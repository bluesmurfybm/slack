<?php
/**
 * 구글 드라이브 · 피그마 링크를 서버가 직접 읽어 온다.
 *
 * ┌──────────────────────────────────────────────────────────────────┐
 * │ 사용자가 준 주소로 바로 나가지 않는다                             │
 * │                                                                  │
 * │ 주소에서 **문서 id 만 뽑아** 정해진 API 주소로 나간다.            │
 * │                                                                  │
 * │   https://docs.google.com/spreadsheets/d/<ID>/edit…              │
 * │        → https://www.googleapis.com/drive/v3/files/<ID>…         │
 * │                                                                  │
 * │ 그래서 http://127.0.0.1:8080/ 이나 사내 주소를 넣어도 서버가      │
 * │ 그리로 나가지 않는다(SSRF). 리디렉션도 따라가지 않는다.           │
 * │                                                                  │
 * │ id 를 못 뽑으면 읽지 않는다. 모르는 주소는 지금까지처럼 'skip'.   │
 * └──────────────────────────────────────────────────────────────────┘
 *
 * 구글 문서는 **엑셀·워드·PPT 로 변환해서** 받는다. 이미 있고 시험을 거친
 * 파서를 그대로 쓰기 위해서다 — 엑셀을 붙여 넣었을 때와 결과가 같아야
 * 사람이 예측할 수 있다.
 *
 * 피그마는 파일이 아니라 화면 구조라 변환할 것이 없다. 문서 트리에서
 * 페이지·프레임 이름과 글자 노드를 **들여쓰기로** 뽑는다 — WBS 도출이
 * 들여쓰기로 대/중/소를 가르므로 그 모양에 맞춘다.
 */

declare(strict_types=1);

require_once __DIR__ . '/Integration.php';
// 글자 수 상한(MAX_CHARS)을 파서와 같은 값으로 쓴다. 여기서 직접 읽지
// 않으면, 파서를 안 싣고 들어오는 경로(api/integration.php 의 '읽어 보기')
// 에서 Class not found 로 터진다.
require_once __DIR__ . '/OfficeDocumentParser.php';

/**
 * 읽어 오지 못했다. 메시지는 사람이 읽을 것이라 그대로 화면에 쓴다.
 *
 * `retryable` 은 **기다리면 될 일인가**를 가른다. 호출 제한(429)이나 상대
 * 서버 오류(5xx)는 설정이 틀린 것이 아니라 지금이 아닐 뿐이다. 그런 것을
 * 실패로 못 박으면 사람이 토큰을 의심하며 헤맨다.
 *
 * LlmError 가 같은 방식으로 나눈다.
 */
class RemoteSourceError extends RuntimeException
{
    public function __construct(string $message, public readonly bool $retryable = false)
    {
        parent::__construct($message);
    }
}

final class RemoteSource
{
    /** 구글 문서를 무엇으로 바꿔 받을지. 왼쪽이 구글 형식, 오른쪽이 우리 kind. */
    private const GOOGLE_EXPORT = [
        'application/vnd.google-apps.spreadsheet' =>
            ['xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        'application/vnd.google-apps.document' =>
            ['docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        'application/vnd.google-apps.presentation' =>
            ['pptx', 'application/vnd.openxmlformats-officedocument.presentationml.presentation'],
    ];

    /** 이미 오피스 파일로 올라가 있는 것은 그대로 받는다. */
    private const BINARY_KIND = [
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'         => 'xlsx',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document'   => 'docx',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
        'application/pdf'                                                           => 'pdf',
    ];

    private Integration $store;

    public function __construct(private PDO $pdo)
    {
        $this->store = new Integration($pdo);
    }

    // =================================================================
    // 주소 읽기 — 네트워크를 타지 않는 순수 함수다. 시험하기 쉽다.
    // =================================================================

    /**
     * 주소에서 어느 연동인지와 문서 id 를 뽑는다.
     *
     * @return array{provider:string, id:string}|null 모르는 주소면 null
     */
    public static function identify(string $url): ?array
    {
        $url  = trim($url);
        $host = strtolower((string)parse_url($url, PHP_URL_HOST));
        if ($host === '') {
            return null;
        }

        if ($host === 'docs.google.com' || $host === 'drive.google.com'
            || $host === 'sheets.google.com') {
            // /spreadsheets/d/<ID>/edit · /document/d/<ID> · /file/d/<ID>/view
            if (preg_match('#/d/([A-Za-z0-9_-]{10,})#', $url, $m)) {
                return ['provider' => Integration::GOOGLE, 'id' => $m[1]];
            }
            // /open?id=<ID> · /uc?id=<ID>
            parse_str((string)parse_url($url, PHP_URL_QUERY), $q);
            if (isset($q['id']) && preg_match('#^[A-Za-z0-9_-]{10,}$#', (string)$q['id'])) {
                return ['provider' => Integration::GOOGLE, 'id' => (string)$q['id']];
            }
            return null;
        }

        if ($host === 'www.figma.com' || $host === 'figma.com') {
            // /file/<KEY>/… · /design/<KEY>/… · /board/<KEY>/… (FigJam)
            if (preg_match('#/(?:file|design|board|proto)/([A-Za-z0-9]{10,})#', $url, $m)) {
                // 주소에 node-id 가 있으면 **그 페이지만** 읽는다. 사람이
                // 특정 페이지를 짚어 붙여 넣었는데 파일 전체를 읽으면,
                // 보라고 한 곳이 수백 줄 속에 묻힌다.
                parse_str((string)parse_url($url, PHP_URL_QUERY), $q);
                $node = isset($q['node-id']) ? trim((string)$q['node-id']) : '';
                // 새 주소는 40006486-417499, 옛 주소는 40006486:417499 다.
                // API 는 콜론 쪽을 확실히 받으므로 맞춰 준다.
                if ($node !== '' && preg_match('#^\d+[-:]\d+$#', $node)) {
                    $node = str_replace('-', ':', $node);
                } else {
                    $node = '';
                }
                return ['provider' => Integration::FIGMA, 'id' => $m[1], 'node' => $node];
            }
            return null;
        }

        return null;
    }

    /** 이 주소를 지금 읽을 수 있는가 — 주소를 알아보고, 연결까지 돼 있는가. */
    public function canFetch(string $url): bool
    {
        $hit = self::identify($url);
        return $hit !== null && $this->store->isReady($hit['provider']);
    }

    // =================================================================
    // 실제로 읽기
    // =================================================================

    /**
     * 링크 하나를 읽어 온다.
     *
     * 파일로 받는 것(구글)은 임시 파일에 떨궈 경로를 돌려준다. 부른 쪽이
     * 기존 파서에 그대로 넘기고 **반드시 지워야 한다**.
     * 글자로 받는 것(피그마)은 text 를 채워 돌려준다.
     *
     * @return array{kind:string, file:?string, text:?string, name:string}
     * @throws RemoteSourceError
     */
    public function fetch(string $url): array
    {
        $hit = self::identify($url);
        if ($hit === null) {
            throw new RemoteSourceError('읽을 수 있는 주소가 아닙니다.');
        }
        $provider = $hit['provider'];
        if (!$this->store->isReady($provider)) {
            throw new RemoteSourceError(
                $provider === Integration::GOOGLE
                    ? '구글 드라이브가 아직 연결되지 않았습니다. 관리자가 설정 화면에서 연결해야 합니다.'
                    : '피그마 토큰이 아직 등록되지 않았습니다. 관리자가 설정 화면에서 넣어야 합니다.'
            );
        }

        try {
            $out = $provider === Integration::GOOGLE
                ? $this->fetchGoogle($hit['id'])
                : $this->fetchFigma($hit['id'], (string)($hit['node'] ?? ''));
            $this->store->markOk($provider);
            return $out;
        } catch (RemoteSourceError $e) {
            // 실패를 남겨 둔다. 링크가 안 읽힐 때 설정 화면에서 이유를 본다.
            $this->store->markError($provider, $e->getMessage());
            throw $e;
        }
    }

    // -----------------------------------------------------------------
    // 구글 드라이브
    // -----------------------------------------------------------------

    private function fetchGoogle(string $fileId): array
    {
        $access = $this->googleAccessToken();
        $hdr    = ['Authorization: Bearer ' . $access];

        // 1) 무엇인지 먼저 묻는다. 형식을 알아야 변환할지 그대로 받을지 정한다.
        //    supportsAllDrives — 공유 드라이브(팀 드라이브)에 있는 문서도 읽는다.
        $metaRaw = $this->http(
            'GET',
            'https://www.googleapis.com/drive/v3/files/' . rawurlencode($fileId)
            . '?fields=id,name,mimeType,size&supportsAllDrives=true',
            $hdr
        );
        $meta = json_decode($metaRaw, true);
        if (!is_array($meta) || !isset($meta['mimeType'])) {
            throw new RemoteSourceError('구글 드라이브가 뜻 모를 응답을 돌려줬습니다.');
        }

        $mime = (string)$meta['mimeType'];
        $name = (string)($meta['name'] ?? $fileId);

        if (isset(self::GOOGLE_EXPORT[$mime])) {
            [$kind, $target] = self::GOOGLE_EXPORT[$mime];
            $url = 'https://www.googleapis.com/drive/v3/files/' . rawurlencode($fileId)
                 . '/export?mimeType=' . rawurlencode($target);
        } elseif (isset(self::BINARY_KIND[$mime])) {
            $kind = self::BINARY_KIND[$mime];
            $url  = 'https://www.googleapis.com/drive/v3/files/' . rawurlencode($fileId)
                  . '?alt=media&supportsAllDrives=true';
            // 크기를 미리 아는 것은 이쪽뿐이다. 변환은 응답을 받아 봐야 안다.
            if (isset($meta['size']) && (int)$meta['size'] > BS_UPLOAD_MAX_BYTES) {
                throw new RemoteSourceError(sprintf(
                    '파일이 너무 큽니다 (%s). %dMB 까지 읽습니다.',
                    $name, (int)(BS_UPLOAD_MAX_BYTES / 1024 / 1024)
                ));
            }
        } else {
            throw new RemoteSourceError(
                "글자를 뽑을 수 없는 형식입니다: $mime"
                . ' (엑셀·워드·PPT·PDF 와 구글 시트·문서·프레젠테이션만 읽습니다)'
            );
        }

        $bytes = $this->http('GET', $url, $hdr);
        if (strlen($bytes) > BS_UPLOAD_MAX_BYTES) {
            throw new RemoteSourceError(sprintf(
                '내려받은 내용이 너무 큽니다. %dMB 까지 읽습니다.',
                (int)(BS_UPLOAD_MAX_BYTES / 1024 / 1024)
            ));
        }

        return ['kind' => $kind, 'file' => $this->spill($bytes, $kind), 'text' => null, 'name' => $name];
    }

    /**
     * 갱신 토큰으로 접근 토큰을 받는다.
     *
     * 접근 토큰은 한 시간짜리라 보관하지 않는다 — 요청마다 새로 받는다.
     * 한 번 읽는 데 왕복이 하나 더 늘지만, 만료 시각을 들고 다니며 맞추는
     * 쪽보다 틀릴 자리가 적다. 문서 분석은 사람이 단추를 눌러 도는 일이라
     * 초당 수십 번 일어나지 않는다.
     */
    private function googleAccessToken(): string
    {
        $raw = $this->http(
            'POST',
            'https://oauth2.googleapis.com/token',
            ['Content-Type: application/x-www-form-urlencoded'],
            http_build_query([
                'client_id'     => $this->store->clientId(Integration::GOOGLE),
                'client_secret' => $this->store->secret(Integration::GOOGLE),
                'refresh_token' => $this->store->token(Integration::GOOGLE),
                'grant_type'    => 'refresh_token',
            ])
        );
        $j = json_decode($raw, true);
        if (!is_array($j) || empty($j['access_token'])) {
            // invalid_grant = 동의가 취소됐거나 토큰이 만료됐다. 다시 연결해야 한다.
            $why = is_array($j) && isset($j['error']) ? (string)$j['error'] : '알 수 없음';
            throw new RemoteSourceError(
                $why === 'invalid_grant'
                    ? '구글 연결이 끊겼습니다(동의 취소 또는 만료). 설정 화면에서 다시 연결하세요.'
                    : "구글 접근 토큰을 받지 못했습니다: $why"
            );
        }
        return (string)$j['access_token'];
    }

    // -----------------------------------------------------------------
    // 피그마
    // -----------------------------------------------------------------

    /**
     * 피그마 파일의 구조를 글자로 편다.
     *
     * 화면 설계는 보통 페이지 > 프레임 > 요소로 쌓이고, 그 이름이 곧 메뉴
     * 구조인 경우가 많다. 그래서 **이름을 들여쓰기로** 적는다 — WBS 도출이
     * 들여쓰기를 보고 대/중/소를 가르므로 바로 쓸 수 있다.
     */
    private function fetchFigma(string $fileKey, string $nodeId = ''): array
    {
        // ┌──────────────────────────────────────────────────────────┐
        // │ node-id 가 있어도 **파일 전체**를 읽는다                   │
        // │                                                          │
        // │ 한때는 node-id 가 있으면 그 가지만 읽었다. "사람이 특정   │
        // │ 페이지를 짚었다" 고 봤는데 틀렸다 — 피그마의 '링크 복사'는│
        // │ 무엇을 고르고 있든 **언제나** node-id 를 붙인다. 그래서   │
        // │ 그냥 복사해 붙여 넣은 주소가 프레임 하나로 좁혀졌고,      │
        // │ 실제로 17자만 읽힌 적이 있다.                             │
        // │                                                          │
        // │ 전체를 읽어도 잃는 것이 없다. 짚은 가지는 그 안에 들어    │
        // │ 있고, 글자 수는 MAX_CHARS 에서 자른다.                    │
        // └──────────────────────────────────────────────────────────┘
        unset($nodeId);

        // 깊이를 건다. 한때 아예 없앴더니 응답이 커져 **호출 제한(429)** 에
        // 걸렸다. 피그마의 제한은 돌려주는 노드 수에 비례한다.
        //
        // 6단계면 페이지 > 섹션 > 프레임 > 요소 > 글자까지 닿는다. 예전의
        // 4단계로는 바깥 이름만 긁혔다. 더 깊이 있는 글자는 놓치지만,
        // 아예 못 읽는 것보다 낫다.
        $url = 'https://api.figma.com/v1/files/' . rawurlencode($fileKey) . '?depth=6';

        // **깊이를 걸지 않는다.** 전에는 depth=4 로 잘랐는데, 실제 글자는
        // 프레임 안쪽 깊은 곳에 있어 이름만 긁고 내용을 통째로 놓쳤다.
        $raw = $this->http('GET', $url, ['X-Figma-Token: ' . $this->store->secret(Integration::FIGMA)]);
        $j   = json_decode($raw, true);
        if (!is_array($j)) {
            throw new RemoteSourceError('피그마가 뜻 모를 응답을 돌려줬습니다.');
        }

        $name = (string)($j['name'] ?? $fileKey);
        if (!isset($j['document'])) {
            throw new RemoteSourceError('피그마 응답에 문서가 없습니다.');
        }

        $lines = [];
        $this->walkFigma($j['document'], 0, $lines);

        if ($lines === []) {
            throw new RemoteSourceError(
                '읽을 글자가 없습니다. 화면이 전부 이미지(캡처)로 되어 있으면 '
                . '피그마에도 글자가 없어 뽑을 것이 없습니다.'
            );
        }
        // 이름이 Frame 12 · IMG_5203 뿐인 파일은 읽어도 쓸 것이 없다.
        // 성공으로 돌려주면 "읽었는데 왜 WBS 가 안 나오지" 가 된다.
        if (count($lines) < 3) {
            throw new RemoteSourceError(sprintf(
                '읽을 만한 글자가 %d줄뿐입니다. 화면이 캡처 이미지이거나 '
                . '레이어 이름이 자동 생성 이름(Frame 12 · IMG_0001)뿐일 때 그렇습니다.',
                count($lines)
            ));
        }
        $text = implode("
", $lines);
        if (mb_strlen($text) > OfficeDocumentParser::MAX_CHARS) {
            $text = mb_substr($text, 0, OfficeDocumentParser::MAX_CHARS);
        }
        return ['kind' => 'figma', 'file' => null, 'text' => $text, 'name' => $name];
    }

    /**
     * 피그마가 자동으로 붙이는 이름. WBS 에 들어가면 쓰레기가 된다.
     *
     * `Frame 12` `Group 5` `Rectangle` `IMG_5203` 같은 것들이다. 사람이
     * 지은 이름(`출석부 - 목록`)만 남겨야 태스크로 쓸 수 있다.
     */
    private static function isNoiseName(string $name): bool
    {
        return (bool)preg_match(
            '#^(frame|group|rectangle|ellipse|vector|line|polygon|star|slice|'
            . 'image|img|component|instance|union|subtract|mask|arrow|shape)'
            . '[\s_-]*\d*$#iu',
            $name
        );
    }

    /**
     * 트리를 훑어 글자를 들여쓰기로 쌓는다.
     *
     * 글자 노드는 **실제 글자**를, 그 밖의 노드는 **사람이 지은 이름**을 쓴다.
     * 자동 생성 이름은 버린다 — 남겨 두면 WBS 초안이 `Frame 12` 로 가득 찬다.
     */
    private function walkFigma(array $node, int $depth, array &$lines): void
    {
        $type = (string)($node['type'] ?? '');
        $name = trim((string)($node['name'] ?? ''));

        if ($depth > 0) {
            $body = '';
            if ($type === 'TEXT' && isset($node['characters'])) {
                $body = trim((string)$node['characters']);
            } elseif ($name !== '' && !self::isNoiseName($name)) {
                $body = $name;
            }
            if ($body !== '') {
                // 설계 메모가 통째로 들어오는 수가 있어 잘라 둔다.
                $body = mb_substr(preg_replace('/\s+/u', ' ', $body) ?? '', 0, 200);
                $lines[] = str_repeat('  ', max(0, $depth - 1)) . $body;
            }
        }

        // 깊이는 넉넉히 두되 끝은 있다. 피그마 문서는 중첩이 깊은 편이라
        // 얕게 끊으면 정작 글자가 안 나온다 — 전에 그래서 이름만 긁었다.
        if ($depth >= 20) {
            return;
        }
        foreach (($node['children'] ?? []) as $kid) {
            if (is_array($kid)) {
                $this->walkFigma($kid, $depth + 1, $lines);
            }
        }
    }

    // -----------------------------------------------------------------
    // 바깥으로 나가는 유일한 자리
    // -----------------------------------------------------------------

    /**
     * @param list<string> $headers
     * @throws RemoteSourceError
     */
    private function http(string $method, string $url, array $headers = [], ?string $body = null): string
    {
        if (!function_exists('curl_init')) {
            throw new RemoteSourceError('서버에 curl 확장이 없습니다.');
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            // 따라가지 않는다. 이 API 들은 리디렉션을 쓰지 않고,
            // 따라가게 두면 사내 주소로 끌려갈 길이 생긴다.
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $res  = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($res === false) {
            // 인증서 검증은 **끄지 않는다.** 끄면 중간에서 가로채도 모른다.
            // 대신 무엇이 문제인지 또렷이 적는다 — 윈도우 PHP 는 CA 묶음이
            // 없어 이 오류가 나고, php.ini 에 curl.cainfo 를 지정하면 된다.
            if (stripos($err, 'certificate') !== false || stripos($err, 'SSL') !== false) {
                throw new RemoteSourceError(
                    '서버가 상대 인증서를 확인하지 못했습니다. PHP 에 CA 인증서 묶음이 '
                    . '지정돼 있는지 보세요(php.ini 의 curl.cainfo). 원문: ' . $err
                );
            }
            throw new RemoteSourceError('바깥으로 나가지 못했습니다: ' . $err);
        }
        if ($code >= 400) {
            // 429(호출 제한)와 5xx(상대 서버)는 기다리면 될 일이다.
            throw new RemoteSourceError($this->explain($code, (string)$res),
                                        retryable: $code === 429 || $code >= 500);
        }
        return (string)$res;
    }

    /** 남의 오류 응답을 사람이 읽을 말로 바꾼다. 그대로 보여 주면 아무도 못 읽는다. */
    private function explain(int $code, string $body): string
    {
        $j   = json_decode($body, true);
        $msg = is_array($j) ? (string)($j['error']['message'] ?? $j['err'] ?? $j['message'] ?? '') : '';

        return match (true) {
            $code === 401 => '자격 정보가 거부됐습니다(401). 설정 화면에서 다시 연결하세요.',
            $code === 403 => '권한이 없습니다(403). 그 문서를 연결된 계정이 볼 수 있는지 확인하세요.'
                             . ($msg !== '' ? " — $msg" : ''),
            $code === 404 => '그 문서를 찾지 못했습니다(404). 주소가 맞는지, 지워지지 않았는지 보세요.',
            $code === 429 => '피그마 호출 제한에 걸렸습니다(429). 잠시 뒤에 저절로 다시 시도합니다.',
            $code >= 500  => "상대 서버 쪽 오류입니다($code). 잠시 뒤에 다시 하세요.",
            default       => "읽지 못했습니다($code)" . ($msg !== '' ? " — $msg" : ''),
        };
    }

    /** 내려받은 것을 임시 파일로 떨군다. 부른 쪽이 지운다. */
    private function spill(string $bytes, string $kind): string
    {
        $path = tempnam(sys_get_temp_dir(), 'bs_remote_');
        if ($path === false) {
            throw new RemoteSourceError('임시 파일을 만들지 못했습니다.');
        }
        // 파서가 확장자를 보지는 않지만, 남아 있을 때 무엇인지 알아보게 붙인다.
        $withExt = $path . '.' . $kind;
        if (!@rename($path, $withExt)) {
            $withExt = $path;
        }
        if (file_put_contents($withExt, $bytes) === false) {
            @unlink($withExt);
            throw new RemoteSourceError('내려받은 내용을 저장하지 못했습니다.');
        }
        return $withExt;
    }
}

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

    /**
     * 이 주소를 읽을 수 있는 **자격**이 되는가.
     *
     * 일부러 enabled·cooldown 을 보지 않는다. 이 값은 링크를 'pending' 으로
     * 받을지 'skip' 으로 받을지 가르는 데 쓰는데, 관리자가 잠깐 꺼 두었다고
     * 'skip' 으로 박아 두면 다시 켰을 때 그 링크들이 영영 안 읽힌다.
     * "지금 불러도 되는가" 는 Integration::blockedReason() 이 답한다.
     */
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

        // ┌──────────────────────────────────────────────────────────┐
        // │ 부르기 전에 묻는다                                        │
        // │                                                          │
        // │ 연결이 안 됐거나, 관리자가 껐거나, 상대가 "쉬어라" 했거나, │
        // │ 오늘 한도를 다 썼으면 **호출을 만들지조차 않는다.**        │
        // │                                                          │
        // │ 셋 다 '기다리면 될 일'(retryable)이다. 실패로 못 박으면    │
        // │ 나중에 사람이 전부 손으로 되돌려야 한다. 단, 연결 자체가   │
        // │ 안 된 것은 기다려도 안 풀리므로 실패로 둔다.               │
        // └──────────────────────────────────────────────────────────┘
        $blocked = $this->store->blockedReason($provider);
        if ($blocked !== null) {
            throw new RemoteSourceError($blocked, retryable: $this->store->isReady($provider));
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
            $hdr, null, Integration::GOOGLE, 'drive_meta'
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

        $bytes = $this->http('GET', $url, $hdr, null, Integration::GOOGLE, 'drive_read');
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
            ]),
            Integration::GOOGLE, 'google_token'
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
    /**
     * 노드 여러 개를 **한 번에** 받는다.
     *
     * ┌──────────────────────────────────────────────────────────────┐
     * │ 이것이 이 기능이 성립하는 유일한 길이다                        │
     * │                                                              │
     * │ 링크 하나에 호출 하나면, IA 시트 117건은 호출 117번이다.      │
     * │ 피그마의 제한은 분당 호출 수가 아니라 **며칠 단위 비용        │
     * │ 예산**이라(x-figma-rate-limit-type), 117번을 한 번 돌리면     │
     * │ 예산이 바닥난다. 실제로 2026-10-04 에 그렇게 2일 14시간짜리   │
     * │ 정지를 받았다.                                                │
     * │                                                              │
     * │ /nodes?ids=a,b,c,… 는 id 를 쉼표로 여러 개 받는다. 117건이    │
     * │ 호출 3번이 된다. 응답 크기도 필요한 가지만큼이라 파일 전체를   │
     * │ 받는 것보다 싸다.                                             │
     * └──────────────────────────────────────────────────────────────┘
     *
     * 못 찾은 id 는 **조용히 빠진다.** 부른 쪽이 "돌려받지 못한 id" 를 보고
     * 그 건만 실패로 적는다 — 하나가 없다고 묶음 전체를 버리면 안 된다.
     *
     * @param  list<string> $nodeIds
     * @return array{file:string, nodes:array<string, array{name:string, text:string}>}
     * @throws RemoteSourceError
     */
    public function figmaNodes(string $fileKey, array $nodeIds): array
    {
        $this->guard(Integration::FIGMA);

        $ids = array_values(array_unique(array_filter($nodeIds, static fn($v) => trim((string)$v) !== '')));
        if ($ids === []) {
            return ['file' => $fileKey, 'nodes' => []];
        }

        $raw = $this->http(
            'GET',
            'https://api.figma.com/v1/files/' . rawurlencode($fileKey)
            . '/nodes?ids=' . rawurlencode(implode(',', $ids)),
            ['X-Figma-Token: ' . $this->store->secret(Integration::FIGMA)],
            null,
            Integration::FIGMA,
            'figma_nodes',
            count($ids)
        );
        $j = json_decode($raw, true);
        if (!is_array($j)) {
            throw new RemoteSourceError('피그마가 뜻 모를 응답을 돌려줬습니다.', retryable: true);
        }

        $out = [];
        foreach (($j['nodes'] ?? []) as $id => $n) {
            // 값이 null 인 id 가 섞여 온다 = 그 노드가 그 파일에 없다.
            if (!is_array($n) || !isset($n['document']) || !is_array($n['document'])) {
                continue;
            }
            $doc  = $n['document'];
            $text = $this->renderFigma($doc, 1);
            if ($text === '') {
                continue;
            }
            $out[(string)$id] = [
                // 가지의 이름이 곧 그 화면의 이름이다. 파일명보다 쓸모 있다.
                'name' => trim((string)($doc['name'] ?? '')) !== ''
                          ? (string)$doc['name'] : (string)($j['name'] ?? $fileKey),
                'text' => $text,
            ];
        }

        $this->store->markOk(Integration::FIGMA);
        return ['file' => (string)($j['name'] ?? $fileKey), 'nodes' => $out];
    }

    private function fetchFigma(string $fileKey, string $nodeId = ''): array
    {
        // ┌──────────────────────────────────────────────────────────┐
        // │ node-id 가 있으면 **그 가지만** 읽는다                     │
        // │                                                          │
        // │ IA 시트는 항목마다 그 항목의 기획 화면을 node-id 로 가리  │
        // │ 킨다. 한 파일 안의 서로 다른 지점이다. 무시하고 파일       │
        // │ 전체를 읽으면                                             │
        // │                                                          │
        // │   · 같은 파일을 항목 수만큼 반복해 받는다 → 429           │
        // │   · 결과가 전부 똑같아 항목별 판정에 쓸 수 없다            │
        // │                                                          │
        // │ 한때 '링크 복사가 늘 node-id 를 붙이니 우연일 것' 이라     │
        // │ 보고 무시했는데, IA 시트에서는 **뜻을 갖고 건 값**이었다.  │
        // │ 혼자 붙여 넣은 주소 하나가 얇게 읽히는 쪽을 감수한다 —     │
        // │ 그쪽은 사람이 보고 알 수 있지만, 수십 건이 같은 글을       │
        // │ 돌려주는 것은 알아채기 어렵다.                             │
        // └──────────────────────────────────────────────────────────┘
        // 가지 하나는 묶어 받기와 같은 길로 간다. 길이 둘이면 한쪽만 고치는
        // 일이 생긴다 — 실제로 node-id 처리를 한 번 되돌렸다 다시 넣었다.
        if ($nodeId !== '') {
            $got = $this->figmaNodes($fileKey, [$nodeId]);
            $one = $got['nodes'][$nodeId] ?? null;
            if ($one === null) {
                throw new RemoteSourceError(
                    '주소가 가리키는 화면을 찾지 못했습니다. 지워졌거나, 다른 가지(브랜치)의 '
                    . '주소거나, 연결된 계정이 그 파일을 볼 수 없을 수 있습니다.'
                );
            }
            return ['kind' => 'figma', 'file' => null,
                    'text' => $one['text'], 'name' => $one['name']];
        }

        // 파일 전체는 깊이를 건다. 제한 없이 받으면 429 에 걸린다 —
        // 피그마의 제한은 돌려주는 노드 수에 비례한다.
        $this->guard(Integration::FIGMA);
        $raw = $this->http(
            'GET',
            'https://api.figma.com/v1/files/' . rawurlencode($fileKey) . '?depth=6',
            ['X-Figma-Token: ' . $this->store->secret(Integration::FIGMA)],
            null, Integration::FIGMA, 'figma_file', 1
        );
        $j = json_decode($raw, true);
        if (!is_array($j) || !isset($j['document']) || !is_array($j['document'])) {
            throw new RemoteSourceError('피그마 응답에 문서가 없습니다.', retryable: true);
        }

        $text = $this->renderFigma($j['document'], 0);
        if ($text === '') {
            throw new RemoteSourceError(
                '읽을 글자가 없습니다. 화면이 전부 이미지(캡처)로 되어 있으면 '
                . '피그마에도 글자가 없어 뽑을 것이 없습니다.'
            );
        }
        return ['kind' => 'figma', 'file' => null, 'text' => $text,
                'name' => (string)($j['name'] ?? $fileKey)];
    }

    /**
     * 노드 하나를 글자로 편다. 글자가 하나도 없으면 빈 문자열.
     *
     * 가지 하나를 읽을 때는 depth 1 로 시작해 **그 가지의 이름부터** 적는다.
     * 그것이 화면 이름이라 가장 쓸모 있는 한 줄이다.
     */
    private function renderFigma(array $root, int $startDepth): string
    {
        $lines = [];
        $this->walkFigma($root, $startDepth, $lines);
        if ($lines === []) {
            return '';
        }
        $text = implode("\n", $lines);
        return mb_strlen($text) > OfficeDocumentParser::MAX_CHARS
             ? mb_substr($text, 0, OfficeDocumentParser::MAX_CHARS)
             : $text;
    }

    /** 지금 이 연동을 부를 수 있는가. 못 부르면 그 이유를 그대로 던진다. */
    private function guard(string $provider): void
    {
        $blocked = $this->store->blockedReason($provider);
        if ($blocked !== null) {
            throw new RemoteSourceError($blocked, retryable: $this->store->isReady($provider));
        }
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
     * 바깥으로 나가는 유일한 자리. **나간 호출은 한 줄도 빠짐없이 기록한다.**
     *
     * `$provider` 를 주면 두 가지를 더 한다.
     *   · bs_api_usage 에 기록한다 — 성공·실패 가리지 않고. 실패도 상대의
     *     예산을 쓴다.
     *   · 429 를 받으면 **Retry-After 를 읽어 쉬는 시각을 박아 둔다.**
     *
     * @param list<string> $headers
     * @throws RemoteSourceError
     */
    private function http(string $method, string $url, array $headers = [], ?string $body = null,
                          string $provider = '', string $act = '', int $items = 1): string
    {
        if (!function_exists('curl_init')) {
            throw new RemoteSourceError('서버에 curl 확장이 없습니다.');
        }

        $began = microtime(true);
        $resp  = [];                      // 응답 헤더. Retry-After 를 읽는다

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
            CURLOPT_HEADERFUNCTION => static function ($c, $line) use (&$resp) {
                $p = strpos($line, ':');
                if ($p > 0) {
                    $resp[strtolower(trim(substr($line, 0, $p)))] = trim(substr($line, $p + 1));
                }
                return strlen($line);
            },
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $res  = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        $ms  = (int)round((microtime(true) - $began) * 1000);
        $log = function (bool $ok, string $note) use ($provider, $act, $code, $ms, $items): void {
            if ($provider !== '') {
                $this->store->logCall($provider, $act, $ok, $code, $ms, $items, $note);
            }
        };

        if ($res === false) {
            $log(false, mb_substr($err, 0, 200));
            // 인증서 검증은 **끄지 않는다.** 끄면 중간에서 가로채도 모른다.
            // 대신 무엇이 문제인지 또렷이 적는다 — 윈도우 PHP 는 CA 묶음이
            // 없어 이 오류가 나고, php.ini 에 curl.cainfo 를 지정하면 된다.
            if (stripos($err, 'certificate') !== false || stripos($err, 'SSL') !== false) {
                throw new RemoteSourceError(
                    '서버가 상대 인증서를 확인하지 못했습니다. PHP 에 CA 인증서 묶음이 '
                    . '지정돼 있는지 보세요(php.ini 의 curl.cainfo). 원문: ' . $err
                );
            }
            throw new RemoteSourceError('바깥으로 나가지 못했습니다: ' . $err, retryable: true);
        }

        if ($code === 429 && $provider !== '') {
            // ┌──────────────────────────────────────────────────────┐
            // │ 상대가 말해 준 시간을 그대로 지킨다                    │
            // │                                                      │
            // │ 피그마는 `retry-after: 224862`(2일 14시간) 를 보낸다. │
            // │ 이 헤더를 안 읽고 우리 마음대로 5분 뒤에 다시 부르면  │
            // │ 또 맞고, 맞을수록 벌칙이 길어진다. 실제로 그래서       │
            // │ 하룻밤에 며칠치 예산을 태웠다.                         │
            // │                                                      │
            // │ 헤더가 없으면 **길게 잡는다**(1시간). 짧게 잡아 또    │
            // │ 맞는 쪽이 늦게 푸는 쪽보다 훨씬 비싸다.               │
            // └──────────────────────────────────────────────────────┘
            $after = $this->retryAfterSeconds($resp);
            $this->store->startCooldown($provider, $after, sprintf(
                '%s 가 %s 뒤에 다시 오라고 했습니다.',
                $provider === Integration::FIGMA ? '피그마' : '상대 서버',
                Integration::humanSpan($after)
            ));
            $log(false, 'rate limited, retry-after=' . $after);
            throw new RemoteSourceError(sprintf(
                '%s 호출 제한에 걸렸습니다(429). %s 동안 쉬었다가 저절로 다시 시도합니다.',
                $provider === Integration::FIGMA ? '피그마' : '상대 서버',
                Integration::humanSpan($after)
            ), retryable: true);
        }

        if ($code >= 400) {
            $log(false, 'HTTP ' . $code);
            // 5xx(상대 서버)는 기다리면 될 일이다.
            throw new RemoteSourceError($this->explain($code, (string)$res),
                                        retryable: $code >= 500);
        }

        $log(true, '');
        return (string)$res;
    }

    /**
     * 429 응답에서 "몇 초 쉬어라" 를 읽는다.
     *
     * Retry-After 는 초일 수도, HTTP 날짜일 수도 있다(RFC 9110). 둘 다 받는다.
     * 못 읽으면 1시간 — 모를 때는 길게 쉬는 쪽이 싸다.
     *
     * @param array<string,string> $headers 소문자 키
     */
    private function retryAfterSeconds(array $headers): int
    {
        $v = trim($headers['retry-after'] ?? $headers['x-ratelimit-reset'] ?? '');
        if ($v === '') {
            return 3600;
        }
        if (ctype_digit($v)) {
            return max(60, (int)$v);
        }
        $ts = strtotime($v);
        return $ts === false ? 3600 : max(60, $ts - time());
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

<?php
/**
 * 출처 문서에서 링크를 찾아 따라 읽는다.
 *
 * ┌──────────────────────────────────────────────────────────────────┐
 * │ 한 단계만 따라간다                                                │
 * │                                                                  │
 * │ IA 시트 → 거기 걸린 기획 화면. 거기서 끝이다.                     │
 * │                                                                  │
 * │ 읽어 온 글 안의 링크를 또 따라가면 끝이 없다. 기획서가 다른        │
 * │ 기획서를 참조하고, 그것이 사내 위키를 가리키고, 위키가 외부        │
 * │ 문서를 가리킨다. 어디서 멈출지 정할 방법이 없고, 한 번 돌릴 때마다 │
 * │ 상대 서비스를 몇 백 번 부르게 된다.                               │
 * └──────────────────────────────────────────────────────────────────┘
 *
 * 찾기(scan)와 읽기(fetch)를 나눈다. 찾기는 글자만 보면 되니 즉시 끝나고,
 * 읽기는 바깥을 타므로 크론 워커가 하나씩 집어 간다.
 */

declare(strict_types=1);

require_once __DIR__ . '/RemoteSource.php';

final class LinkAnalyzer
{
    /** 한 프로젝트에서 한 번에 다룰 링크 수. 넘으면 앞에서부터 자른다. */
    public const MAX_LINKS = 300;

    /**
     * 되시도까지 쉬는 시간(분).
     *
     * 호출 제한(429)에 걸렸을 때 곧바로 다시 부르면 또 걸린다. 그 사이에는
     * 다른 링크를 읽는다 — fetched_at 을 찍어 두고 그보다 오래된 것만 집는다.
     * 칸을 새로 만들지 않고 이미 있는 칸으로 뒤로 미루는 방법이다.
     */
    public const RETRY_AFTER_MINUTES = 5;

    private RemoteSource $remote;

    public function __construct(private PDO $pdo)
    {
        $this->remote = new RemoteSource($pdo);
    }

    // =================================================================
    // 찾기 — 바깥을 타지 않는다
    // =================================================================

    /**
     * 프로젝트의 출처 문서 글자에서 링크를 찾아 담는다.
     *
     * 엑셀 파서가 셀 링크를 `글자 <주소>` 로 붙여 두므로, 줄 단위로 훑어
     * 주소와 **그 줄 전체**를 함께 담는다. 줄 전체가 "어느 항목의 링크인가"
     * 를 말해 준다 — 그게 없으면 읽어 온 글이 어느 태스크 것인지 모른다.
     *
     * 이미 담긴 주소는 건드리지 않는다. 다시 찾아도 읽어 둔 내용이 날아가지
     * 않아야 한다.
     *
     * @return array{found:int, added:int, skipped:int}
     */
    public function scan(int $projectId): array
    {
        $st = $this->pdo->prepare(
            'SELECT id, title, parsed_text FROM bs_project_source
              WHERE project_id = ? AND parsed_text IS NOT NULL AND parsed_text <> ""'
        );
        $st->execute([$projectId]);

        $seen  = [];
        $found = $added = $skipped = 0;

        $ins = $this->pdo->prepare(
            'INSERT INTO bs_source_link (project_id, source_id, url, provider, context, status)
                  VALUES (?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE
                  -- 문맥만 갱신한다. 읽어 둔 내용과 상태는 건드리지 않는다.
                  context = VALUES(context)'
        );

        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $src) {
            foreach (explode("\n", (string)$src['parsed_text']) as $line) {
                foreach ($this->urlsIn($line) as $url) {
                    if (isset($seen[$url])) {
                        continue;
                    }
                    $seen[$url] = true;
                    $found++;
                    if (count($seen) > self::MAX_LINKS) {
                        $skipped++;
                        continue;
                    }

                    $hit      = RemoteSource::identify($url);
                    $provider = $hit['provider'] ?? 'other';
                    // 읽을 수 없는 서비스(노션·사내 위키)는 담되 'skip' 으로
                    // 둔다. 찾았다는 사실 자체가 정보다 — 사람이 보고 판단한다.
                    $status   = $hit === null ? 'skip' : 'pending';

                    $ins->execute([
                        $projectId, (int)$src['id'], mb_substr($url, 0, 500), $provider,
                        mb_substr(trim(preg_replace('/\s+/u', ' ', $line) ?? ''), 0, 500),
                        $status,
                    ]);
                    if ($ins->rowCount() > 0) {
                        $added++;
                    }
                }
            }
        }

        return ['found' => $found, 'added' => $added, 'skipped' => $skipped];
    }

    /**
     * 한 줄에서 주소를 뽑는다.
     *
     * 파서가 붙인 `<주소>` 모양을 먼저 보고, 없으면 맨 주소를 찾는다.
     * 사람이 셀에 주소를 그대로 적어 두는 일도 흔하다.
     *
     * @return list<string>
     */
    private function urlsIn(string $line): array
    {
        $out = [];
        if (preg_match_all('#<(https?://[^>\s]+)>#i', $line, $m)) {
            $out = $m[1];
        }
        if (preg_match_all('#(?<![<\w])(https?://[^\s<>"\']+)#i', $line, $m2)) {
            foreach ($m2[1] as $u) {
                $out[] = rtrim($u, '.,;)]');      // 문장 끝 기호는 주소가 아니다
            }
        }
        return array_values(array_unique($out));
    }

    // =================================================================
    // 읽기 — 바깥을 탄다. 워커가 하나씩 부른다
    // =================================================================

    /** 아직 안 읽은 링크 수. 큐에 넣기 전에 할 일이 있는지 본다. */
    public function pendingCount(int $projectId): int
    {
        $st = $this->pdo->prepare(
            'SELECT COUNT(*) FROM bs_source_link WHERE project_id = ? AND status = "pending"'
        );
        $st->execute([$projectId]);
        return (int)$st->fetchColumn();
    }

    /**
     * 다음에 읽을 링크 하나. 없으면 null.
     *
     * 방금 호출 제한에 걸린 것은 건너뛴다. **대기(pending)인데 돌려줄 것이
     * 없는 상태**가 생길 수 있는데, 그때는 워커가 작업을 큐로 되돌려
     * 다음 회차에 이어 간다.
     */
    public function nextPending(int $projectId): ?array
    {
        $st = $this->pdo->prepare(
            'SELECT * FROM bs_source_link
              WHERE project_id = ? AND status = "pending"
                AND (fetched_at IS NULL OR fetched_at < DATE_SUB(NOW(), INTERVAL ? MINUTE))
              ORDER BY id LIMIT 1'
        );
        $st->execute([$projectId, self::RETRY_AFTER_MINUTES]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r === false ? null : $r;
    }

    /**
     * 링크 하나를 읽어 담는다.
     *
     * **예외를 밖으로 던지지 않는다.** 하나가 실패했다고 나머지가 멈추면
     * 안 된다. 실패는 status='fail' 과 error 로 남는다.
     *
     * 세 갈래로 돌려준다. 'retry' 는 **아직 안 끝난 것**이라 진행률을
     * 올리면 안 된다 — 올리면 되시도할 때마다 두 번 세어 done 이 total 을
     * 넘는다.
     *
     * @return 'ok'|'fail'|'retry'
     */
    public function fetchOne(array $link): string
    {
        $id  = (int)$link['id'];
        $url = (string)$link['url'];
        $tmp = null;

        try {
            $got  = $this->remote->fetch($url);
            $tmp  = $got['file'];
            $text = $got['text'];

            if ($text === null) {
                // 구글은 파일로 온다. 기존 파서로 글자를 뽑는다.
                require_once __DIR__ . '/OfficeDocumentParser.php';
                $parser = new OfficeDocumentParser(
                    defined('BS_PDFTOTEXT') && BS_PDFTOTEXT ? BS_PDFTOTEXT : null
                );
                $text = $parser->parse((string)$tmp, $got['kind'])->text();
            }
            if (mb_strlen($text) > OfficeDocumentParser::MAX_CHARS) {
                $text = mb_substr($text, 0, OfficeDocumentParser::MAX_CHARS);
            }

            $this->save($id, 'ok', mb_substr((string)$got['name'], 0, 200), $text, null);
            return 'ok';

        } catch (Throwable $e) {
            $msg = $e instanceof RemoteSourceError || $e instanceof DocumentParseError
                 ? $e->getMessage()
                 : '링크를 읽는 중 오류가 발생했습니다.';
            if (!($e instanceof RemoteSourceError) && !($e instanceof DocumentParseError)) {
                error_log('[BlueStudio] fetchOne#' . $id . ': ' . $e);
            }

            // 기다리면 될 일(호출 제한·상대 서버 오류)은 **실패로 못 박지
            // 않는다.** 대기로 두고 사유만 적어 둔다 — 사람이 토큰을
            // 의심하며 헤매지 않게. fetched_at 을 찍어 두면 그만큼 쉰다.
            $retry = $e instanceof RemoteSourceError && $e->retryable;
            $this->save($id, $retry ? 'pending' : 'fail', null, null, $msg);
            return $retry ? 'retry' : 'fail';

        } finally {
            // 임시 파일은 어떤 길로 끝나든 지운다. 서버에 사업 문서가 쌓이면 안 된다.
            if ($tmp !== null && is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }

    private function save(int $id, string $status, ?string $title, ?string $text, ?string $err): void
    {
        $this->pdo->prepare(
            'UPDATE bs_source_link
                SET status = ?, title = ?, parsed_text = ?, error = ?, fetched_at = NOW()
              WHERE id = ?'
        )->execute([$status, $title, $text, $err === null ? null : mb_substr($err, 0, 300), $id]);
    }

    /** 실패한 것을 다시 읽을 수 있게 되돌린다. 설정을 고친 뒤 쓴다. */
    public function retryFailed(int $projectId): int
    {
        $st = $this->pdo->prepare(
            'UPDATE bs_source_link SET status = "pending", error = NULL
              WHERE project_id = ? AND status = "fail"'
        );
        $st->execute([$projectId]);
        return $st->rowCount();
    }

    /** 화면에 보여 줄 목록. parsed_text 는 길어서 빼고 길이만 준다. */
    public function links(int $projectId): array
    {
        $st = $this->pdo->prepare(
            'SELECT id, source_id, url, provider, context, status, title, error,
                    CHAR_LENGTH(COALESCE(parsed_text, "")) AS chars, fetched_at
               FROM bs_source_link WHERE project_id = ? ORDER BY id'
        );
        $st->execute([$projectId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
}

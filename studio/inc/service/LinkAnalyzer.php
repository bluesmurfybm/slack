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
    /**
     * 한 프로젝트에서 **읽을 수 있는** 링크를 몇 개까지 담을지.
     *
     * ┌──────────────────────────────────────────────────────────────┐
     * │ 읽을 수 없는 주소는 이 수에 안 넣는다                          │
     * │                                                              │
     * │ 전에는 모든 주소를 한데 세어 300 에서 끊었다. 실제 IA 시트를  │
     * │ 돌려 보니 300 중 183 이 `https://csms45.moodler.kr/` 같은     │
     * │ **사이트 주소**였다. 읽을 수 없는 주소가 상한의 61% 를 먹고,  │
     * │ 정작 읽어야 할 피그마 링크가 잘려 나갔다.                     │
     * │                                                              │
     * │ 그래서 두 수를 나눈다. 읽을 수 있는 것만 MAX_LINKS 로 세고,   │
     * │ 못 읽는 것은 기록용이라 따로 넉넉히 받는다.                    │
     * └──────────────────────────────────────────────────────────────┘
     */
    public const MAX_LINKS = 300;

    /** 읽을 수 없는 주소(노션·사내 위키·사이트)를 몇 개까지 기록해 둘지. */
    public const MAX_SKIP_LINKS = 500;

    /**
     * 피그마에 한 번에 몇 개를 묶어 물을지.
     *
     * 피그마의 비용은 **돌려주는 노드 수**에 비례하므로 무한정 키울 수 없다.
     * 40개면 링크 117건이 호출 3번이 된다 — 117번과 3번의 차이가 이 기능이
     * 되느냐 마느냐를 가른다.
     */
    public const FIGMA_BATCH = 40;

    /**
     * 되시도까지 쉬는 시간(분).
     *
     * 호출 제한(429)에 걸렸을 때 곧바로 다시 부르면 또 걸린다. 그 사이에는
     * 다른 링크를 읽는다 — fetched_at 을 찍어 두고 그보다 오래된 것만 집는다.
     * 칸을 새로 만들지 않고 이미 있는 칸으로 뒤로 미루는 방법이다.
     */
    public const RETRY_AFTER_MINUTES = 5;

    private RemoteSource $remote;

    /** 마지막 실패·보류 사유. 워커가 "왜 멈췄는지" 를 화면에 적는 데 쓴다. */
    private string $lastMessage = '';

    public function __construct(private PDO $pdo)
    {
        $this->remote = new RemoteSource($pdo);
    }

    public function lastMessage(): string
    {
        return $this->lastMessage;
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
        $readable = $unreadable = 0;     // 상한을 따로 센다

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

                    $hit      = RemoteSource::identify($url);
                    $provider = $hit['provider'] ?? 'other';
                    // 읽을 수 없는 서비스(노션·사내 위키)는 담되 'skip' 으로
                    // 둔다. 찾았다는 사실 자체가 정보다 — 사람이 보고 판단한다.
                    $status   = $hit === null ? 'skip' : 'pending';

                    // 상한은 **각자 센다.** 못 읽는 주소가 읽을 주소의 자리를
                    // 빼앗으면 안 된다.
                    if ($status === 'pending') {
                        if (++$readable > self::MAX_LINKS) {
                            $skipped++;
                            continue;
                        }
                    } elseif (++$unreadable > self::MAX_SKIP_LINKS) {
                        continue;       // 기록용이라 넘쳐도 알리지 않는다
                    }

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
    public function nextPending(int $projectId, array $skipProviders = []): ?array
    {
        // 쉬는 중인 연동은 아예 집지 않는다. 피그마가 막혔다고 구글까지
        // 멈추면 안 되고, 막힌 쪽을 집어 봐야 또 같은 벽에 부딪힌다.
        $sql = 'SELECT * FROM bs_source_link
                 WHERE project_id = ? AND status = "pending"
                   AND (fetched_at IS NULL OR fetched_at < DATE_SUB(NOW(), INTERVAL ? MINUTE))';
        $arg = [$projectId, self::RETRY_AFTER_MINUTES];
        foreach ($skipProviders as $p) {
            $sql  .= ' AND provider <> ?';
            $arg[] = (string)$p;
        }
        $sql .= ' ORDER BY id LIMIT 1';

        $st = $this->pdo->prepare($sql);
        $st->execute($arg);
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
            $this->lastMessage = $msg;
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

    // =================================================================
    // 피그마 묶어 받기
    //
    // ┌──────────────────────────────────────────────────────────────┐
    // │ 이 기능이 성립하는 유일한 길                                   │
    // │                                                              │
    // │ 링크 하나에 호출 하나면 IA 시트 117건은 호출 117번이다.       │
    // │ 피그마의 제한은 분당 호출 수가 아니라 **며칠 단위 비용        │
    // │ 예산**이어서, 117번을 한 번 돌리면 예산이 바닥난다. 실제로    │
    // │ 2026-10-04 에 그렇게 2일 14시간짜리 정지를 받았다.            │
    // │                                                              │
    // │ 같은 파일을 가리키는 링크들의 node-id 를 모아 한 번에 묻는다. │
    // │ 117건이 호출 3번이 된다.                                      │
    // └──────────────────────────────────────────────────────────────┘
    // =================================================================

    /**
     * 묶음 하나를 받아 담는다. 더 받을 것이 없으면 null.
     *
     * **한 번에 한 묶음만** 한다. 호출 사이에 쉬고, 제한에 걸리면 즉시
     * 멈추는 판단은 워커가 한다 — 여기서 다 돌려 버리면 그 둘을 못 한다.
     *
     * 호출 제한에 걸리면 RemoteSourceError 를 **그대로 던진다.** 워커가
     * 받아서 그 회차를 끝낸다.
     *
     * @return array{file:string, asked:int, ok:int, fail:int}|null
     * @throws RemoteSourceError
     */
    public function figmaBatchOnce(int $projectId): ?array
    {
        $group = $this->nextFigmaGroup($projectId);
        if ($group === null) {
            return null;
        }
        [$fileKey, $byNode] = $group;

        $got = $this->remote->figmaNodes($fileKey, array_keys($byNode));

        $ok = $fail = 0;
        foreach ($byNode as $nodeId => $linkIds) {
            $hit = $got['nodes'][$nodeId] ?? null;
            foreach ($linkIds as $linkId) {
                if ($hit === null) {
                    // 돌려받지 못한 id = 그 파일에 그 노드가 없다. 기다려도
                    // 안 풀리므로 실패로 못 박는다. 사유를 구체적으로 적어
                    // 사람이 어디를 볼지 알게 한다.
                    $this->save($linkId, 'fail', null, null,
                        '그 화면을 찾지 못했습니다. 지워졌거나, 다른 가지(브랜치)의 '
                        . '주소거나, 연결된 계정이 이 파일을 볼 수 없을 수 있습니다.');
                    $fail++;
                } else {
                    $this->save($linkId, 'ok', mb_substr($hit['name'], 0, 200), $hit['text'], null);
                    $ok++;
                }
            }
        }

        return ['file' => $got['file'], 'asked' => count($byNode), 'ok' => $ok, 'fail' => $fail];
    }

    /** 아직 안 읽은 피그마 링크가 몇 건인가. 묶음 몇 번이면 되는지 가늠한다. */
    public function figmaPendingCount(int $projectId): int
    {
        $st = $this->pdo->prepare(
            'SELECT COUNT(*) FROM bs_source_link
              WHERE project_id = ? AND status = "pending" AND provider = ?'
        );
        $st->execute([$projectId, Integration::FIGMA]);
        return (int)$st->fetchColumn();
    }

    /**
     * 다음에 물을 묶음. 같은 파일끼리 모아 node-id 를 최대 FIGMA_BATCH 개.
     *
     * 서로 다른 링크가 같은 node-id 를 가리키는 일이 있다(IA 시트에서 한
     * 화면을 여러 항목이 참조한다). 그래서 node-id 하나에 링크 여럿을 단다
     * — 한 번 받은 글을 그 링크 전부에 나눠 담는다.
     *
     * @return array{0:string, 1:array<string, list<int>>}|null
     */
    private function nextFigmaGroup(int $projectId): ?array
    {
        $st = $this->pdo->prepare(
            'SELECT id, url FROM bs_source_link
              WHERE project_id = ? AND status = "pending" AND provider = ?
              ORDER BY id'
        );
        $st->execute([$projectId, Integration::FIGMA]);

        $byFile = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $hit = RemoteSource::identify((string)$r['url']);
            // node-id 가 없는 주소는 파일 전체를 읽어야 해서 묶을 수 없다.
            // 그쪽은 기존 한 건씩 경로(fetchOne)가 맡는다.
            if ($hit === null || (string)($hit['node'] ?? '') === '') {
                continue;
            }
            $byFile[(string)$hit['id']][(string)$hit['node']][] = (int)$r['id'];
        }
        if ($byFile === []) {
            return null;
        }

        // 가장 많이 쌓인 파일부터 턴다. 묶음 효율이 가장 좋은 쪽이다.
        uasort($byFile, static fn($a, $b) => count($b) <=> count($a));
        $fileKey = (string)array_key_first($byFile);

        return [$fileKey, array_slice($byFile[$fileKey], 0, self::FIGMA_BATCH, true)];
    }

    /**
     * 실패한 것을 다시 읽을 수 있게 되돌린다. 설정을 고친 뒤 쓴다.
     *
     * **fetched_at 도 지운다.** 안 지우면 nextPending() 의 되시도 간격
     * (RETRY_AFTER_MINUTES)에 걸려, 사람이 단추를 눌러도 몇 분간 아무 일도
     * 일어나지 않는다. 그 간격은 호출 제한에 걸린 것을 스스로 미루려고 둔
     * 장치지, 사람이 "지금 다시 하라" 고 한 것까지 미루라는 뜻이 아니다.
     */
    public function retryFailed(int $projectId): int
    {
        $st = $this->pdo->prepare(
            'UPDATE bs_source_link SET status = "pending", error = NULL, fetched_at = NULL
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

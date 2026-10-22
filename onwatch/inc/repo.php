<?php
/**
 * 조회·저장. 모두 바인딩 파라미터. 감시는 누구나 보고 누구나 만든다 —
 * 고치기·지우기만 등록자(와 포털 관리자)로 막는다(presenter.php).
 */

const OW_WATCH_COLS = 'w.*, u.name AS owner_name, u.email AS owner_email, u.color AS owner_color';

function ow_watch_get(int $id): ?array
{
    $st = ow_db()->prepare("SELECT " . OW_WATCH_COLS . " FROM ow_watch w
                              LEFT JOIN portal_users u ON u.id = w.owner_id WHERE w.id = ?");
    $st->execute([$id]);
    $row = $st->fetch();
    return $row ?: null;
}

/**
 * 목록.
 * @param string $scope all | mine(내가 만든) | subscribed(내가 받는)
 * @param string $status active | paused | ended | '' (종료 빼고 전부) | any
 */
function ow_watch_list(int $uid, string $scope = 'all', string $status = '', string $q = ''): array
{
    $sql = "SELECT " . OW_WATCH_COLS . ",
                   (SELECT COUNT(*) FROM ow_subscriber s WHERE s.watch_id = w.id) AS sub_count,
                   (SELECT COUNT(*) FROM ow_subscriber s WHERE s.watch_id = w.id AND s.user_id = ?) AS is_sub,
                   (SELECT COUNT(*) FROM ow_finding f WHERE f.watch_id = w.id) AS finding_count,
                   (SELECT f.title FROM ow_finding f WHERE f.watch_id = w.id ORDER BY f.id DESC LIMIT 1) AS last_finding
              FROM ow_watch w
         LEFT JOIN portal_users u ON u.id = w.owner_id
             WHERE 1 = 1";
    $args = [$uid];
    if ($scope === 'mine') {
        $sql .= " AND w.owner_id = ?";
        $args[] = $uid;
    } elseif ($scope === 'subscribed') {
        $sql .= " AND EXISTS (SELECT 1 FROM ow_subscriber s WHERE s.watch_id = w.id AND s.user_id = ?)";
        $args[] = $uid;
    }
    if (isset(OW_STATUS_LABEL[$status])) {
        $sql .= " AND w.status = ?";
        $args[] = $status;
    } elseif ($status !== 'any') {
        $sql .= " AND w.status <> 'ended'";
    }
    if ($q !== '') {
        $like = '%' . addcslashes($q, '%_\\') . '%';
        $sql .= " AND (w.title LIKE ? OR w.prompt LIKE ?)";
        array_push($args, $like, $like);
    }
    // 감시 중 → 일시정지 → 종료, 그 안에서는 최근 소식·최근 등록 순
    $sql .= " ORDER BY FIELD(w.status, 'active', 'paused', 'ended'),
                       COALESCE(w.last_change_at, w.created_at) DESC, w.id DESC
              LIMIT 300";
    $st = ow_db()->prepare($sql);
    $st->execute($args);
    return $st->fetchAll();
}

/** 화면 머리의 숫자들 — 전체·내 것·받는 것 */
function ow_counts(int $uid): array
{
    $st = ow_db()->prepare("SELECT
            SUM(status <> 'ended') AS open_all,
            SUM(status <> 'ended' AND owner_id = ?) AS open_mine,
            SUM(status <> 'ended' AND EXISTS (SELECT 1 FROM ow_subscriber s WHERE s.watch_id = ow_watch.id AND s.user_id = ?)) AS open_sub
          FROM ow_watch");
    $st->execute([$uid, $uid]);
    $r = $st->fetch() ?: [];
    return ['all' => (int)($r['open_all'] ?? 0), 'mine' => (int)($r['open_mine'] ?? 0), 'subscribed' => (int)($r['open_sub'] ?? 0)];
}

/** 이번 달(KST) 비용. $watchId 가 null 이면 전체. */
function ow_month_cost(?int $watchId = null): float
{
    $sql = "SELECT COALESCE(SUM(cost_usd), 0) FROM ow_run WHERE started_at >= ?";
    $args = [date('Y-m-01 00:00:00')];
    if ($watchId !== null) {
        $sql .= " AND watch_id = ?";
        $args[] = $watchId;
    }
    $st = ow_db()->prepare($sql);
    $st->execute($args);
    return (float)$st->fetchColumn();
}

function ow_runs(int $watchId, int $limit = 30): array
{
    $st = ow_db()->prepare("SELECT r.*, u.name AS requested_name FROM ow_run r
                              LEFT JOIN portal_users u ON u.id = r.requested_by
                             WHERE r.watch_id = ? ORDER BY r.id DESC LIMIT " . max(1, min(200, $limit)));
    $st->execute([$watchId]);
    return $st->fetchAll();
}

function ow_run_get(int $runId): ?array
{
    $st = ow_db()->prepare("SELECT * FROM ow_run WHERE id = ?");
    $st->execute([$runId]);
    return $st->fetch() ?: null;
}

function ow_findings(int $watchId, int $limit = 200): array
{
    $st = ow_db()->prepare("SELECT f.*, u.name AS feedback_name FROM ow_finding f
                              LEFT JOIN portal_users u ON u.id = f.feedback_by
                             WHERE f.watch_id = ? ORDER BY f.id DESC LIMIT " . max(1, min(500, $limit)));
    $st->execute([$watchId]);
    return $st->fetchAll();
}

function ow_events(int $watchId, int $limit = 50): array
{
    $st = ow_db()->prepare("SELECT e.*, u.name AS user_name FROM ow_event e
                              LEFT JOIN portal_users u ON u.id = e.user_id
                             WHERE e.watch_id = ? ORDER BY e.id DESC LIMIT " . max(1, min(200, $limit)));
    $st->execute([$watchId]);
    return $st->fetchAll();
}

function ow_subscribers(int $watchId): array
{
    $st = ow_db()->prepare("SELECT u.id, u.name, u.email, u.color FROM ow_subscriber s
                              JOIN portal_users u ON u.id = s.user_id
                             WHERE s.watch_id = ? ORDER BY s.created_at");
    $st->execute([$watchId]);
    return $st->fetchAll();
}

function ow_is_subscribed(int $watchId, int $uid): bool
{
    $st = ow_db()->prepare("SELECT 1 FROM ow_subscriber WHERE watch_id = ? AND user_id = ?");
    $st->execute([$watchId, $uid]);
    return (bool)$st->fetchColumn();
}

function ow_subscribe(int $watchId, int $uid, bool $on): void
{
    if ($on) {
        ow_db()->prepare("INSERT IGNORE INTO ow_subscriber (watch_id, user_id, created_at) VALUES (?, ?, NOW())")
               ->execute([$watchId, $uid]);
    } else {
        ow_db()->prepare("DELETE FROM ow_subscriber WHERE watch_id = ? AND user_id = ?")->execute([$watchId, $uid]);
    }
}

function ow_log(int $watchId, ?int $uid, string $action, string $detail = ''): void
{
    ow_db()->prepare("INSERT INTO ow_event (watch_id, user_id, action, detail, created_at) VALUES (?, ?, ?, ?, NOW())")
           ->execute([$watchId, $uid, $action, $detail === '' ? null : mb_substr($detail, 0, 500)]);
}

/** 감시 상세 주소 — 알림의 url 과 같은 모양이어야 읽음 처리가 맞물린다(worker/notify.py). */
function ow_watch_url(int $watchId): string
{
    return OW_MODULE_KEY . '/view.php?id=' . $watchId;
}

/**
 * 이 감시의 알림을 읽음으로. 상세 화면을 열면 부른다.
 * @return string|null 읽음으로 바꾸기 전 가장 오래된 안 읽은 알림 시각 — 그 뒤 소식에 NEW 를 붙인다
 */
function ow_mark_seen(int $uid, int $watchId): ?string
{
    $url = ow_watch_url($watchId);
    $since = push_inbox_oldest_unread_url($uid, OW_MODULE_KEY . '.', $url);
    if ($since !== null) push_inbox_mark_read_url($uid, OW_MODULE_KEY . '.', $url);
    return $since;
}

/** 감시별 안 읽은 알림 수 — 목록의 '새 소식' 표시 */
function ow_unread_by_watch(int $uid): array
{
    $out = [];
    foreach (push_inbox_unread_urls($uid, OW_MODULE_KEY . '.') as $url => $n) {
        if (preg_match('#view\.php\?id=(\d+)#', $url, $m)) $out[(int)$m[1]] = ($out[(int)$m[1]] ?? 0) + $n;
    }
    return $out;
}

// ---------------------------------------------------------------------
// 입력 검사와 저장
// ---------------------------------------------------------------------

/**
 * 폼 값을 검사해 저장할 행으로 바꾼다.
 * @return array{0:array,1:array<string,string>} [행, 필드별 오류]
 */
function ow_validate(array $in, ?array $existing = null): array
{
    $err = [];
    $title  = trim((string)($in['title'] ?? ''));
    $prompt = trim((string)($in['prompt'] ?? ''));
    if ($title === '') $err['title'] = '이름을 적어 주세요.';
    elseif (mb_strlen($title) > 200) $err['title'] = '이름은 200자까지입니다.';
    if (mb_strlen($prompt) < 20) $err['prompt'] = '무엇을 살필지 20자 이상 적어 주세요.';
    elseif (mb_strlen($prompt) > (int)ow_config('prompt_max_chars')) {
        $err['prompt'] = '지시문은 ' . number_format((int)ow_config('prompt_max_chars')) . '자까지입니다.';
    }
    $baseline = trim((string)($in['baseline'] ?? ''));
    if (mb_strlen($baseline) > 8000) $err['baseline'] = '기준 상태는 8,000자까지입니다.';

    $interval = (int)($in['interval_min'] ?? 1440);
    if (!isset(OW_INTERVALS[$interval])) $err['interval_min'] = '주기를 골라 주세요.';
    $runTime = (string)($in['run_time'] ?? '09:00');
    if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $runTime)) $err['run_time'] = '실행 시각은 HH:MM 입니다.';
    $weekday = (int)($in['weekday'] ?? 1);
    if ($weekday < 1 || $weekday > 7) $weekday = 1;
    $hFrom = (int)($in['hour_from'] ?? 0);
    $hTo   = (int)($in['hour_to'] ?? 24);
    if ($hFrom < 0 || $hFrom > 23 || $hTo < 1 || $hTo > 24 || $hTo <= $hFrom) {
        $err['hour_from'] = '실행 시간대가 맞지 않습니다.';
    }

    $now = date('Y-m-d H:i:s');
    $startIn = trim((string)($in['starts_at'] ?? ''));
    $endIn   = trim((string)($in['ends_at'] ?? ''));
    $starts = $startIn === '' ? ($existing['starts_at'] ?? $now) : ow_parse_dt($startIn, false);
    $ends   = $endIn === '' ? null : ow_parse_dt($endIn, true);
    if ($starts === null) $err['starts_at'] = '시작일이 올바르지 않습니다.';
    if ($ends === null) $err['ends_at'] = '종료일을 정해 주세요. 수시 감시는 끝이 있어야 합니다.';
    if ($starts !== null && $ends !== null) {
        if ($ends <= $starts) $err['ends_at'] = '종료일은 시작일보다 뒤여야 합니다.';
        elseif ($ends <= $now) $err['ends_at'] = '종료일이 이미 지났습니다.';
        elseif ((strtotime($ends) - strtotime($starts)) / 86400 > (int)ow_config('max_days') + 1) {
            $err['ends_at'] = '감시 기간은 최대 ' . (int)ow_config('max_days') . '일입니다.';
        }
    }

    $notifyMode = (string)($in['notify_mode'] ?? 'change');
    if (!isset(OW_NOTIFY_MODES[$notifyMode])) $notifyMode = 'change';
    $minImp = (string)($in['min_importance'] ?? 'low');
    if (!isset(OW_IMPORTANCE[$minImp])) $minImp = 'low';

    $searches = (int)($in['max_searches'] ?? ow_config('default_searches'));
    if ($searches < 1 || $searches > (int)ow_config('max_searches')) {
        $err['max_searches'] = '검색 상한은 1~' . (int)ow_config('max_searches') . '회입니다.';
    }
    $budget = (float)($in['monthly_budget_usd'] ?? ow_config('default_budget_usd'));
    if ($budget < 0.5 || $budget > (float)ow_config('max_budget_usd')) {
        $err['monthly_budget_usd'] = '월 비용 상한은 0.5~' . ow_config('max_budget_usd') . ' USD 입니다.';
    }

    $webhook = trim((string)($in['slack_webhook'] ?? ''));
    if ($webhook !== '' && $webhook !== '__keep__' && !ow_valid_slack_webhook($webhook)) {
        $err['slack_webhook'] = '슬랙 웹훅은 https://hooks.slack.com/ 으로 시작하는 주소만 받습니다.';
    }

    $row = [
        'title'              => $title,
        'prompt'             => $prompt,
        'baseline'           => $baseline === '' ? null : $baseline,
        'starts_at'          => $starts,
        'ends_at'            => $ends,
        'interval_min'       => $interval,
        'run_time'           => $interval >= 1440 ? $runTime : null,
        'weekday'            => $interval >= 10080 ? $weekday : null,
        'weekdays_only'      => !empty($in['weekdays_only']) && $interval < 10080 ? 1 : 0,
        'hour_from'          => $interval < 1440 ? $hFrom : 0,
        'hour_to'            => $interval < 1440 ? $hTo : 24,
        'notify_mode'        => $notifyMode,
        'min_importance'     => $minImp,
        'notify_slack'       => !empty($in['notify_slack']) ? 1 : 0,
        'prefer_domains'     => implode("\n", ow_clean_domains((string)($in['prefer_domains'] ?? ''))) ?: null,
        'block_domains'      => implode("\n", ow_clean_domains((string)($in['block_domains'] ?? ''))) ?: null,
        'max_searches'       => $searches,
        'monthly_budget_usd' => round($budget, 2),
    ];
    // 웹훅: '__keep__' 이면 그대로, 빈 값이면 지움(기본 채널), 아니면 새 값
    if ($webhook !== '__keep__') {
        $row['slack_webhook_enc'] = $webhook === '' ? null : enc_token($webhook);
    }
    // 슬랙을 전체로 꺼 두었으면 화면에 칸이 없다. 고칠 때는 저장된 선택을 건드리지 않고
    // (다시 켜면 그대로 살아나게), 새로 만들 때는 끈 채로 둔다.
    if (!ow_config('slack_enabled')) {
        unset($row['slack_webhook_enc']);
        if ($existing) unset($row['notify_slack']);
        else $row['notify_slack'] = 0;
    }
    if (!$err && ow_next_run($row, ow_after_for($row)) === null) {
        $err['interval_min'] = '기간 안에 실행될 때가 없습니다. 주기·요일·시간대를 확인해 주세요.';
    }
    return [$row, $err];
}

/** 'YYYY-mm-dd' 또는 'YYYY-mm-ddTHH:ii'. 날짜만 오면 시작은 그날 00:00, 끝은 23:59:59. */
function ow_parse_dt(string $s, bool $endOfDay): ?string
{
    $s = str_replace('T', ' ', trim($s));
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) $s .= $endOfDay ? ' 23:59:59' : ' 00:00:00';
    elseif (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $s)) $s .= ':00';
    $dt = DateTime::createFromFormat('Y-m-d H:i:s', $s, new DateTimeZone('Asia/Seoul'));
    return $dt && $dt->format('Y-m-d H:i:s') === $s ? $s : null;
}

/** 새 감시. 등록자는 곧바로 받는 사람이 된다. @return int id */
function ow_watch_create(array $row, int $uid, bool $runNow): int
{
    $pdo = ow_db();
    $pdo->beginTransaction();
    try {
        $row['owner_id']   = $uid;
        $row['status']     = 'active';
        $row['created_at'] = date('Y-m-d H:i:s');
        $row['next_run_at'] = ow_next_run($row, ow_after_for($row));
        if ($runNow) {
            $row['run_requested_at'] = $row['created_at'];
            $row['run_requested_by'] = $uid;
        }
        $cols = array_keys($row);
        $pdo->prepare("INSERT INTO ow_watch (`" . implode('`,`', $cols) . "`) VALUES ("
                      . implode(',', array_fill(0, count($cols), '?')) . ")")
            ->execute(array_values($row));
        $id = (int)$pdo->lastInsertId();
        ow_subscribe($id, $uid, true);
        ow_log($id, $uid, 'create', $runNow ? '등록하며 바로 한 번 실행을 요청' : '');
        $pdo->commit();
        return $id;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** 고치기. 바뀐 칸 이름을 기록에 남긴다. */
function ow_watch_update(array $w, array $row, int $uid): void
{
    $changed = [];
    $labels = ['title' => '이름', 'prompt' => '지시문', 'baseline' => '기준 상태', 'starts_at' => '시작',
               'ends_at' => '종료', 'interval_min' => '주기', 'run_time' => '실행 시각', 'weekday' => '요일',
               'weekdays_only' => '평일만', 'hour_from' => '시간대', 'hour_to' => '시간대',
               'notify_mode' => '알림 방식', 'min_importance' => '최소 중요도', 'notify_slack' => '슬랙',
               'slack_webhook_enc' => '슬랙 웹훅', 'prefer_domains' => '우선 출처', 'block_domains' => '제외 도메인',
               'max_searches' => '검색 상한', 'monthly_budget_usd' => '월 비용 상한'];
    foreach ($row as $k => $v) {
        if ((string)($w[$k] ?? '') !== (string)$v) $changed[$labels[$k] ?? $k] = true;
    }
    if (!$changed) return;
    if (array_key_exists('baseline', $row) && (string)$w['baseline'] !== (string)$row['baseline']) {
        $row['baseline_updated_at'] = date('Y-m-d H:i:s');
    }
    $merged = array_merge($w, $row);
    if ($w['status'] === 'active') {
        $row['next_run_at'] = ow_next_run($merged, ow_after_for($merged));
    }
    // 종료일을 늦췄으면 종료 임박 알림을 다시 보낼 수 있게
    if (isset($row['ends_at']) && $row['ends_at'] !== $w['ends_at']) $row['end_notice_sent'] = 0;
    $row['updated_at'] = date('Y-m-d H:i:s');
    $sets = implode(', ', array_map(function ($c) { return "`$c` = ?"; }, array_keys($row)));
    ow_db()->prepare("UPDATE ow_watch SET $sets WHERE id = ?")->execute(array_merge(array_values($row), [(int)$w['id']]));
    ow_log((int)$w['id'], $uid, 'edit', implode(', ', array_keys($changed)));
}

/** 다음 실행을 셀 기준 시각 — 지금, 그러나 시작 전이면 시작 직전 */
function ow_after_for(array $w): string
{
    $now = date('Y-m-d H:i:s');
    $before = date('Y-m-d H:i:s', strtotime((string)$w['starts_at']) - 1);
    return $before > $now ? $before : $now;
}

/** 일시정지·재개·종료·연장·지금 실행 */
function ow_watch_set_status(array $w, string $status, int $uid): void
{
    $now = date('Y-m-d H:i:s');
    if ($status === 'active') {
        $next = ow_next_run($w, ow_after_for($w));
        ow_db()->prepare("UPDATE ow_watch SET status = 'active', next_run_at = ?, updated_at = ? WHERE id = ?")
               ->execute([$next, $now, (int)$w['id']]);
        ow_log((int)$w['id'], $uid, 'resume');
    } elseif ($status === 'paused') {
        ow_db()->prepare("UPDATE ow_watch SET status = 'paused', updated_at = ? WHERE id = ?")->execute([$now, (int)$w['id']]);
        ow_log((int)$w['id'], $uid, 'pause');
    } elseif ($status === 'ended') {
        ow_db()->prepare("UPDATE ow_watch SET status = 'ended', ended_at = ?, next_run_at = NULL, run_requested_at = NULL,
                                              updated_at = ? WHERE id = ?")->execute([$now, $now, (int)$w['id']]);
        ow_log((int)$w['id'], $uid, 'end', '직접 종료');
    }
}

function ow_watch_extend(array $w, int $days, int $uid): string
{
    $base = max(strtotime((string)$w['ends_at']), time());
    $limit = strtotime((string)$w['starts_at']) + ((int)ow_config('max_days') + 1) * 86400 - 1;
    $newEnd = date('Y-m-d 23:59:59', min($limit, $base + $days * 86400));
    if ($newEnd <= $w['ends_at']) {
        throw new DomainException('더 늘릴 수 없습니다. 감시 기간은 시작부터 최대 ' . (int)ow_config('max_days') . '일입니다.');
    }
    $merged = array_merge($w, ['ends_at' => $newEnd]);
    $status = $w['status'] === 'ended' ? 'active' : $w['status'];
    $next = $status === 'active' ? ow_next_run($merged, ow_after_for($merged)) : $w['next_run_at'];
    ow_db()->prepare("UPDATE ow_watch SET ends_at = ?, status = ?, next_run_at = ?, end_notice_sent = 0,
                                          ended_at = NULL, updated_at = NOW() WHERE id = ?")
           ->execute([$newEnd, $status, $next, (int)$w['id']]);
    ow_log((int)$w['id'], $uid, 'extend', '종료 ' . substr((string)$w['ends_at'], 0, 10) . ' → ' . substr($newEnd, 0, 10));
    return $newEnd;
}

function ow_request_run(array $w, int $uid): void
{
    ow_db()->prepare("UPDATE ow_watch SET run_requested_at = NOW(), run_requested_by = ? WHERE id = ? AND run_requested_at IS NULL")
           ->execute([$uid, (int)$w['id']]);
    ow_log((int)$w['id'], $uid, 'run', '지금 실행 요청');
}

function ow_cancel_run(array $w, int $uid): void
{
    ow_db()->prepare("UPDATE ow_watch SET run_requested_at = NULL, run_requested_by = NULL
                       WHERE id = ? AND (locked_until IS NULL OR locked_until < NOW())")
           ->execute([(int)$w['id']]);
    ow_log((int)$w['id'], $uid, 'run', '지금 실행 요청 취소');
}

function ow_apply_baseline(array $w, string $baseline, int $uid): void
{
    ow_db()->prepare("UPDATE ow_watch SET baseline = ?, baseline_updated_at = NOW(), updated_at = NOW() WHERE id = ?")
           ->execute([$baseline === '' ? null : $baseline, (int)$w['id']]);
    ow_log((int)$w['id'], $uid, 'baseline', '실행 결과의 제안으로 기준 상태를 바꿈');
}

function ow_set_feedback(int $findingId, int $watchId, ?string $fb, int $uid): void
{
    ow_db()->prepare("UPDATE ow_finding SET feedback = ?, feedback_by = ?, feedback_at = ? WHERE id = ? AND watch_id = ?")
           ->execute([$fb, $fb === null ? null : $uid, $fb === null ? null : date('Y-m-d H:i:s'), $findingId, $watchId]);
}

/** 복제용 — 사람·상태·시각 칸을 뺀 설정만 */
function ow_clone_fields(array $w): array
{
    $keep = ['title', 'prompt', 'baseline', 'interval_min', 'run_time', 'weekday', 'weekdays_only', 'hour_from',
             'hour_to', 'notify_mode', 'min_importance', 'notify_slack', 'prefer_domains', 'block_domains',
             'max_searches', 'monthly_budget_usd'];
    $out = array_intersect_key($w, array_flip($keep));
    $out['title'] = $w['title'] . ' (복사본)';
    return $out;
}

function ow_watch_delete(int $id): void
{
    ow_db()->prepare("DELETE FROM ow_watch WHERE id = ?")->execute([$id]);
}

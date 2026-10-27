<?php
/**
 * BlueLab SiteHub API (포털 로그인 필요). access_api.php 와 같은 모양 — GET 은 쿼리, 쓰기는 POST JSON {action}.
 *   GET  ?all=1                → 사이트 전체(+ 접속/담당자/계약 묶음 + 이력 건수 + 계약 상태). 보관(archived=1)도 함께 — 화면이 가른다
 *   GET  ?history=<site_id>    → 그 사이트의 이력
 *   GET  ?unmatched=1          → 사이트가 안 정해진 이력
 *   GET  ?staff=1              → portal_users 이름·이메일 (블루소프트 담당자 선택용)
 *   GET  ?stats=1&period=12m|ytd|lastyear|all&cost_period=(같은 값, 지출 비용용)&year=YYYY(연간 계약액 연도, 기본 올해) → 통계 (지정 계정만, 그 외 403)
 *   GET  ?changes=<site_id>    → 그 사이트의 변경 기록 (누가 언제 무엇을, audit_lib.php)
 *   POST {action:save_field, site_id, key, value}
 *   POST {action:create, ...}  / {action:save, site_id, ...} / {action:delete, site_id}
 *   POST {action:archive, site_id, archived:1|0, note}   → 보관함으로 / 복원 (데이터는 그대로)
 *   POST {action:sub_save, table, site_id, id, ...} / {action:sub_delete, table, id} / {action:sub_sort, table, site_id, ids}
 *   GET  ?procedures=1                 → 처리 절차 전체(공통 site_id=0 + 사이트별) + 처리 횟수
 *   POST {action:proc_save, id, site_id(0=공통), title, category, duration, link_url, steps, caution} / {action:proc_delete, id}
 *   POST {action:proc_sort, site_id, ids} / {action:proc_copy, id, site_id}   → 순서 저장 / 공통 절차를 그 사이트용으로 복사
 *   GET  ?proc_suggest=1[&site=<id>]    → 이력에서 뽑은 절차 등록 제안 (proc_suggest.php). site 를 주면 그 사이트 이력만으로(사이트 전용 제안)
 *   POST {action:proc_suggest_register, keyword, scope_site, site_id, title, …} → 제안을 절차로 등록하고 그 묶음의 이력을 모두 연결 / {action:proc_suggest_ignore, keyword}
 *   POST {action:proc_use, procedure_id, history_id} / {action:proc_unuse, ...} → 이력을 그 절차로 처리했다고 연결 / 해제
 *   POST {action:history_assign, id, site_id}
 *   GET  ?history_options=1 → 이력 폼 선택지 (처리상태·요청구분·무/유상·우선순위)
 *   POST {action:history_add, site_id, req_at, channel, body, status, paid, priority, remark} / history_update {id, 바꿀 칸} / history_delete {id}
 *        → Slack 리스트에 먼저 쓰고(개인 토큰) DB 반영. history_write.php
 *   POST {action:auto_resolve}                     → 미매칭 자동 정리 계획 (기존 사이트 연결 / 새 사이트 등록 / 건너뜀)
 *   POST {action:auto_resolve, apply:1, exclude:[표기…]} → 적용
 *   ── BLUMO 감시 (monitor_*.php) ──
 *   GET  ?monitors=1                 → 원 상태만 (목록이 1분마다)
 *   GET  ?monitor_board=1            → 감시 주소 전체 + 상태 + BLUMO 상태 (monitor.php 대시보드)
 *   GET  ?monitor_detail=<id>        → 점검 기록 80회·장애 20건·가용률
 *   GET  ?monitor_incidents=1        → 장애 이력 300건
 *   GET  ?monitor_status=1           → 전체 점검 진행 (잠금 파일)
 *   GET  ?agent=<id>                 → 키가 들어간 blumo-agent.php 내려받기
 *   GET  ?monitor_settings=1         → 설정 (지정 계정만)
 *   POST {action:monitor_toggle, site_id, on}   → 사이트 감시 켜기/끄기 (켤 때 주소가 없으면 운영 URL 로)
 *   POST {action:monitor_probe, url}            → 주소 열어 확인 (https→http, 제목으로 정상 판정 문구 후보)
 *   POST {action:monitor_check, id}             → 지금 점검 (하나, 기다렸다 결과)
 *   POST {action:monitor_run_all}               → 전체 지금 점검 (뒤로 CLI 를 띄운다)
 *   POST {action:monitor_enable, id, on} / {action:monitor_mute, id, min}  → 자동 점검 멈춤·재개 / 알림 무음
 *   ── 지정 계정만 (sitehub_can_stats) ──
 *   GET  ?blumo=1 · POST {action:blumo_import, items:[{blumo_id, site_id}]} → 예전 BLUMO 31곳을 감시 주소로
 *   POST {action:monitor_settings_save, …} / monitor_pause {on} / monitor_test_webhook / monitor_doctor
 *   POST {action:monitor_legacy_import, path, settings, history}  → 예전 blumo.sqlite 의 설정·기록 가져오기
 * 쓰기 응답에는 바뀐 사이트 한 건(site)을 실어 보내서 화면이 전체를 다시 안 받아도 되게 한다.
 */
require_once __DIR__ . '/guard.php';
require_once __DIR__ . '/db.php';

$me = current_portal_user();
if (!$me) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => '로그인이 필요합니다'], JSON_UNESCAPED_UNICODE);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function sitehub_out(array $o) {
    echo json_encode($o, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function sitehub_history_rows(PDO $pdo, string $where, array $args) {
    $st = $pdo->prepare("SELECT * FROM lab_history WHERE {$where} ORDER BY COALESCE(req_at, `date`) DESC, created DESC");
    $st->execute($args);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        $r['site_id']     = $r['site_id'] === null ? null : (int)$r['site_id'];
        $r['site_locked'] = (int)$r['site_locked'];
        $r['archived']    = (int)$r['archived'];
        $r['created']     = (int)$r['created'];
        $r['updated']     = (int)$r['updated'];
        $r['attachments'] = $r['attachments'] ? (json_decode($r['attachments'], true) ?: []) : [];
    }
    unset($r);
    if ($rows) {
        $ids = array_column($rows, 'id');
        $st = $pdo->prepare("SELECT history_id, procedure_id FROM lab_procedure_uses WHERE history_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")");
        $st->execute($ids);
        $by = [];
        foreach ($st->fetchAll() as $u) $by[$u['history_id']][] = (int)$u['procedure_id'];
        foreach ($rows as &$r) $r['procs'] = $by[$r['id']] ?? [];
        unset($r);
    }
    return $rows;
}

function sitehub_meta(PDO $pdo, array $me) {
    $unmatched = (int)$pdo->query("SELECT COUNT(*) FROM lab_history WHERE site_id IS NULL AND archived = 0 AND parent_id IS NULL")->fetchColumn();
    $last = $pdo->query("SELECT v FROM lab_sync_meta WHERE k = 'last_sync_at'")->fetchColumn();
    // sync_age: 마지막 동기화 후 지난 초 — 화면 자동 동기화가 브라우저 시계 대신 이 값을 쓴다(PC 시계가 틀려도 안 어긋나게)
    return ['unmatched' => $unmatched, 'last_sync' => $last ?: null, 'sync_age' => $last ? max(0, time() - strtotime($last)) : null,
            'can_sync' => sitehub_can_sync($me), 'blumo' => sitehub_blumo_meta($pdo)];
}

try {
    $pdo = sitehub_db();

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        sitehub_session_release();

        if (isset($_GET['staff'])) {
            $rows = $pdo->query("SELECT email, name FROM portal_users ORDER BY name, email")->fetchAll();
            sitehub_out(['ok' => true, 'staff' => $rows]);
        }
        if (isset($_GET['history_options'])) {   // 이력 추가·수정 폼의 선택지 (Slack 리스트 스키마 · 개인 토큰 필요)
            require_once __DIR__ . '/history_write.php';
            sitehub_out(['ok' => true, 'options' => sitehub_hw_options($pdo, $me)]);
        }
        if (isset($_GET['history'])) {
            $sid = (int)$_GET['history'];
            if ($sid <= 0) throw new Exception('사이트를 선택하세요.');
            sitehub_out(['ok' => true, 'rows' => sitehub_history_rows($pdo, 'site_id = ? AND archived = 0', [$sid]),
                         'meta' => sitehub_meta($pdo, $me)]);
        }
        if (isset($_GET['stats'])) {
            // 지정 계정만 (guard.php sitehub_can_stats). 화면만 숨기지 않고 여기서 막는다.
            if (!sitehub_can_stats($me)) {
                http_response_code(403);
                sitehub_out(['ok' => false, 'error' => '통계는 지정된 계정만 볼 수 있습니다.']);
            }
            require_once __DIR__ . '/stats_lib.php';
            sitehub_out(['ok' => true] + sitehub_stats($pdo, (string)($_GET['period'] ?? '12m'), (string)($_GET['cost_period'] ?? '12m'), (string)($_GET['year'] ?? '')));
        }
        if (isset($_GET['proc_suggest'])) {
            require_once __DIR__ . '/proc_suggest.php';
            sitehub_out(['ok' => true, 'suggestions' => sitehub_proc_suggestions($pdo, (int)($_GET['site'] ?? 0) ?: null)]);
        }
        if (isset($_GET['procedures'])) {
            sitehub_out(['ok' => true, 'procedures' => sitehub_load_procedures($pdo)]);
        }
        if (isset($_GET['changes'])) {
            $sid = (int)$_GET['changes'];
            if ($sid <= 0) throw new Exception('사이트를 선택하세요.');
            sitehub_out(['ok' => true, 'rows' => sitehub_changes($pdo, $sid)]);
        }
        if (isset($_GET['monitors'])) {
            sitehub_out(['ok' => true, 'sites' => sitehub_monitor_snapshot($pdo), 'blumo' => sitehub_blumo_meta($pdo)]);
        }
        if (isset($_GET['monitor_board'])) {
            sitehub_out(['ok' => true, 'rows' => sitehub_monitor_board($pdo), 'blumo' => sitehub_blumo_meta($pdo)]);
        }
        if (isset($_GET['monitor_detail'])) {
            sitehub_out(['ok' => true] + sitehub_monitor_detail($pdo, (int)$_GET['monitor_detail']));
        }
        if (isset($_GET['monitor_incidents'])) {
            sitehub_out(['ok' => true, 'rows' => sitehub_monitor_incidents($pdo)]);
        }
        if (isset($_GET['monitor_status'])) {
            sitehub_out(['ok' => true, 'running' => blumo_lock_info(), 'blumo' => sitehub_blumo_meta($pdo)]);
        }
        if (isset($_GET['agent'])) {
            $f = sitehub_monitor_agent_file($pdo, (int)$_GET['agent']);
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . $f['name'] . '"');
            echo $f['body'];
            exit;
        }
        if (isset($_GET['monitor_settings']) || isset($_GET['blumo'])) {
            // 웹훅 주소(채널에 아무나 글을 쓸 수 있는 값)가 실리므로 지정 계정만
            if (!sitehub_can_stats($me)) { http_response_code(403); sitehub_out(['ok' => false, 'error' => 'BLUMO 설정은 지정된 계정만 볼 수 있습니다.']); }
            if (isset($_GET['blumo'])) sitehub_out(['ok' => true, 'seed' => sitehub_blumo_seed_plan($pdo), 'meta' => sitehub_blumo_meta($pdo)]);
            $cfg = blumo_cfg($pdo);
            $legacy = blumo_legacy_path();
            sitehub_out(['ok' => true, 'cfg' => array_intersect_key($cfg, BLUMO_DEFAULTS) + ['webhook_url' => $cfg['webhook_url'], 'paused' => $cfg['paused']],
                         'ranges' => BLUMO_RANGES, 'meta' => sitehub_blumo_meta($pdo), 'php_cli' => blumo_php_cli(), 'ca' => is_file($cfg['ca_bundle']),
                         'run_cmd' => 'php ' . str_replace('\\', '/', __DIR__) . '/monitor_run.php',
                         'legacy' => ['path' => $legacy, 'exists' => is_file($legacy), 'sqlite' => extension_loaded('pdo_sqlite'),
                                      'imported_at' => blumo_meta_get($pdo, 'legacy_import_at')]]);
        }
        if (isset($_GET['unmatched'])) {
            // 하위 항목은 부모를 따라가므로 미매칭 목록에는 요청(최상위)만 — 부모를 지정하면 하위도 같이 간다
            sitehub_out(['ok' => true, 'rows' => sitehub_history_rows($pdo, 'site_id IS NULL AND archived = 0 AND parent_id IS NULL', []),
                         'meta' => sitehub_meta($pdo, $me)]);
        }

        // 첫 화면: 사이트가 하나도 없고 레포 루트에 호스팅 정보 엑셀이 있으면 그걸로 채운다 (버튼 없이 바로 데이터가 보이게)
        $seeded = null;
        if ((int)$pdo->query("SELECT COUNT(*) FROM lab_sites")->fetchColumn() === 0) {
            $xlsx = dirname(__DIR__) . '/호스팅 정보_v.xlsx';
            if (is_file($xlsx)) {
                require_once __DIR__ . '/import_xlsx.php';
                try { $seeded = sitehub_import_xlsx($xlsx, false, false); unset($seeded['per']); }
                catch (Throwable $e) { $seeded = ['error' => $e->getMessage()]; }
            }
        }
        $sites = sitehub_load_sites($pdo, null, true);
        $staff = $pdo->query("SELECT email, name FROM portal_users ORDER BY name, email")->fetchAll();
        sitehub_out(['ok' => true, 'sites' => $sites, 'staff' => $staff, 'meta' => sitehub_meta($pdo, $me), 'seeded' => $seeded]);
    }

    $in     = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = (string)($in['action'] ?? '');
    $siteId = (int)($in['site_id'] ?? 0);
    $COLS   = sitehub_cols();

    // ── 마스터(lab_sites) ──────────────────────────────────────────────────

    if ($action === 'save_field') {
        $key = (string)($in['key'] ?? '');
        $f   = sitehub_field($key);
        if (!$f || !in_array($key, $COLS, true)) throw new Exception('수정할 수 없는 항목입니다: ' . $key);
        if ($siteId <= 0) throw new Exception('사이트를 선택하세요.');
        $val = sitehub_normalize($f, $in['value'] ?? '');
        $st = $pdo->prepare("SELECT * FROM lab_sites WHERE id=?");
        $st->execute([$siteId]);
        $old = $st->fetch();
        if (!$old) throw new Exception('사이트를 찾을 수 없습니다.');
        $diff = sitehub_audit_diff($pdo, [$f], $old, [$key => $val]);
        if ($diff) {   // 바뀐 게 없으면 수정일도 안 올린다
            $pdo->prepare("UPDATE lab_sites SET `{$key}`=?, updated_at=NOW(), updated_by=? WHERE id=?")->execute([$val, $me['email'], $siteId]);
            sitehub_audit($pdo, $me, $siteId, 'update', 'site', $siteId, (string)$old['name'], $diff);
        }
        sitehub_out(['ok' => true, 'site' => sitehub_load_site($pdo, $siteId)]);
    }

    if ($action === 'create' || $action === 'save') {
        $vals = [];
        foreach (sitehub_field_groups() as $g) {
            foreach ($g['fields'] as $f) {
                if ($action === 'save' && !array_key_exists($f['key'], $in)) continue;   // 보낸 칸만 고친다
                $vals[$f['key']] = sitehub_normalize($f, $in[$f['key']] ?? '');
            }
        }
        if (array_key_exists('name', $vals) && $vals['name'] === '') throw new Exception('사이트명을 입력하세요.');

        if ($action === 'create') {
            // 같은 사이트명이라도 고객사가 다르면 별개일 수 있다(같은 이름의 사이트를 두 고객사가 쓰는 경우). 둘 다 같을 때만 막는다.
            $st = $pdo->prepare("SELECT id, archived FROM lab_sites WHERE name=? AND customer=?");
            $st->execute([$vals['name'], $vals['customer'] ?? '']);
            if ($dup = $st->fetch()) {
                $label = $vals['name'] . ($vals['customer'] !== '' ? ' / ' . $vals['customer'] : '');
                throw new Exception((int)$dup['archived'] ? "같은 사이트가 보관함에 있습니다. 보관함에서 복원해 쓰세요. ({$label})"
                                                         : "이미 등록된 사이트입니다. ({$label})");
            }
            $max = (int)$pdo->query("SELECT COALESCE(MAX(sort_no),0) FROM lab_sites")->fetchColumn();
            $cols = array_keys($vals);
            $sel  = implode(',', array_map(fn($c) => "`$c`", $cols));
            $ph   = implode(',', array_fill(0, count($cols), '?'));
            $pdo->prepare("INSERT INTO lab_sites ({$sel}, sort_no, created_at, updated_at, updated_by) VALUES ({$ph}, ?, NOW(), NOW(), ?)")
                ->execute(array_merge(array_values($vals), [$max + 1, $me['email']]));
            $siteId = (int)$pdo->lastInsertId();
            sitehub_audit($pdo, $me, $siteId, 'create', 'site', $siteId, (string)$vals['name']);
            sitehub_out(['ok' => true, 'site_id' => $siteId, 'site' => sitehub_load_site($pdo, $siteId)]);
        }

        if ($siteId <= 0) throw new Exception('사이트를 선택하세요.');
        if (!$vals) throw new Exception('고칠 내용이 없습니다.');
        $st = $pdo->prepare("SELECT * FROM lab_sites WHERE id=?");
        $st->execute([$siteId]);
        $old = $st->fetch();
        if (!$old) throw new Exception('사이트를 찾을 수 없습니다.');
        $fields = [];
        foreach (sitehub_field_groups() as $g) foreach ($g['fields'] as $f) $fields[] = $f;
        $diff = sitehub_audit_diff($pdo, $fields, $old, $vals);
        if ($diff) {
            $set = implode(',', array_map(fn($c) => "`$c`=?", array_keys($vals)));
            $pdo->prepare("UPDATE lab_sites SET {$set}, updated_at=NOW(), updated_by=? WHERE id=?")
                ->execute(array_merge(array_values($vals), [$me['email'], $siteId]));
            sitehub_audit($pdo, $me, $siteId, 'update', 'site', $siteId, (string)($vals['name'] ?? $old['name']), $diff);
        }
        sitehub_out(['ok' => true, 'site' => sitehub_load_site($pdo, $siteId)]);
    }

    // 보관 / 복원 — 삭제 대신 쓴다. 데이터는 그대로 두고 archived 플래그만 바꾸므로 언제든 되돌릴 수 있다.
    if ($action === 'archive') {
        if ($siteId <= 0) throw new Exception('사이트를 선택하세요.');
        $on   = !empty($in['archived']) ? 1 : 0;
        $note = $on ? mb_substr(trim((string)($in['note'] ?? '')), 0, 500) : '';
        $st = $pdo->prepare("SELECT name, archived, archive_note FROM lab_sites WHERE id=?");
        $st->execute([$siteId]);
        $old = $st->fetch();
        if (!$old) throw new Exception('사이트를 찾을 수 없습니다.');
        if ((int)$old['archived'] !== $on) {
            $pdo->prepare("UPDATE lab_sites SET archived=?, archived_at=" . ($on ? 'NOW()' : 'NULL') . ", archive_note=?, updated_at=NOW(), updated_by=? WHERE id=?")
                ->execute([$on, $note, $me['email'], $siteId]);
            sitehub_audit($pdo, $me, $siteId, $on ? 'archive' : 'restore', 'site', $siteId, (string)$old['name'], [], $note);
        } elseif ($on && $note !== (string)$old['archive_note']) {   // 이미 보관된 사이트의 사유만 고침
            $pdo->prepare("UPDATE lab_sites SET archive_note=?, updated_at=NOW(), updated_by=? WHERE id=?")->execute([$note, $me['email'], $siteId]);
            sitehub_audit($pdo, $me, $siteId, 'update', 'site', $siteId, (string)$old['name'],
                [['field' => 'archive_note', 'label' => '보관 사유', 'old' => (string)$old['archive_note'], 'new' => $note, 'secret' => 0]]);
        }
        sitehub_out(['ok' => true, 'site' => sitehub_load_site($pdo, $siteId)]);
    }

    if ($action === 'delete') {
        if ($siteId <= 0) throw new Exception('사이트를 선택하세요.');
        $st = $pdo->prepare("SELECT name FROM lab_sites WHERE id=?");
        $st->execute([$siteId]);
        $name = (string)$st->fetchColumn();
        $pdo->beginTransaction();
        sitehub_audit($pdo, $me, $siteId, 'delete', 'site', $siteId, $name);   // 사이트는 사라져도 기록은 남는다
        foreach (sitehub_sub_fields() as $def) {
            $pdo->prepare("DELETE FROM `{$def['table']}` WHERE site_id=?")->execute([$siteId]);
        }
        // 이력은 Slack 쪽 기록이라 지우지 않는다 — 사이트 연결만 푼다
        $pdo->prepare("UPDATE lab_history SET site_id=NULL, site_locked=0 WHERE site_id=?")->execute([$siteId]);
        $pdo->prepare("DELETE FROM lab_sites WHERE id=?")->execute([$siteId]);
        $pdo->commit();
        sitehub_out(['ok' => true, 'meta' => sitehub_meta($pdo, $me)]);
    }

    // ── BLUMO 감시 ─────────────────────────────────────────────────────────

    if ($action === 'monitor_toggle') {
        if ($siteId <= 0) throw new Exception('사이트를 선택하세요.');
        $st = $pdo->prepare("SELECT * FROM lab_sites WHERE id=?");
        $st->execute([$siteId]);
        $old = $st->fetch();
        if (!$old) throw new Exception('사이트를 찾을 수 없습니다.');
        $on = !empty($in['on']) ? 1 : 0;
        if ((int)$old['monitor'] !== $on) {
            $pdo->prepare("UPDATE lab_sites SET monitor=?, updated_at=NOW(), updated_by=? WHERE id=?")->execute([$on, $me['email'], $siteId]);
            sitehub_audit($pdo, $me, $siteId, 'update', 'site', $siteId, (string)$old['name'],
                sitehub_audit_diff($pdo, [['key' => 'monitor', 'label' => 'BLUMO 감시', 'type' => 'bool']], $old, ['monitor' => $on]));
        }
        // 켤 때 감시 주소가 하나도 없으면 운영 URL 로 하나 만든다 — 켜자마자 BLUMO 가 볼 게 있게
        $made = null;
        $cnt = $pdo->prepare("SELECT COUNT(*) FROM lab_site_monitors WHERE site_id=?");
        $cnt->execute([$siteId]);
        if ($on && !(int)$cnt->fetchColumn() && trim((string)$old['url_ops']) !== '') {
            $url = sitehub_monitor_url((string)$old['url_ops']);
            $pdo->prepare("INSERT INTO lab_site_monitors (site_id, label, url, expect, health, sort_no, created_at, updated_at, updated_by)
                           VALUES (?, '', ?, '', '', 1, NOW(), NOW(), ?)")->execute([$siteId, $url, $me['email']]);
            $made = (int)$pdo->lastInsertId();
            sitehub_audit($pdo, $me, $siteId, 'create', 'monitors', $made, sitehub_monitor_host($url), [], '감시를 켜면서 운영 URL 로 만듦');
        }
        sitehub_out(['ok' => true, 'made' => $made, 'site' => sitehub_load_site($pdo, $siteId), 'meta' => sitehub_meta($pdo, $me)]);
    }

    if ($action === 'monitor_probe') {
        sitehub_session_release();
        sitehub_out(['ok' => true] + blumo_probe_url($pdo, (string)($in['url'] ?? '')));
    }

    if ($action === 'monitor_check') {
        sitehub_session_release();
        $t = blumo_targets($pdo, (int)($in['id'] ?? 0))[0] ?? null;
        if (!$t) throw new Exception('감시 주소가 없습니다.');
        @set_time_limit(120);
        $r = blumo_check($pdo, $t, blumo_cfg($pdo));
        sitehub_out(['ok' => true, 'class' => $r['class'], 'cause' => $r['cause'], 'code' => $r['code'], 'total_ms' => $r['total_ms'],
                     'site' => sitehub_load_site($pdo, $t['site_id'])]);
    }

    if ($action === 'monitor_run_all') {
        if (blumo_lock_info()) throw new Exception('이미 전체 점검이 진행 중입니다.');
        $r = blumo_spawn_run();
        if ($r !== true) throw new Exception($r);
        sitehub_out(['ok' => true, 'running' => blumo_lock_info()]);
    }

    if ($action === 'monitor_enable' || $action === 'monitor_mute') {
        $id = (int)($in['id'] ?? 0);
        $st = $pdo->prepare("SELECT * FROM lab_site_monitors WHERE id=?");
        $st->execute([$id]);
        $old = $st->fetch();
        if (!$old) throw new Exception('감시 주소가 없습니다.');
        $sid = (int)$old['site_id'];
        if ($action === 'monitor_enable') {
            $on = !empty($in['on']) ? 1 : 0;
            if ((int)$old['enabled'] !== $on) {
                $pdo->prepare("UPDATE lab_site_monitors SET enabled=?, updated_at=NOW(), updated_by=? WHERE id=?")->execute([$on, $me['email'], $id]);
                sitehub_audit($pdo, $me, $sid, 'update', 'monitors', $id, sitehub_row_label('monitors', $old),
                    [['field' => 'enabled', 'label' => '자동 점검', 'old' => $on ? '멈춤' : '점검', 'new' => $on ? '점검' : '멈춤', 'secret' => 0]]);
            }
        } else {
            $min = max(0, min(60 * 24 * 30, (int)($in['min'] ?? 0)));
            $until = $min ? date('Y-m-d H:i:s', time() + $min * 60) : null;
            $pdo->prepare("UPDATE lab_site_monitors SET mute_until=? WHERE id=?")->execute([$until, $id]);
            sitehub_audit($pdo, $me, $sid, 'update', 'monitors', $id, sitehub_row_label('monitors', $old),
                [['field' => 'mute_until', 'label' => '알림 무음', 'old' => (string)($old['mute_until'] && strtotime($old['mute_until']) > time() ? substr($old['mute_until'], 0, 16) . '까지' : ''),
                  'new' => $until ? substr($until, 0, 16) . '까지' : '', 'secret' => 0]]);
        }
        sitehub_touch($pdo, $sid, $me);
        sitehub_out(['ok' => true, 'site' => sitehub_load_site($pdo, $sid)]);
    }

    // ── BLUMO 설정 · 예전 BLUMO 가져오기 — 지정 계정만 ────────────────────────────
    if (in_array($action, ['monitor_settings_save', 'monitor_pause', 'monitor_test_webhook', 'monitor_doctor', 'monitor_legacy_import'], true)) {
        if (!sitehub_can_stats($me)) { http_response_code(403); sitehub_out(['ok' => false, 'error' => 'BLUMO 설정은 지정된 계정만 바꿀 수 있습니다.']); }
        sitehub_session_release();
        if ($action === 'monitor_settings_save') {
            $u = trim((string)($in['webhook_url'] ?? ''));
            if ($u !== '' && !preg_match('#^https://#', $u)) throw new Exception('웹훅 주소는 https:// 로 시작해야 합니다.');
            blumo_meta_set($pdo, 'webhook_url', $u === '' ? '' : sitehub_enc($u));
            blumo_meta_set($pdo, 'webhook_format', in_array($in['webhook_format'] ?? '', ['slack', 'jandi', 'raw'], true) ? $in['webhook_format'] : 'slack');
            foreach (BLUMO_RANGES as $k => [$lo, $hi]) {
                if (isset($in[$k]) && $in[$k] !== '') blumo_meta_set($pdo, $k, max($lo, min($hi, (int)$in[$k])));
            }
            blumo_meta_set($pdo, 'alert_on_warn', !empty($in['alert_on_warn']) ? 1 : 0);
            sitehub_out(['ok' => true, 'meta' => sitehub_blumo_meta($pdo)]);
        }
        if ($action === 'monitor_pause') {
            blumo_meta_set($pdo, 'paused', !empty($in['on']) ? '1' : '0');
            sitehub_out(['ok' => true, 'meta' => sitehub_blumo_meta($pdo)]);
        }
        if ($action === 'monitor_test_webhook') {
            $r = blumo_test_webhook($pdo);
            if ($r !== true) throw new Exception($r);
            sitehub_out(['ok' => true]);
        }
        if ($action === 'monitor_doctor') {
            @set_time_limit(90);
            sitehub_out(['ok' => true, 'lines' => blumo_doctor($pdo)]);
        }
        if ($action === 'monitor_legacy_import') {
            $path = trim((string)($in['path'] ?? '')) ?: blumo_legacy_path();
            sitehub_out(['ok' => true, 'result' => blumo_legacy_import($pdo, $path, !empty($in['settings']), !empty($in['history'])),
                         'meta' => sitehub_blumo_meta($pdo)]);
        }
    }

    if ($action === 'blumo_import') {
        if (!sitehub_can_stats($me)) { http_response_code(403); sitehub_out(['ok' => false, 'error' => 'BLUMO 연동은 지정된 계정만 할 수 있습니다.']); }
        $plan = [];
        foreach (sitehub_blumo_seed_plan($pdo) as $p) $plan[$p['blumo_id']] = $p;
        $ins = $pdo->prepare("INSERT INTO lab_site_monitors (site_id, label, url, expect, health, note, blumo_id, sort_no, created_at, updated_at, updated_by)
                              VALUES (?, ?, ?, ?, '', ?, ?, ?, NOW(), NOW(), ?)");
        $siteOk = $pdo->prepare("SELECT name, monitor FROM lab_sites WHERE id=?");
        $nextSort = $pdo->prepare("SELECT COALESCE(MAX(sort_no),0)+1 FROM lab_site_monitors WHERE site_id=?");
        $made = 0; $touched = [];
        $pdo->beginTransaction();
        foreach ((array)($in['items'] ?? []) as $it) {
            $bid = (int)($it['blumo_id'] ?? 0);
            $sid = (int)($it['site_id'] ?? 0);
            $p = $plan[$bid] ?? null;
            if (!$p || $p['linked'] || $sid <= 0) continue;
            $siteOk->execute([$sid]);
            $site = $siteOk->fetch();
            if (!$site) continue;
            $nextSort->execute([$sid]);
            $ins->execute([$sid, $p['label'], sitehub_monitor_url($p['url']), $p['expect'], $p['memo'] !== '' ? $p['memo'] : null, $bid, (int)$nextSort->fetchColumn(), $me['email']]);
            $mid = (int)$pdo->lastInsertId();
            sitehub_audit($pdo, $me, $sid, 'create', 'monitors', $mid, sitehub_row_label('monitors', ['label' => $p['label'], 'url' => $p['url']]), [],
                          'BLUMO 에 있던 대상 연결 — ' . $p['name']);
            if (!(int)$site['monitor']) {
                $pdo->prepare("UPDATE lab_sites SET monitor=1 WHERE id=?")->execute([$sid]);
                sitehub_audit($pdo, $me, $sid, 'update', 'site', $sid, (string)$site['name'],
                    [['field' => 'monitor', 'label' => 'BLUMO 감시', 'old' => '아니오', 'new' => '예', 'secret' => 0]], 'BLUMO 대상 연결');
            }
            $touched[$sid] = 1;
            $made++;
        }
        foreach (array_keys($touched) as $sid) sitehub_touch($pdo, $sid, $me);
        $pdo->commit();
        sitehub_out(['ok' => true, 'made' => $made, 'sites' => sitehub_load_sites($pdo, array_keys($touched)), 'seed' => sitehub_blumo_seed_plan($pdo)]);
    }

    // ── 하위 테이블(접속정보 · 담당자 · 계약 · 감시 주소) ────────────────────────

    if ($action === 'sub_save' || $action === 'sub_delete' || $action === 'sub_sort') {
        $table = (string)($in['table'] ?? '');
        $def   = sitehub_sub_fields($table);
        if (!$def) throw new Exception('알 수 없는 항목입니다: ' . $table);
        $tbl = $def['table'];   // 화이트리스트를 거친 이름만 들어온다

        if ($action === 'sub_delete') {
            $id = (int)($in['id'] ?? 0);
            if ($id <= 0) throw new Exception('잘못된 id');
            $st = $pdo->prepare("SELECT * FROM `{$tbl}` WHERE id=?");
            $st->execute([$id]);
            $row = $st->fetch();
            $sid = (int)($row['site_id'] ?? 0);
            if ($sid <= 0) throw new Exception('이미 삭제된 항목입니다.');
            $pdo->prepare("DELETE FROM `{$tbl}` WHERE id=?")->execute([$id]);
            if ($table === 'monitors') $pdo->prepare("DELETE FROM lab_monitor_state WHERE monitor_id=?")->execute([$id]);   // 점검 기록·장애 이력은 남긴다
            sitehub_audit($pdo, $me, $sid, 'delete', $table, $id, sitehub_row_label($table, $row));
            sitehub_touch($pdo, $sid, $me);
            sitehub_out(['ok' => true, 'site' => sitehub_load_site($pdo, $sid)]);
        }

        if ($siteId <= 0) throw new Exception('사이트를 선택하세요.');
        $st = $pdo->prepare("SELECT id FROM lab_sites WHERE id=?");
        $st->execute([$siteId]);
        if (!$st->fetchColumn()) throw new Exception('없는 사이트입니다. (id=' . $siteId . ')');

        if ($action === 'sub_sort') {
            $ids = array_values(array_filter(array_map('intval', (array)($in['ids'] ?? []))));
            $st  = $pdo->prepare("UPDATE `{$tbl}` SET sort_no=? WHERE id=? AND site_id=?");
            $pdo->beginTransaction();
            foreach ($ids as $i => $id) $st->execute([$i + 1, $id, $siteId]);
            $pdo->commit();
            sitehub_out(['ok' => true, 'site' => sitehub_load_site($pdo, $siteId)]);
        }

        $id   = (int)($in['id'] ?? 0);
        $vals = [];
        foreach ($def['fields'] as $f) {
            if ($id > 0 && !array_key_exists($f['key'], $in)) continue;   // 수정은 보낸 칸만
            $vals[$f['key']] = sitehub_normalize($f, $in[$f['key']] ?? '');
        }
        // 만료일은 호스팅·도메인·SSL 만 — 다른 종류로 바꾸면 비운다
        if ($table === 'accounts' && array_key_exists('kind', $vals) && !in_array($vals['kind'], SITEHUB_EXPIRY_KINDS, true)) {
            $vals['expires_on'] = null;
        }
        // 비밀번호·에이전트 키처럼 가린 칸을 비워 보낸 수정은 "그대로 두기"다 — 빈칸이 지우기를 뜻하면 실수로 날아간다
        foreach ($def['fields'] as $f) {
            if ($id > 0 && ($f['type'] ?? '') === 'pw' && array_key_exists($f['key'], $vals) && $vals[$f['key']] === '' && empty($in['clear_pw'])) unset($vals[$f['key']]);
        }
        if ($table === 'monitors') {
            foreach (['url', 'health'] as $c) if (array_key_exists($c, $vals) && $vals[$c] !== '') $vals[$c] = sitehub_monitor_url($vals[$c]);
        }
        if ($id > 0) {
            if (!$vals) throw new Exception('고칠 내용이 없습니다.');
            $st = $pdo->prepare("SELECT * FROM `{$tbl}` WHERE id=? AND site_id=?");
            $st->execute([$id, $siteId]);
            $old = $st->fetch();
            if (!$old) throw new Exception('항목을 찾을 수 없습니다.');
            $diff = sitehub_audit_diff($pdo, $def['fields'], $old, $vals);
            if ($diff) {   // 바뀐 게 없으면(그대로 저장) 수정일을 안 올린다
                $set = implode(',', array_map(fn($c) => "`$c`=?", array_keys($vals)));
                $pdo->prepare("UPDATE `{$tbl}` SET {$set}, updated_at=NOW(), updated_by=? WHERE id=? AND site_id=?")
                    ->execute(array_merge(array_values($vals), [$me['email'], $id, $siteId]));
                sitehub_audit($pdo, $me, $siteId, 'update', $table, $id, sitehub_row_label($table, $vals + $old), $diff);
                sitehub_touch($pdo, $siteId, $me);
            }
        } else {
            $hasSort = !in_array($table, ['contracts', 'costs'], true);   // 계약은 종료일 순, 비용은 결제일 순으로 보여 주므로 sort_no 가 없다
            $max  = $hasSort ? (int)$pdo->query("SELECT COALESCE(MAX(sort_no),0) FROM `{$tbl}` WHERE site_id=" . $siteId)->fetchColumn() : 0;
            $cols = array_keys($vals);
            $sel  = implode(',', array_map(fn($c) => "`$c`", $cols));
            $ph   = implode(',', array_fill(0, count($cols), '?'));
            $pdo->prepare("INSERT INTO `{$tbl}` (site_id, {$sel}" . ($hasSort ? ', sort_no' : '') . ", created_at, updated_at, updated_by)
                           VALUES (?, {$ph}" . ($hasSort ? ', ?' : '') . ", NOW(), NOW(), ?)")
                ->execute(array_merge([$siteId], array_values($vals), $hasSort ? [$max + 1] : [], [$me['email']]));
            $id = (int)$pdo->lastInsertId();
            sitehub_audit($pdo, $me, $siteId, 'create', $table, $id, sitehub_row_label($table, $vals));
            sitehub_touch($pdo, $siteId, $me);
        }
        sitehub_out(['ok' => true, 'id' => $id, 'site' => sitehub_load_site($pdo, $siteId)]);
    }

    // ── 처리 절차 (공통 site_id=0 · 사이트별) ───────────────────────────────

    if (in_array($action, ['proc_save', 'proc_delete', 'proc_sort', 'proc_copy', 'proc_use', 'proc_unuse', 'proc_suggest_register', 'proc_suggest_ignore'], true)) {
        $fields = sitehub_procedure_fields();
        $procOut = fn(array $extra = []) => sitehub_out(['ok' => true, 'procedures' => sitehub_load_procedures($pdo)] + $extra);
        $siteExists = function (int $sid) use ($pdo) {
            if ($sid === 0) return;
            $st = $pdo->prepare("SELECT id FROM lab_sites WHERE id=?");
            $st->execute([$sid]);
            if (!$st->fetchColumn()) throw new Exception('없는 사이트입니다. (id=' . $sid . ')');
        };
        $getProc = function (int $id) use ($pdo) {
            $st = $pdo->prepare("SELECT * FROM lab_procedures WHERE id=?");
            $st->execute([$id]);
            $row = $st->fetch();
            if (!$row) throw new Exception('절차를 찾을 수 없습니다.');
            return $row;
        };
        $audit = function (string $act, array $row, array $diff = []) use ($pdo, $me) {
            if ((int)$row['site_id'] <= 0) return;   // 공통 절차는 사이트 변경 이력에 안 남긴다
            sitehub_audit($pdo, $me, (int)$row['site_id'], $act, 'procedures', (int)$row['id'], sitehub_row_label('procedures', $row), $diff);
            sitehub_touch($pdo, (int)$row['site_id'], $me);
        };

        if ($action === 'proc_suggest_ignore') {
            $kw = trim((string)($in['keyword'] ?? ''));
            if ($kw === '') throw new Exception('키워드가 없습니다.');
            $pdo->prepare("INSERT IGNORE INTO lab_procedure_ignores (keyword, ignored_by, ignored_at) VALUES (?,?,NOW())")->execute([$kw, $me['email']]);
            $procOut();
        }

        // 제안 등록 — 묶음은 서버가 다시 계산한다(화면이 보낸 id 를 믿지 않는다). 절차를 만들고 그 묶음의 이력을 전부 잇는다
        if ($action === 'proc_suggest_register') {
            require_once __DIR__ . '/proc_suggest.php';
            $kw = trim((string)($in['keyword'] ?? ''));
            $group = null;
            foreach (sitehub_proc_suggestions($pdo, (int)($in['scope_site'] ?? 0) ?: null) as $g) if ($g['keyword'] === $kw) { $group = $g; break; }
            if (!$group) throw new Exception('이미 처리됐거나 사라진 제안입니다. 새로고침 후 다시 확인하세요.');
            $sid = (int)($in['site_id'] ?? 0);
            if ($sid > 0) {   // 사이트 전용은 묶음의 이력이 전부 그 사이트일 때만 — 아니면 공통으로
                $ph = implode(',', array_fill(0, count($group['ids']), '?'));
                $q = $pdo->prepare("SELECT COUNT(*) FROM lab_history WHERE id IN ({$ph}) AND (site_id IS NULL OR site_id <> ?)");
                $q->execute(array_merge($group['ids'], [$sid]));
                if ((int)$q->fetchColumn() > 0) $sid = 0;
            }
            $siteExists($sid);
            $vals = [];
            foreach ($fields as $f) $vals[$f['key']] = sitehub_normalize($f, $in[$f['key']] ?? ($f['key'] === 'category' ? 'etc' : ''));
            $max = $pdo->prepare("SELECT COALESCE(MAX(sort_no),0) FROM lab_procedures WHERE site_id=?");
            $max->execute([$sid]);
            $cols = array_keys($vals);
            $pdo->beginTransaction();
            $pdo->prepare("INSERT INTO lab_procedures (site_id, " . implode(',', array_map(fn($c) => "`$c`", $cols)) . ", sort_no, created_at, updated_at, updated_by)
                           VALUES (?, " . implode(',', array_fill(0, count($cols), '?')) . ", ?, NOW(), NOW(), ?)")
                ->execute(array_merge([$sid], array_values($vals), [(int)$max->fetchColumn() + 1, $me['email']]));
            $newId = (int)$pdo->lastInsertId();
            $ins = $pdo->prepare("INSERT IGNORE INTO lab_procedure_uses (procedure_id, history_id, site_id, used_by, used_at) VALUES (?,?,?,?,NOW())");
            $siteOf = $pdo->prepare("SELECT site_id FROM lab_history WHERE id=?");
            foreach ($group['ids'] as $hid) {
                $siteOf->execute([$hid]);
                $hs = $siteOf->fetchColumn();
                $ins->execute([$newId, $hid, $hs === false || $hs === null ? null : (int)$hs, $me['email']]);
            }
            $pdo->commit();
            $audit('create', ['id' => $newId, 'site_id' => $sid] + $vals);
            $procOut(['id' => $newId, 'linked' => count($group['ids'])]);
        }

        if ($action === 'proc_save') {
            $id = (int)($in['id'] ?? 0);
            $vals = [];
            foreach ($fields as $f) {
                if ($id > 0 && !array_key_exists($f['key'], $in)) continue;
                $vals[$f['key']] = sitehub_normalize($f, $in[$f['key']] ?? ($f['key'] === 'category' ? 'etc' : ''));
            }
            if ($id > 0) {
                $old = $getProc($id);
                $diff = sitehub_audit_diff($pdo, $fields, $old, $vals);
                if ($diff) {
                    $set = implode(',', array_map(fn($c) => "`$c`=?", array_keys($vals)));
                    $pdo->prepare("UPDATE lab_procedures SET {$set}, updated_at=NOW(), updated_by=? WHERE id=?")
                        ->execute(array_merge(array_values($vals), [$me['email'], $id]));
                    $audit('update', $vals + $old, $diff);
                }
            } else {
                $sid = (int)($in['site_id'] ?? 0);
                $siteExists($sid);
                $max = $pdo->prepare("SELECT COALESCE(MAX(sort_no),0) FROM lab_procedures WHERE site_id=?");
                $max->execute([$sid]);
                $cols = array_keys($vals);
                $pdo->prepare("INSERT INTO lab_procedures (site_id, " . implode(',', array_map(fn($c) => "`$c`", $cols)) . ", sort_no, created_at, updated_at, updated_by)
                               VALUES (?, " . implode(',', array_fill(0, count($cols), '?')) . ", ?, NOW(), NOW(), ?)")
                    ->execute(array_merge([$sid], array_values($vals), [(int)$max->fetchColumn() + 1, $me['email']]));
                $id = (int)$pdo->lastInsertId();
                $audit('create', ['id' => $id, 'site_id' => $sid] + $vals);
            }
            $procOut(['id' => $id]);
        }

        if ($action === 'proc_delete') {
            $row = $getProc((int)($in['id'] ?? 0));
            $pdo->prepare("DELETE FROM lab_procedures WHERE id=?")->execute([$row['id']]);
            $pdo->prepare("DELETE FROM lab_procedure_uses WHERE procedure_id=?")->execute([$row['id']]);
            $audit('delete', $row);
            $procOut();
        }

        if ($action === 'proc_sort') {
            $ids = array_values(array_filter(array_map('intval', (array)($in['ids'] ?? []))));
            $st = $pdo->prepare("UPDATE lab_procedures SET sort_no=? WHERE id=? AND site_id=?");
            $pdo->beginTransaction();
            foreach ($ids as $i => $pid) $st->execute([$i + 1, $pid, $siteId]);
            $pdo->commit();
            $procOut();
        }

        if ($action === 'proc_copy') {
            $src = $getProc((int)($in['id'] ?? 0));
            if ($siteId <= 0) throw new Exception('복사할 사이트를 선택하세요.');
            $siteExists($siteId);
            $max = $pdo->prepare("SELECT COALESCE(MAX(sort_no),0) FROM lab_procedures WHERE site_id=?");
            $max->execute([$siteId]);
            $pdo->prepare("INSERT INTO lab_procedures (site_id, category, title, steps, duration, caution, link_url, sort_no, created_at, updated_at, updated_by)
                           VALUES (?,?,?,?,?,?,?,?,NOW(),NOW(),?)")
                ->execute([$siteId, $src['category'], $src['title'], $src['steps'], $src['duration'], $src['caution'], $src['link_url'], (int)$max->fetchColumn() + 1, $me['email']]);
            $newId = (int)$pdo->lastInsertId();
            $audit('create', ['id' => $newId, 'site_id' => $siteId, 'title' => $src['title']]);
            $procOut(['id' => $newId]);
        }

        // proc_use / proc_unuse — 이력 한 건과 절차를 잇거나 끊는다
        $pid = (int)($in['procedure_id'] ?? 0);
        $hid = (string)($in['history_id'] ?? '');
        $getProc($pid);
        $st = $pdo->prepare("SELECT site_id FROM lab_history WHERE id=?");
        $st->execute([$hid]);
        $h = $st->fetch();
        if (!$h) throw new Exception('이력을 찾을 수 없습니다.');
        if ($action === 'proc_use') {
            $pdo->prepare("INSERT IGNORE INTO lab_procedure_uses (procedure_id, history_id, site_id, used_by, used_at) VALUES (?,?,?,?,NOW())")
                ->execute([$pid, $hid, $h['site_id'] === null ? null : (int)$h['site_id'], $me['email']]);
        } else {
            $pdo->prepare("DELETE FROM lab_procedure_uses WHERE procedure_id=? AND history_id=?")->execute([$pid, $hid]);
        }
        $st = $pdo->prepare("SELECT procedure_id FROM lab_procedure_uses WHERE history_id=?");
        $st->execute([$hid]);
        $procOut(['history_id' => $hid, 'procs' => array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN))]);
    }

    // ── 이력 ────────────────────────────────────────────────────────────────

    // 미매칭 자동 정리 — apply 없으면 계획(미리보기)만, apply=1 이면 exclude 로 뺀 표기만 빼고 적용 (규칙은 match_lib.php)
    if ($action === 'auto_resolve') {
        require_once __DIR__ . '/match_lib.php';
        if (empty($in['apply'])) sitehub_out(['ok' => true, 'plan' => sitehub_auto_resolve_plan($pdo)]);
        $exclude = array_values(array_filter((array)($in['exclude'] ?? []), 'is_string'));
        $res = sitehub_auto_resolve_apply($pdo, $exclude, $me);
        sitehub_out(['ok' => true, 'result' => $res, 'meta' => sitehub_meta($pdo, $me)]);
    }

    if ($action === 'history_assign') {
        $hid = (string)($in['id'] ?? '');
        if ($hid === '') throw new Exception('잘못된 이력 id');
        if ($siteId > 0) {
            $st = $pdo->prepare("SELECT id FROM lab_sites WHERE id=?");
            $st->execute([$siteId]);
            if (!$st->fetchColumn()) throw new Exception('없는 사이트입니다.');
            $pdo->prepare("UPDATE lab_history SET site_id=?, site_locked=1 WHERE id=?")->execute([$siteId, $hid]);
        } else {
            // 지정 해제 — 다음 동기화 때 자동 매칭을 다시 받는다
            $pdo->prepare("UPDATE lab_history SET site_id=NULL, site_locked=0 WHERE id=?")->execute([$hid]);
        }
        sitehub_children_follow($pdo);   // 그 요청의 하위 항목도 같이
        sitehub_out(['ok' => true, 'meta' => sitehub_meta($pdo, $me), 'site' => $siteId > 0 ? sitehub_load_site($pdo, $siteId) : null]);
    }

    if (in_array($action, ['history_add', 'history_update', 'history_delete'], true)) {
        require_once __DIR__ . '/history_write.php';
        $hid = (string)($in['id'] ?? '');
        if ($action === 'history_add') {
            if ($siteId <= 0) throw new Exception('사이트를 선택하세요.');
            sitehub_history_add($pdo, $me, $siteId, $in);
        } elseif ($action === 'history_update') {
            if ($hid === '') throw new Exception('잘못된 이력 id');
            sitehub_history_update($pdo, $me, $hid, $in);
            $siteId = (int)sitehub_hw_row($pdo, $hid)['site_id'];
        } else {
            if ($hid === '') throw new Exception('잘못된 이력 id');
            $siteId = (int)sitehub_history_delete($pdo, $me, $hid);
        }
        sitehub_out(['ok' => true, 'rows' => $siteId > 0 ? sitehub_history_rows($pdo, 'site_id = ? AND archived = 0', [$siteId]) : [],
                     'meta' => sitehub_meta($pdo, $me), 'site' => $siteId > 0 ? sitehub_load_site($pdo, $siteId) : null]);
    }

    throw new Exception('알 수 없는 action');
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    sitehub_out(['ok' => false, 'error' => $e->getMessage()]);
}

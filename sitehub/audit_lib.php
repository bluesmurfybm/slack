<?php
/**
 * 변경 기록 — 누가 언제 무엇을 바꿨나. db.php 가 include 하고, api.php 의 쓰기 동작과 미매칭 자동 정리가 부른다.
 *
 *  - lab_site_changes 에 칸 하나 = 행 하나로 남긴다(무엇이 무엇으로). 만들기·삭제·보관·복원은 칸 없이 한 행.
 *  - 비밀번호는 값을 남기지 않는다 — 바뀌었는지만('(변경됨)'). 비교는 복호화한 평문끼리(암호문은 IV 가 매번 달라 늘 다르다).
 *  - 수정자는 각 행의 updated_by 에도 적는다: portal_users.email, 엑셀 가져오기는 'import:xlsx', 자동 정리 등록은 이메일 그대로.
 *    접속정보·담당자·계약을 고쳐도 사이트(lab_sites)의 updated_at/updated_by 를 같이 올려 목록의 '최근 수정'이 맞게 한다.
 */

/** 칸 값을 사람이 읽는 글로 — select 는 라벨, bool 은 예/아니오, 금액은 쉼표, 담당자는 이름. 비밀번호는 null */
function sitehub_audit_text(PDO $pdo, array $f, $v): ?string {
    if ($v === null) return '';
    switch ($f['type'] ?? 'input') {
        case 'pw':     return null;
        case 'bool':   return (int)$v ? '예' : '아니오';
        case 'select': return (string)($f['options'][(string)$v] ?? $v);
        case 'number': return $v === '' ? '' : number_format((int)$v);
        case 'staff':
            $emails = sitehub_split_list($v);
            if (!$emails) return '';
            $st = $pdo->prepare("SELECT email, name FROM portal_users WHERE email IN (" . implode(',', array_fill(0, count($emails), '?')) . ")");
            $st->execute($emails);
            $names = array_column($st->fetchAll(), 'name', 'email');
            return implode(', ', array_map(fn($e) => $names[$e] ?? $e, $emails));
        default:       return (string)$v;
    }
}

/** 사람이 읽는 글 기준으로 같은 값인가 — 빈 값('', '—', null)은 모두 같다. $ci 면 대소문자·띄어쓰기도 무시(칸 정의의 'ci') */
function sitehub_audit_same_text(?string $a, ?string $b, bool $ci = false): bool {
    $norm = fn(?string $v) => ($t = trim((string)$v)) === '—' ? '' : ($ci ? preg_replace('/\s+/u', '', mb_strtolower($t)) : $t);
    return $norm($a) === $norm($b);
}

/** 칸 정의 목록으로 옛 행과 새 값을 견준다 → [['field','label','old','new','secret'], …] (바뀐 칸만) */
function sitehub_audit_diff(PDO $pdo, array $fields, array $old, array $new): array {
    $out = [];
    foreach ($fields as $f) {
        $k = $f['key'];
        if (!array_key_exists($k, $new)) continue;
        $o = $old[$k] ?? null;
        $n = $new[$k];
        if (($f['type'] ?? '') === 'pw') {
            if (sitehub_dec((string)$o) !== sitehub_dec((string)$n)) $out[] = ['field' => $k, 'label' => $f['label'], 'old' => null, 'new' => null, 'secret' => 1];
            continue;
        }
        if ((string)($o ?? '') === (string)($n ?? '')) continue;
        $ot = sitehub_audit_text($pdo, $f, $o);
        $nt = sitehub_audit_text($pdo, $f, $n);
        if (sitehub_audit_same_text($ot, $nt, !empty($f['ci']))) continue;   // 빈 값끼리·읽는 글이 같은 변경은 기록하지 않는다
        $out[] = ['field' => $k, 'label' => $f['label'], 'old' => $ot, 'new' => $nt, 'secret' => 0];
    }
    return $out;
}

/** 기록 남기기. $changes 가 비면(만들기·삭제·보관 등) 한 행, 있으면 칸마다 한 행 */
function sitehub_audit(PDO $pdo, ?array $me, int $siteId, string $action, string $target, ?int $rowId, string $rowLabel,
                       array $changes = [], string $note = ''): void {
    $st = $pdo->prepare("INSERT INTO lab_site_changes (site_id, at, user_email, user_name, action, target, row_id, row_label, field, field_label, old_value, new_value, note)
                         VALUES (?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $email = (string)($me['email'] ?? 'system');
    $name  = (string)($me['name'] ?? '');
    $label = mb_substr($rowLabel, 0, 200);
    $note  = $note === '' ? null : mb_substr($note, 0, 500);
    if (!$changes) { $st->execute([$siteId, $email, $name, $action, $target, $rowId, $label, null, null, null, null, $note]); return; }
    foreach ($changes as $c) {
        $st->execute([$siteId, $email, $name, $action, $target, $rowId, $label, $c['field'], $c['label'],
                      $c['secret'] ? null : $c['old'], $c['secret'] ? '(변경됨)' : $c['new'], $note]);
    }
}

/** 사이트의 '최근 수정'을 올린다 — 하위 행(접속정보·담당자·계약)을 고쳤을 때도 */
function sitehub_touch(PDO $pdo, int $siteId, ?array $me): void {
    $pdo->prepare("UPDATE lab_sites SET updated_at = NOW(), updated_by = ? WHERE id = ?")->execute([(string)($me['email'] ?? 'system'), $siteId]);
}

/** 하위 행을 사람이 알아볼 이름으로 — 접속정보는 라벨(없으면 종류), 담당자는 이름, 계약은 형태+기간 */
function sitehub_row_label(string $table, array $r): string {
    if ($table === 'accounts') return trim((string)($r['label'] ?? '')) !== '' ? (string)$r['label'] : (sitehub_account_kinds()[$r['kind'] ?? ''] ?? (string)($r['kind'] ?? ''));
    if ($table === 'contacts') return (string)($r['name'] ?? '');
    if ($table === 'costs') return trim(($r['paid_on'] ?: '?') . ' ' . (string)($r['item'] ?? ''));
    if ($table === 'procedures') return (string)($r['title'] ?? '');
    if ($table === 'monitors') return trim((string)($r['label'] ?? '')) !== '' ? (string)$r['label'] . ' · ' . sitehub_monitor_host((string)($r['url'] ?? '')) : sitehub_monitor_host((string)($r['url'] ?? ''));
    if ($table === 'contracts') return (sitehub_contract_kinds()[$r['kind'] ?? ''] ?? '') . ' ' . ($r['start_date'] ?: '?') . ' ~ ' . ($r['end_date'] ?: '?');
    return (string)($r['name'] ?? '');
}

/** 사이트 하나의 기록 — 최근 것부터 */
function sitehub_changes(PDO $pdo, int $siteId, int $limit = 300): array {
    $st = $pdo->prepare("SELECT * FROM lab_site_changes WHERE site_id = ? ORDER BY at DESC, id DESC LIMIT " . max(1, min(1000, $limit)));
    $st->execute([$siteId]);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) { $r['id'] = (int)$r['id']; $r['row_id'] = $r['row_id'] === null ? null : (int)$r['row_id']; }
    unset($r);
    return $rows;
}

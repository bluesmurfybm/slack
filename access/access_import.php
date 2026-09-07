<?php
/**
 * 'SVN_배포_디비정보(블루내부공유).xlsx' → schools(마스터) + school_access(상세) 시딩.
 *
 *  CLI    : php access_import.php ["xlsx경로"] [--force]
 *  브라우저: access.php 의 [엑셀 가져오기] 에서 파일 업로드 (포털 로그인 필요)
 *
 *  동작
 *   1) 엑셀 A열(대학명)을 schools.name 과 대조한다(공백/줄바꿈 무시). 있으면 그 학교에 붙이고,
 *      없으면 schools 에 새로 만든다 — schools 가 마스터라 여기서 학교를 새로 만들 수는 있어도
 *      기존 학교의 이름/URL 을 엑셀 값으로 덮어쓰지는 않는다(관리 화면에서 손본 값 보호).
 *      단 URL 칸이 비어 있으면 엑셀에서 뽑은 URL 로 채워 준다.
 *   2) school_access 는 엑셀 기준으로 다시 만든다(--force 없이 기존 데이터가 있으면 중단).
 *
 *  ※ 이 엑셀에는 전 대학 계정/비밀번호가 그대로 들어 있다. 저장소(git)에 커밋하지 말 것.
 */
require_once __DIR__ . '/db.php';

$IS_CLI = (PHP_SAPI === 'cli');

// 시트 이름 → (그룹명, schools.ver 기본값, 컬럼 매핑). 시트마다 컬럼이 한두 칸씩 밀려 있다.
//  - '3.5 이하' : 유일하게 '무들 버전'(C)과 '기타'(O)가 있고 plink 칸이 없다
//  - '3.9-saas' : 유일하게 '운영 웹서버'(H)가 껴 있어 그 뒤가 한 칸씩 밀린다
//  - '그 외'     : '사업시작' 칸이 없어 B부터 한 칸씩 당겨진다
//  - login_*/vpn/vpn_note 는 엑셀에 칸이 없다 — 아래에서 본문을 보고 파생시킨다
//  - '_ver_cell' 은 저장용이 아니라 학교(schools.ver)를 짝지을 때만 쓰는 임시 값이다
$SHEETS = [
    '3.5 이하' => ['grp' => '3.5', 'ver' => null, 'map' => [
        'name'=>'A','opened'=>'B','_ver_cell'=>'C','repo'=>'D','dev_note'=>'E','ops_note'=>'F',
        'login_info'=>'G','dev_db'=>'H','ops_db'=>'I','haksa_db'=>'J',
        'note'=>'K','etc'=>'L','deploy'=>'M','deploy_acct'=>'N','extra'=>'O']],
    '3.9' => ['grp' => '3.9', 'ver' => '3.9', 'map' => [
        'name'=>'A','opened'=>'B','repo'=>'C','dev_note'=>'D','ops_note'=>'E','login_info'=>'F',
        'dev_db'=>'G','ops_db'=>'H','haksa_db'=>'I','plink'=>'J',
        'note'=>'K','etc'=>'L','deploy'=>'M','deploy_acct'=>'N']],
    '3.9-saas' => ['grp' => '3.9-saas', 'ver' => '3.9', 'map' => [
        'name'=>'A','opened'=>'B','repo'=>'C','dev_note'=>'D','ops_note'=>'E','login_info'=>'F',
        'dev_db'=>'G','ops_web'=>'H','ops_db'=>'I','haksa_db'=>'J','plink'=>'K',
        'note'=>'L','etc'=>'M','deploy'=>'N','deploy_acct'=>'O']],
    '4.5' => ['grp' => '4.5', 'ver' => '4.5', 'map' => [
        'name'=>'A','opened'=>'B','repo'=>'C','dev_note'=>'D','ops_note'=>'E','login_info'=>'F',
        'dev_db'=>'G','ops_db'=>'H','haksa_db'=>'I','plink'=>'J',
        'note'=>'K','etc'=>'L','deploy'=>'M','deploy_acct'=>'N']],
    '그 외' => ['grp' => '그 외', 'ver' => '', 'map' => [
        'name'=>'A','repo'=>'B','dev_note'=>'C','ops_note'=>'D','login_info'=>'E',
        'dev_db'=>'F','ops_db'=>'G','haksa_db'=>'H','plink'=>'I',
        'note'=>'J','etc'=>'K','deploy'=>'L','deploy_acct'=>'M']],
];

/** xlsx 의 sharedStrings — <si> 안에 <r> 런이 여러 개면 이어붙여야 원문 줄바꿈이 살아난다 */
function ax_shared_strings(ZipArchive $zip) {
    $out = [];
    $s = $zip->getFromName('xl/sharedStrings.xml');
    if ($s === false) return $out;
    $xml = simplexml_load_string($s);
    foreach ($xml->si as $si) {
        if (isset($si->t) && !isset($si->r)) { $out[] = (string)$si->t; continue; }
        $t = '';
        foreach ($si->r as $r) $t .= (string)$r->t;
        $out[] = $t;
    }
    return $out;
}

/** 시트 이름 → 'xl/worksheets/sheetN.xml'. 시트 순서가 바뀌어도 이름으로 찾도록 rels 를 탄다. */
function ax_sheet_targets(ZipArchive $zip) {
    $wb   = simplexml_load_string($zip->getFromName('xl/workbook.xml'));
    $rels = simplexml_load_string($zip->getFromName('xl/_rels/workbook.xml.rels'));
    $rmap = [];
    foreach ($rels->Relationship as $r) $rmap[(string)$r['Id']] = (string)$r['Target'];
    $out = [];
    foreach ($wb->sheets->sheet as $sh) {
        $rid = (string)$sh->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')->id;
        $tgt = ltrim($rmap[$rid] ?? '', '/');
        if ($tgt === '') continue;
        if (strpos($tgt, 'xl/') !== 0) $tgt = 'xl/' . $tgt;
        $out[(string)$sh['name']] = $tgt;
    }
    return $out;
}

/** 한 시트를 [행][컬럼문자] => 값 배열로 읽는다 */
function ax_read_sheet(ZipArchive $zip, array $shared, $file) {
    $x = simplexml_load_string($zip->getFromName($file));
    $rows = [];
    foreach ($x->sheetData->row as $row) {
        $c = [];
        foreach ($row->c as $cell) {
            $t = (string)$cell['t'];
            if ($t === 'inlineStr')   $val = (string)$cell->is->t;
            elseif ($t === 's')       $val = $shared[(int)(string)$cell->v] ?? '';
            else                      $val = (string)$cell->v;
            $c[preg_replace('/[0-9]/', '', (string)$cell['r'])] = $val;
        }
        $rows[] = $c;
    }
    return $rows;
}

/** 셀 값 정리 — 줄바꿈은 살리고 양끝 공백/줄끝 공백만 턴다 */
function ax_clean($v) {
    $v = str_replace(["\r\n", "\r"], "\n", (string)$v);
    $v = preg_replace('/[ \t]+\n/', "\n", $v);
    return trim($v);
}

/**
 * URL 칸 원문에서 실제 주소만 뽑는다(schools.dev/ops 를 채울 때만 사용).
 * slack/schools/schools_import.php 의 normUrl 과 같은 규칙 — 두 모듈이 같은 엑셀을 읽으므로
 * 결과가 달라지면 안 된다.
 */
function ax_norm_url($u) {
    $u = trim((string)$u);
    if ($u === '') return '';
    if (preg_match('~https?://[^\s]+~i', $u, $m)) return rtrim($m[0], " \t\r\n.,;");
    $tok = rtrim(preg_split('/\s+/', $u)[0], " \t\r\n.,;/");
    if ($tok === '' || !preg_match('~[a-z0-9]\.[a-z]~i', $tok)) return '';
    return (stripos($tok, 'moodler.kr') !== false ? 'http://' : 'https://') . $tok;
}

/** URL/호스트명에서 호스트만 뽑는다(schools.dev/ops 와 같은 주소인지 비교용) */
function ax_host($u) {
    $u = preg_replace('~^[a-z][a-z0-9+.-]*://~i', '', trim((string)$u));
    $u = preg_split('~[/?#\s]~', $u)[0];
    $u = strtolower(preg_replace('~^www\.~i', '', $u));
    return preg_match('~^[a-z0-9-]+(\.[a-z0-9-]+)+$~', $u) ? $u : '';
}

/**
 * 개발/운영 URL 칸 원문에서 "이미 schools 에 들어 있는 주소만 딱 적힌 줄"을 걷어낸다.
 *
 * 원문을 그대로 두면 dev_note 33건·ops_note 47건 중 절반 이상이 'cyber.gachon.ac.kr' 처럼
 * 마스터의 개발/운영 URL과 같은 주소를 한 번 더 적어 둔 것뿐이라 화면이 지저분해진다.
 * 반대로 '원격 접속 후 http://172.20.10.206', '개발서버는 블루에서만 접근 가능',
 * 여분의 도메인처럼 진짜 정보도 섞여 있어서 칸 자체를 없앨 수는 없다.
 * 그래서 '주소 하나만 있는 줄이고 그 호스트가 이미 마스터에 있는' 줄만 버린다.
 */
function ax_strip_known_urls($raw, array $knownHosts) {
    $keep = [];
    foreach (explode("
", (string)$raw) as $line) {
        $t = trim($line);
        if ($t === '') continue;
        // 토큰이 하나뿐인 줄 = 설명 없이 주소만 적힌 줄
        if (!preg_match('~\s~u', $t)) {
            $h = ax_host($t);
            if ($h !== '' && in_array($h, $knownHosts, true)) continue;
        }
        $keep[] = $t;
    }
    return implode("
", $keep);
}

/**
 * 엑셀을 읽어 schools 를 보강하고 school_access 를 다시 채운다.
 * @return array ['total'=>int, 'new_schools'=>int, 'per'=>[시트명=>건수]]
 */
function access_import_xlsx($path, array $SHEETS) {
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) throw new Exception("xlsx 를 열 수 없습니다: {$path}");

    $shared  = ax_shared_strings($zip);
    $targets = ax_sheet_targets($zip);
    $cols    = access_cols();

    $pdo = access_db();

    // 이름 → 후보 목록. schools 는 "같은 대학의 다른 버전"을 별도 행으로 갖는다
    // (강원대 3.2 / 강원대 4.5, 부산대 3.5 / 부산대 4.5 …). 이름만 보고 고르면 4.5 시트
    // 내용이 3.2 학교에 붙어 버리므로 버전까지 맞춰야 한다.
    $index = [];   // 정규화이름 => [['id'=>…, 'ver'=>…], …]  (id 오름차순)
    foreach ($pdo->query("SELECT id, name, ver FROM schools ORDER BY id") as $s) {
        $index[access_norm_name($s['name'])][] = ['id' => (int)$s['id'], 'ver' => (string)$s['ver']];
    }

    $insSchool = $pdo->prepare("INSERT INTO schools (name, ver, dev, ops, active, created_at, updated_at)
                                VALUES (?, ?, ?, ?, 1, NOW(), NOW())");
    // 관리 화면에서 손본 값을 지우지 않도록, 비어 있는 칸만 채운다
    $fillUrl   = $pdo->prepare("UPDATE schools SET dev = IF(dev='', ?, dev), ops = IF(ops='', ?, ops),
                                       updated_at = NOW() WHERE id=?");

    $sel = implode(',', array_map(fn($c) => "`$c`", $cols));
    $ph  = implode(',', array_fill(0, count($cols), '?'));
    $upd = implode(',', array_map(fn($c) => "`$c`=VALUES(`$c`)", $cols));
    $insAccess = $pdo->prepare("INSERT INTO school_access (school_id, {$sel}, sort_no, created_at, updated_at)
                                VALUES (?, {$ph}, ?, NOW(), NOW())
                                ON DUPLICATE KEY UPDATE {$upd}, sort_no=VALUES(sort_no), updated_at=NOW()");

    $pdo->exec("TRUNCATE TABLE school_access");   // DDL → 트랜잭션 밖(암묵적 커밋)

    $per = []; $total = 0; $newSchools = 0;
    $pdo->beginTransaction();
    foreach ($SHEETS as $sheetName => $def) {
        if (empty($targets[$sheetName])) { $per[$sheetName] = 0; continue; }
        $rows = ax_read_sheet($zip, $shared, $targets[$sheetName]);
        $n = 0;
        // 1·2행은 병합 헤더("세부 사항" / "대학 ( 기관 ) 명" …) — 3행부터 데이터
        for ($i = 2; $i < count($rows); $i++) {
            $name = ax_clean($rows[$i][$def['map']['name']] ?? '');
            if ($name === '') continue;   // 빈 줄/구분선

            $vals = ['grp' => $def['grp']];
            foreach ($cols as $c) {
                if ($c === 'grp') continue;
                $letter = $def['map'][$c] ?? null;
                $vals[$c] = $letter ? ax_clean($rows[$i][$letter] ?? '') : '';
            }
            // 저장하지 않고 버전 판별에만 쓰는 칸('3.5 이하' 시트의 '무들 버전')
            $verCell = isset($def['map']['_ver_cell']) ? ax_clean($rows[$i][$def['map']['_ver_cell']] ?? '') : '';

            $vals['repo'] = access_strip_repo_label($vals['repo']);   // "git : https://…" → 주소만

            // 엑셀에 칸이 없는 값들은 본문에서 파생시킨다(둘 다 화면에서 고칠 수 있는 초깃값)
            $lg = access_split_login($vals['login_info']);
            $op = access_split_account($lg['ops']);   // "csmsathena / 비번" → 계정과 비번을 따로
            $dv = access_split_account($lg['dev']);
            $vals['login_ops_id'] = $op['id'];
            $vals['login_ops']    = $op['pw'];
            $vals['login_dev_id'] = $dv['id'];
            $vals['login_dev']    = $dv['pw'];
            $vals['login_info']   = $lg['rest'];   // 운영/테스트로 못 가른 나머지만 남긴다

            // 4.5 는 사이트 계정이 csmsathena 로 통일돼 있다(다른 계정을 쓰는 곳이 없음).
            // 엑셀에 비밀번호만 적어 둔 칸이 많아서 계정을 채워 준다 — 이미 적혀 있으면 그대로 둔다.
            if ($def['ver'] === '4.5') {
                if ($vals['login_ops'] !== '' && $vals['login_ops_id'] === '') $vals['login_ops_id'] = 'csmsathena';
                if ($vals['login_dev'] !== '' && $vals['login_dev_id'] === '') $vals['login_dev_id'] = 'csmsathena';
            }
            $vp = access_detect_vpn($vals);
            $vals['vpn']      = $vp['vpn'];
            $vals['vpn_note'] = $vp['vpn_note'];

            // VPN 판정까지 끝난 뒤에 암호화한다(판정이 평문을 봐야 하므로 순서가 중요)
            foreach (access_secret_cols() as $c) $vals[$c] = access_enc($vals[$c]);

            $dev = ax_norm_url($vals['dev_note']);
            $ops = ax_norm_url($vals['ops_note']);
            // 마스터(schools.dev/ops)에 이미 있는 주소를 한 번 더 적어 둔 줄은 메모에서 뺀다.
            // 조건이 같이 적혔거나 여분의 도메인이 있는 줄만 남는다.
            $known = array_values(array_filter([ax_host($dev), ax_host($ops)]));
            $vals['dev_note'] = ax_strip_known_urls($vals['dev_note'], $known);
            $vals['ops_note'] = ax_strip_known_urls($vals['ops_note'], $known);

            // 이 행의 버전. '3.5 이하' 시트만 2.9/3.2/3.5 가 섞여 있어 무들 버전 칸에서
            // major.minor 를 뽑고(schools.ver 과 같은 규칙), 나머지 시트는 고정값이다.
            $ver = $def['ver'];
            if ($ver === null) {
                $ver = preg_match('/(\d+)\.(\d+)/', $verCell, $m) ? "{$m[1]}.{$m[2]}" : '3.5';
            }

            $key      = access_norm_name($name);
            $schoolId = 0;
            foreach ($index[$key] ?? [] as $cand) {          // 버전이 같은 학교 우선
                if ($ver !== '' && $cand['ver'] === $ver) { $schoolId = $cand['id']; break; }
            }
            if (!$schoolId && !empty($index[$key])) {        // 버전이 안 맞으면 같은 이름 첫 번째
                $schoolId = $index[$key][0]['id'];
            }

            if ($schoolId) {
                if ($dev !== '' || $ops !== '') $fillUrl->execute([$dev, $ops, $schoolId]);
            } else {
                $insSchool->execute([$name, $ver, $dev, $ops]);
                $schoolId = (int)$pdo->lastInsertId();
                $index[$key][] = ['id' => $schoolId, 'ver' => $ver];
                $newSchools++;
            }

            $insAccess->execute(array_merge([$schoolId], array_values($vals), [$n]));
            $n++; $total++;
        }
        $per[$sheetName] = $n;
    }
    $pdo->commit();
    $zip->close();

    return ['total' => $total, 'new_schools' => $newSchools, 'per' => $per];
}

/* ─────────────── CLI ─────────────── */
if ($IS_CLI) {
    $args  = array_values(array_filter($argv, fn($a) => $a !== '--force'));
    $force = in_array('--force', $argv, true);
    $path  = $args[1] ?? (__DIR__ . '/../SVN_배포_디비정보(블루내부공유).xlsx');

    $cnt = (int)access_db()->query("SELECT COUNT(*) FROM school_access")->fetchColumn();
    if ($cnt > 0 && !$force) {
        fwrite(STDERR, "school_access 에 이미 {$cnt}건 있습니다. 덮어쓰려면 --force 를 붙이세요.\n");
        exit(1);
    }
    try {
        $r = access_import_xlsx($path, $SHEETS);
    } catch (Throwable $e) {
        fwrite(STDERR, "실패: " . $e->getMessage() . "\n");
        exit(1);
    }
    foreach ($r['per'] as $sheet => $n) echo "  - {$sheet}: {$n}건\n";
    echo "총 {$r['total']}건을 school_access 에 넣었습니다";
    echo $r['new_schools'] ? " (schools 에 {$r['new_schools']}개 신규 등록).\n" : ".\n";
    exit(0);
}

/* ─────────────── 브라우저 업로드 ─────────────── */
require_once __DIR__ . '/guard.php';
header('Content-Type: application/json; charset=utf-8');
if (!current_portal_user()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => '로그인이 필요합니다'], JSON_UNESCAPED_UNICODE);
    exit;
}
try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') throw new Exception('POST 로 업로드하세요.');
    if (empty($_FILES['xlsx']) || ($_FILES['xlsx']['error'] ?? 1) !== UPLOAD_ERR_OK) {
        throw new Exception('엑셀 파일 업로드에 실패했습니다. (php.ini 의 upload_max_filesize 확인)');
    }
    $cnt = (int)access_db()->query("SELECT COUNT(*) FROM school_access")->fetchColumn();
    if ($cnt > 0 && empty($_POST['force'])) {
        throw new Exception("이미 {$cnt}건이 등록돼 있습니다. '기존 데이터 덮어쓰기'를 체크해야 가져올 수 있습니다.");
    }
    $r = access_import_xlsx($_FILES['xlsx']['tmp_name'], $SHEETS);
    echo json_encode(['ok' => true] + $r, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

<?php
/**
 * csmspwdlog — 슬랙 채널에 올라온 대학 사이트 주소로 관리자 계정을 찾아 요청자에게 DM 으로 보내고,
 * 누가·언제·어느 대학을 요청했는지 남긴다. 봇(bot/handle.php)과 이력 화면(api.php)이 같이 쓴다.
 *
 * 대학 계정(관리자 ID·비밀번호)은 이 모듈의 표에도 로그에도 남기지 않는다 — DM 문구로만 만들어 보내고 버린다.
 *
 * DB 는 portal_db() 로 붙는다. access_db() 는 부를 때마다 EnvHub 마이그레이션·암호화 백필이 돌아서 쓰지 않고,
 * access/db.php 는 복호화 함수(access_dec) 때문에만 불러온다.
 */
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../access/db.php';

const CSMSPWDLOG_RESULTS = [
    'sent'      => '전송',
    'not_found' => '대학 없음',
    'no_info'   => '계정 없음',
    'dm_failed' => 'DM 실패',
    'pending'   => '처리 중',
];
const CSMSPWDLOG_MAX_HOSTS = 5;

function csmspwdlog_table(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `csmspwdlog` (
            `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `created_at`    DATETIME     NOT NULL COMMENT '요청 시각',
            `channel`       VARCHAR(20)  NOT NULL COMMENT '슬랙 채널 ID',
            `msg_ts`        VARCHAR(32)  NOT NULL COMMENT '슬랙 메시지 ts',
            `slack_user_id` VARCHAR(20)  NOT NULL COMMENT '요청자 슬랙 ID',
            `slack_name`    VARCHAR(100) NOT NULL DEFAULT '' COMMENT '요청자 슬랙 이름',
            `slack_email`   VARCHAR(190) NOT NULL DEFAULT '' COMMENT '요청자 슬랙 이메일',
            `url_raw`       VARCHAR(500) NOT NULL DEFAULT '' COMMENT '입력한 주소 원문',
            `host`          VARCHAR(190) NOT NULL COMMENT '정규화한 호스트',
            `env`           VARCHAR(4)   NOT NULL DEFAULT '' COMMENT '입력 주소가 어느 쪽인지: ops=운영, dev=테스트, 빈 값=대학 없음',
            `school_id`     INT UNSIGNED NULL COMMENT 'schools.id',
            `school_name`   VARCHAR(200) NOT NULL DEFAULT '' COMMENT '요청 시점 대학명',
            `ver`           VARCHAR(20)  NOT NULL DEFAULT '' COMMENT '요청 시점 버전',
            `result`        VARCHAR(20)  NOT NULL DEFAULT 'pending' COMMENT 'pending|sent|not_found|no_info|dm_failed',
            `detail`        VARCHAR(500) NOT NULL DEFAULT '' COMMENT '오류코드 등',
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_msg_host` (`channel`, `msg_ts`, `host`),
            KEY `ix_created` (`created_at`),
            KEY `ix_school` (`school_id`, `created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
          COMMENT='슬랙 대학 관리자 계정 요청 이력 (계정 값은 저장하지 않는다)'
    ");
}

function csmspwdlog_host(string $u): string {
    $u = preg_replace('~^[a-z][a-z0-9+.-]*://~i', '', trim($u));
    $u = preg_split('~[/?#\s]~', $u)[0];
    $u = preg_replace('~^[^@]*@~', '', $u);
    $u = preg_replace('~:\d+$~', '', $u);
    $u = rtrim(strtolower(preg_replace('~^www\.~i', '', $u)), '.');
    return preg_match('~^[a-z0-9-]+(\.[a-z0-9-]+)+$~', $u) ? $u : '';
}

/**
 * 슬랙 메시지 본문에서 사이트 주소를 뽑는다 → [['raw'=>원문, 'host'=>호스트], …]
 * 슬랙이 링크로 바꾼 <url|라벨> 과, 링크가 안 걸린 맨 도메인을 둘 다 본다.
 */
function csmspwdlog_hosts_in(string $text): array {
    $found = [];
    if (preg_match_all('~<(https?://[^|>\s]+)(?:\|[^>]*)?>~i', $text, $m)) {
        foreach ($m[1] as $raw) $found[] = html_entity_decode($raw, ENT_QUOTES);
    }
    $rest = preg_replace('~<[^>]*>~', ' ', $text);   // 멘션·채널·mailto 링크는 주소가 아니다

    // 링크가 안 걸린 글에서는 파일 이름(index.php 등)이 도메인처럼 보인다
    $notTld = ['php', 'html', 'htm', 'js', 'css', 'txt', 'log', 'md', 'json', 'xml', 'sql', 'zip', 'png', 'jpg',
               'jpeg', 'gif', 'pdf', 'xlsx', 'xls', 'docx', 'pptx', 'hwp', 'sh', 'py', 'bat', 'conf', 'ini', 'yml', 'yaml'];
    $re = '~(?<![\w@.\-/])((?:https?://)?(?:(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,24}|\d{1,3}(?:\.\d{1,3}){3})(?::\d{2,5})?(?:[/?#]\S*)?)~i';
    if (preg_match_all($re, (string)$rest, $m)) {
        foreach ($m[1] as $raw) {
            if (!preg_match('~^https?://~i', $raw)) {
                $h = csmspwdlog_host($raw);
                if (in_array(substr((string)strrchr($h, '.'), 1), $notTld, true)) continue;
            }
            $found[] = $raw;
        }
    }

    $out = [];
    foreach ($found as $raw) {
        $h = csmspwdlog_host($raw);
        if ($h === '' || isset($out[$h])) continue;
        $out[$h] = ['raw' => mb_substr($raw, 0, 500), 'host' => $h];
        if (count($out) >= CSMSPWDLOG_MAX_HOSTS) break;
    }
    return array_values($out);
}

/** 호스트가 운영/테스트 주소와 같은 대학 → [['school_id','name','ver','env'=>'ops'|'dev','url'], …] */
function csmspwdlog_find(PDO $pdo, string $host): array {
    if ($host === '') return [];
    $out = [];
    foreach ($pdo->query("SELECT id, name, ver, dev, ops FROM schools WHERE active = 1 ORDER BY name, id") as $s) {
        foreach (['ops', 'dev'] as $env) {
            if (csmspwdlog_host((string)$s[$env]) !== $host) continue;
            $out[] = ['school_id' => (int)$s['id'], 'name' => (string)$s['name'], 'ver' => (string)$s['ver'],
                      'env' => $env, 'url' => (string)$s[$env]];
            break;   // 운영·테스트 주소가 같으면 운영으로 본다
        }
    }
    return $out;
}

/** 그 대학의 운영(ops) 또는 테스트(dev) 사이트 관리자 계정 → [['id','pw'], …] (복호화한 값) */
function csmspwdlog_accounts(PDO $pdo, int $schoolId, string $env): array {
    $col = $env === 'ops' ? 'login_ops' : 'login_dev';
    $st = $pdo->prepare("SELECT `{$col}_id` AS acct_id, `{$col}` AS acct_pw FROM school_access WHERE school_id = ? ORDER BY sort_no, id");
    $st->execute([$schoolId]);
    $out = [];
    foreach ($st as $r) {
        $id = trim((string)$r['acct_id']);
        $pw = trim(access_dec($r['acct_pw']));
        if ($id === '' && $pw === '') continue;
        $out[$id . "\0" . $pw] = ['id' => $id, 'pw' => $pw];
    }
    return array_values($out);
}

function csmspwdlog_slack_esc(string $s): string {
    return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $s);
}

/** 대학 한 곳의 DM 문구. $mask 면 비밀번호를 가린다(--dry-run). */
function csmspwdlog_dm_text(array $school, array $accounts, bool $mask = false): string {
    $lines = [sprintf('*%s*%s · %s', csmspwdlog_slack_esc($school['name']),
                      $school['ver'] !== '' ? ' (' . csmspwdlog_slack_esc($school['ver']) . ')' : '',
                      $school['env'] === 'ops' ? '운영' : '테스트'),
              csmspwdlog_slack_esc($school['url'])];
    if (!$accounts) {
        $lines[] = 'EnvHub 에 등록된 관리자 계정이 없습니다.';
        return implode("\n", $lines);
    }
    foreach ($accounts as $a) {
        $lines[] = 'ID: `' . csmspwdlog_slack_esc($a['id'] !== '' ? $a['id'] : '(미입력)') . '`';
        $lines[] = 'PW: `' . ($mask ? '****' : csmspwdlog_slack_esc($a['pw'] !== '' ? $a['pw'] : '(미입력)')) . '`';
    }
    return implode("\n", $lines);
}

function csmspwdlog_curl_ca(): array {
    if (is_file((string)ini_get('curl.cainfo'))) return [];
    foreach ([(string)ini_get('openssl.cafile'), dirname(__DIR__) . '/slackai/vendor/cacert.pem'] as $ca) {
        if ($ca !== '' && is_file($ca)) return [CURLOPT_CAINFO => $ca];
    }
    return [];
}

/** Slack Web API 호출(form POST). 실패해도 예외 없이 ['ok'=>false,'error'=>…] */
function csmspwdlog_slack(string $method, string $token, array $fields): array {
    $ch = curl_init("https://slack.com/api/{$method}");
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token, 'Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_POSTFIELDS     => http_build_query($fields),
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT        => 15,
    ] + csmspwdlog_curl_ca());
    $body = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $res = json_decode((string)$body, true);
    if (is_array($res)) return $res;
    return ['ok' => false, 'error' => $err !== '' ? 'curl: ' . $err : "http_{$code}"];
}

/** 이력 조회: ?q=&result=&from=YYYY-MM-DD&to=YYYY-MM-DD&limit=&offset= → ['rows','total','limit','offset','results'] */
function csmspwdlog_list(PDO $pdo, array $get): array {
    $w = []; $p = [];
    $q = mb_substr(trim((string)($get['q'] ?? '')), 0, 100);
    if ($q !== '') {
        $w[] = '(l.slack_name LIKE ? OR l.slack_email LIKE ? OR l.school_name LIKE ? OR l.host LIKE ? OR l.url_raw LIKE ? OR pu.name LIKE ?)';
        $like = '%' . $q . '%';
        array_push($p, $like, $like, $like, $like, $like, $like);
    }
    $result = (string)($get['result'] ?? '');
    if ($result !== '' && isset(CSMSPWDLOG_RESULTS[$result])) { $w[] = 'l.result = ?'; $p[] = $result; }
    $from = (string)($get['from'] ?? '');
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) { $w[] = 'l.created_at >= ?'; $p[] = $from . ' 00:00:00'; }
    $to = (string)($get['to'] ?? '');
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) { $w[] = 'l.created_at <= ?'; $p[] = $to . ' 23:59:59'; }

    $limit  = max(1, min(500, (int)($get['limit'] ?? 100)));
    $offset = max(0, (int)($get['offset'] ?? 0));
    // 슬랙 이메일 → 포털 사용자. portal_users.slack_email 이 비면 로그인 이메일이 슬랙 이메일이다.
    $base = " FROM csmspwdlog l
              LEFT JOIN portal_users pu
                     ON l.slack_email <> '' AND LOWER(COALESCE(NULLIF(pu.slack_email, ''), pu.email)) = LOWER(l.slack_email)"
          . ($w ? ' WHERE ' . implode(' AND ', $w) : '');

    $st = $pdo->prepare("SELECT COUNT(*) AS cnt" . $base);
    $st->execute($p);
    $total = (int)($st->fetch()['cnt'] ?? 0);

    $st = $pdo->prepare("SELECT l.id, l.created_at, l.channel, l.slack_user_id, l.slack_name, l.slack_email, l.url_raw, l.host,
                                l.env, l.school_id, l.school_name, l.ver, l.result, l.detail, pu.name AS portal_name"
                        . $base . " ORDER BY l.id DESC LIMIT {$limit} OFFSET {$offset}");
    $st->execute($p);
    $rows = [];
    foreach ($st as $r) {
        $r['id'] = (int)$r['id'];
        $r['school_id'] = $r['school_id'] === null ? null : (int)$r['school_id'];
        $rows[] = $r;
    }
    return ['rows' => $rows, 'total' => $total, 'limit' => $limit, 'offset' => $offset, 'results' => CSMSPWDLOG_RESULTS];
}

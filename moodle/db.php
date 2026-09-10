<?php
/**
 * MoodleUp(무들 동향) 조회 헬퍼.
 *  - 데이터는 moodle/watch/ 의 Python 주간 배치가 넣는다(주 1회 INSERT). 이 파일은 읽기만 한다.
 *  - 테이블 DDL 은 배치(core/store.py)와 같다 — 배치가 아직 안 돌았어도 화면이 뜨도록 여기서도 만든다.
 *    컬럼을 바꾸면 두 곳을 같이 고칠 것.
 */

require_once __DIR__ . '/../db.php';

/** 요약하기·갱신 이력·상태 배지를 볼 수 있는 계정. 나머지는 요약과 원문만 본다. */
const MOODLE_ADMINS = ['amitoa@bluesoft.co.kr'];

function moodle_is_admin($email) {
    return in_array(strtolower(trim((string)$email)), MOODLE_ADMINS, true);
}

function moodle_db() {
    static $ready = false;
    $pdo = portal_db();
    if ($ready) return $pdo;
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `moodle_weekly_report` (
            `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `week`         VARCHAR(10)  NOT NULL COMMENT 'ISO 주차 예: 2026-W37',
            `period_start` DATETIME     NOT NULL,
            `period_end`   DATETIME     NOT NULL,
            `generated_at` DATETIME     NOT NULL COMMENT '마지막 갱신 시각(UTC)',
            `first_generated_at` DATETIME NULL   COMMENT '처음 생성 시각(UTC)',
            `run_count`    INT UNSIGNED NOT NULL DEFAULT 1,
            `status`       VARCHAR(12)  NOT NULL COMMENT 'ok | partial | failed',
            `headline`     VARCHAR(300) NOT NULL DEFAULT '',
            `summary_md`   MEDIUMTEXT   NULL,
            `updates_md`   MEDIUMTEXT   NULL     COMMENT '마지막 갱신에서 새로 들어온 것 요약',
            `actions_json` TEXT         NULL,
            `sources_json` TEXT         NOT NULL COMMENT '소스별 status/note/stats',
            `model`        VARCHAR(80)  NOT NULL DEFAULT '',
            `note`         TEXT         NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_week` (`week`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `moodle_weekly_item` (
            `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `report_id`     INT UNSIGNED NOT NULL,
            `source`        VARCHAR(20)  NOT NULL,
            `kind`          VARCHAR(20)  NOT NULL,
            `title`         VARCHAR(500) NOT NULL,
            `url`           VARCHAR(700) NOT NULL,
            `published_at`  DATETIME     NULL,
            `excerpt`       TEXT         NULL,
            `meta_json`     TEXT         NULL,
            `is_focus`      TINYINT(1)   NOT NULL DEFAULT 0,
            `impact`        VARCHAR(4)   NULL COMMENT '고 | 중 | 저 (요약 모델 판정)',
            `impact_reason` TEXT         NULL,
            `added_run`     INT UNSIGNED NOT NULL DEFAULT 1 COMMENT '이 항목이 처음 들어온 실행 번호',
            PRIMARY KEY (`id`),
            KEY `ix_report` (`report_id`, `source`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `moodle_weekly_run` (
            `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `report_id`  INT UNSIGNED NOT NULL,
            `run_no`     INT UNSIGNED NOT NULL,
            `ran_at`     DATETIME     NOT NULL,
            `trigger`    VARCHAR(12)  NOT NULL COMMENT 'timer | manual | cli',
            `status`     VARCHAR(12)  NOT NULL,
            `new_items`  INT UNSIGNED NOT NULL DEFAULT 0,
            `headline`   VARCHAR(300) NOT NULL DEFAULT '',
            `updates_md` MEDIUMTEXT   NULL,
            `note`       TEXT         NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_run` (`report_id`, `run_no`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    // 첫 배포판(실행 이력 이전) 테이블에 컬럼 보강 — 배치(core/store.py MIGRATIONS)와 같은 목록
    add_column_if_missing($pdo, "ALTER TABLE `moodle_weekly_report` ADD COLUMN `first_generated_at` DATETIME NULL AFTER `generated_at`");
    add_column_if_missing($pdo, "ALTER TABLE `moodle_weekly_report` ADD COLUMN `run_count` INT UNSIGNED NOT NULL DEFAULT 1 AFTER `first_generated_at`");
    add_column_if_missing($pdo, "ALTER TABLE `moodle_weekly_report` ADD COLUMN `updates_md` MEDIUMTEXT NULL AFTER `summary_md`");
    add_column_if_missing($pdo, "ALTER TABLE `moodle_weekly_item` ADD COLUMN `added_run` INT UNSIGNED NOT NULL DEFAULT 1");
    // 요약본 형광펜·메모. PHP 만 쓰는 테이블이라 배치(store.py)에는 없다.
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `moodle_note` (
            `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `report_id`   INT UNSIGNED NOT NULL,
            `target`      VARCHAR(12)  NOT NULL DEFAULT 'summary' COMMENT 'summary | updates',
            `kind`        VARCHAR(10)  NOT NULL COMMENT 'highlight | note',
            `anchor_text` TEXT         NOT NULL COMMENT '선택한 글',
            `prefix`      VARCHAR(200) NOT NULL DEFAULT '' COMMENT '앞 문맥(같은 문장이 여럿일 때 위치 찾기용)',
            `suffix`      VARCHAR(200) NOT NULL DEFAULT '',
            `note`        TEXT         NULL,
            `color`       VARCHAR(12)  NOT NULL DEFAULT 'yellow',
            `user_email`  VARCHAR(190) NOT NULL,
            `user_name`   VARCHAR(60)  NOT NULL DEFAULT '',
            `created_at`  DATETIME     NOT NULL,
            PRIMARY KEY (`id`),
            KEY `ix_report` (`report_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $ready = true;
    return $pdo;
}

/** 리포트의 형광펜·메모(오래된 것부터 — 같은 순서로 다시 찍어야 겹침이 안정적이다) */
function moodle_notes($reportId) {
    $stmt = moodle_db()->prepare("SELECT * FROM moodle_note WHERE report_id = ? ORDER BY id");
    $stmt->execute([(int)$reportId]);
    return $stmt->fetchAll();
}

function moodle_note_add(array $n) {
    $pdo = moodle_db();
    $stmt = $pdo->prepare(
        "INSERT INTO moodle_note (report_id, target, kind, anchor_text, prefix, suffix, note, color,
                                  user_email, user_name, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())");
    $stmt->execute([$n['report_id'], $n['target'], $n['kind'], $n['text'], $n['prefix'], $n['suffix'],
                    $n['note'], $n['color'], $n['user_email'], $n['user_name']]);
    $id = (int)$pdo->lastInsertId();
    $row = $pdo->prepare("SELECT * FROM moodle_note WHERE id = ?");
    $row->execute([$id]);
    return $row->fetch();
}

/**
 * 북마크(kind=bookmark) 전체를 최신순으로. $q 가 있으면 본문·메모·주차·헤드라인에서 LIKE 검색.
 * 북마크 페이지에서만 쓴다(사용자가 열 때 SELECT 한 번).
 */
function moodle_bookmarks($q = '') {
    $sql = "SELECT n.*, r.week, r.headline, r.period_start, r.period_end
              FROM moodle_note n JOIN moodle_weekly_report r ON r.id = n.report_id
             WHERE n.kind = 'bookmark'";
    $args = [];
    $q = trim((string)$q);
    if ($q !== '') {
        $sql .= " AND (n.anchor_text LIKE ? OR n.note LIKE ? OR r.week LIKE ? OR r.headline LIKE ? OR n.user_name LIKE ?)";
        $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
        $args = [$like, $like, $like, $like, $like];
    }
    $sql .= " ORDER BY n.id DESC";
    $stmt = moodle_db()->prepare($sql);
    $stmt->execute($args);
    return $stmt->fetchAll();
}

function moodle_bookmark_count() {
    return (int)moodle_db()->query("SELECT COUNT(*) FROM moodle_note WHERE kind = 'bookmark'")->fetchColumn();
}

/** 본인 메모만 지운다. 지웠으면 true. */
function moodle_note_delete($id, $email) {
    $stmt = moodle_db()->prepare("DELETE FROM moodle_note WHERE id = ? AND user_email = ?");
    $stmt->execute([(int)$id, $email]);
    return $stmt->rowCount() > 0;
}

/**
 * 요약 HTML 에서 PAG(Technical Transformation) 절을 강조 상자로 감싼다.
 * 제목(h2~h5)에 PAG·Technical Transformation·Tech Transformation 이 들어가면 그 제목부터
 * 같은 급 이상의 다음 제목 전까지를 <div class="pag-block"> 로 묶는다.
 */
function moodle_md_emphasize_pag($html) {
    $parts = preg_split('/(<h([2-5])>.*?<\/h\2>)/su', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
    $out = '';
    $openLevel = 0;
    for ($i = 0; $i < count($parts); $i++) {
        $chunk = $parts[$i];
        if (preg_match('/^<h([2-5])>(.*?)<\/h\1>$/su', $chunk, $m)) {
            $level = (int)$m[1];
            $isPag = preg_match('/PAG|tech(?:nical)?\s+transformation/iu', strip_tags($m[2]));
            if ($openLevel && $level <= $openLevel) { $out .= '</div>'; $openLevel = 0; }
            if ($isPag && !$openLevel) { $out .= '<div class="pag-block">'; $openLevel = $level; }
            $out .= $chunk;
            $i++;   // 다음 조각은 캡처된 레벨 숫자 — 건너뛴다
            continue;
        }
        $out .= $chunk;
    }
    if ($openLevel) $out .= '</div>';
    return $out;
}

/** 사이드바용 주차 목록(최신 순) */
function moodle_weeks() {
    return moodle_db()->query(
        "SELECT id, week, period_start, period_end, generated_at, run_count, status, headline
           FROM moodle_weekly_report ORDER BY week DESC"
    )->fetchAll();
}

/** 주차 하나. $week 가 null 이면 최신. 없으면 null. */
function moodle_report($week = null) {
    $pdo = moodle_db();
    if ($week === null || $week === '') {
        $stmt = $pdo->query("SELECT * FROM moodle_weekly_report ORDER BY week DESC LIMIT 1");
    } else {
        $stmt = $pdo->prepare("SELECT * FROM moodle_weekly_report WHERE week = ?");
        $stmt->execute([$week]);
    }
    $row = $stmt->fetch();
    if (!$row) return null;
    $row['actions'] = json_decode($row['actions_json'] ?? '[]', true) ?: [];
    $row['sources'] = json_decode($row['sources_json'] ?? '[]', true) ?: [];
    return $row;
}

/** 리포트의 실행 이력(최근 것부터). 1회차가 처음 생성이고 그 뒤가 갱신이다. */
function moodle_runs($reportId) {
    $stmt = moodle_db()->prepare(
        "SELECT * FROM moodle_weekly_run WHERE report_id = ? ORDER BY run_no DESC");
    $stmt->execute([(int)$reportId]);
    return $stmt->fetchAll();
}

/**
 * 갱신 요청 파일이 놓이는 폴더. 배치(Settings.data_dir)와 같은 곳을 봐야 한다.
 * 기본은 watch/var/requests. 서버에서 DATA_DIR 을 바꿨으면 moodle/config.local.php 에
 * return ['data_dir' => '/경로'] 로 알려준다.
 */
function moodle_requests_dir() {
    $local = __DIR__ . '/config.local.php';
    $cfg = is_file($local) ? (require $local) : [];
    $dataDir = $cfg['data_dir'] ?? (__DIR__ . '/watch/var');
    return rtrim($dataDir, "/\\") . '/requests';
}

/**
 * 주차의 갱신 요청 상태: null(없음) | ['state' => 'pending'|'running'|'failed', ...파일 내용]
 * 파일 stat 만 본다 — DB 는 건드리지 않는다.
 */
function moodle_refresh_state($week) {
    $dir = moodle_requests_dir();
    foreach (['running' => "$week.running", 'pending' => "$week.json", 'failed' => "$week.failed"] as $state => $name) {
        $path = "$dir/$name";
        if (is_file($path)) {
            $info = json_decode((string)@file_get_contents($path), true) ?: [];
            return ['state' => $state, 'path' => $path] + $info;
        }
    }
    return null;
}

/** 리포트의 원문 항목. 주목·영향도 높은 것부터. */
function moodle_items($reportId) {
    $stmt = moodle_db()->prepare(
        "SELECT * FROM moodle_weekly_item WHERE report_id = ?
          ORDER BY FIELD(impact, '고', '중', '저') DESC, is_focus DESC, published_at DESC, id"
    );
    $stmt->execute([(int)$reportId]);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) {
        $r['meta'] = json_decode($r['meta_json'] ?? '{}', true) ?: [];
    }
    return $rows;
}

/** DB 의 DATETIME(UTC) → 화면용 KST 문자열 */
function moodle_kst($utc, $fmt = 'Y-m-d H:i') {
    if (!$utc) return '';
    try {
        $d = new DateTime($utc, new DateTimeZone('UTC'));
        return $d->setTimezone(new DateTimeZone('Asia/Seoul'))->format($fmt);
    } catch (Exception $e) {
        return (string)$utc;
    }
}

/**
 * 요약 마크다운 → HTML. 요약 모델에는 제목·불릿·번호·굵게·코드·링크만 쓰라고 했으니 그만큼만 지원한다.
 * 먼저 전부 이스케이프하고 그 위에서 패턴을 바꾸므로 원문의 HTML 은 절대 살아나지 않는다.
 * 링크는 http(s) 만 허용한다.
 */
function moodle_md($md) {
    $lines = preg_split('/\r\n|\r|\n/', (string)$md);
    $out = [];
    $list = null;        // 'ul' | 'ol' | null
    $para = [];
    $closeList = function () use (&$out, &$list) {
        if ($list) { $out[] = "</$list>"; $list = null; }
    };
    $flushPara = function () use (&$out, &$para) {
        if ($para) { $out[] = '<p>' . moodle_md_inline(implode(' ', $para)) . '</p>'; $para = []; }
    };
    foreach ($lines as $line) {
        $t = rtrim($line);
        if ($t === '') { $flushPara(); $closeList(); continue; }
        if (preg_match('/^\s*(-{3,}|\*{3,})\s*$/', $t)) {   // 구분선(갱신분 경계)
            $flushPara(); $closeList();
            $out[] = '<hr>';
            continue;
        }
        if (preg_match('/^(#{1,4})\s+(.*)$/', $t, $m)) {
            $flushPara(); $closeList();
            $lv = min(strlen($m[1]) + 1, 5);   // 페이지 h1 은 이미 있으니 한 단계 내린다
            $out[] = "<h$lv>" . moodle_md_inline($m[2]) . "</h$lv>";
            continue;
        }
        if (preg_match('/^\s*[-*]\s+(.*)$/', $t, $m)) {
            $flushPara();
            if ($list !== 'ul') { $closeList(); $out[] = '<ul>'; $list = 'ul'; }
            $out[] = '<li>' . moodle_md_inline($m[1]) . '</li>';
            continue;
        }
        if (preg_match('/^\s*\d+[.)]\s+(.*)$/', $t, $m)) {
            $flushPara();
            if ($list !== 'ol') { $closeList(); $out[] = '<ol>'; $list = 'ol'; }
            $out[] = '<li>' . moodle_md_inline($m[1]) . '</li>';
            continue;
        }
        if ($list && preg_match('/^\s{2,}(\S.*)$/', $t, $m)) {
            // 들여쓴 줄은 앞 항목의 이어지는 문장
            $out[count($out) - 1] = preg_replace('/<\/li>$/', ' ' . moodle_md_inline($m[1]) . '</li>', $out[count($out) - 1]);
            continue;
        }
        $closeList();
        $para[] = trim($t);
    }
    $flushPara(); $closeList();
    return implode("\n", $out);
}

function moodle_md_inline($s) {
    $s = htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    $s = preg_replace('/`([^`]+)`/', '<code>$1</code>', $s);
    $s = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $s);
    $s = preg_replace_callback('/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/', function ($m) {
        return '<a href="' . $m[2] . '" target="_blank" rel="noopener">' . $m[1] . '</a>';
    }, $s);
    // 태그 바깥 텍스트의 PAG 낱말만 강조 표식으로 감싼다(URL·속성은 건드리지 않는다)
    $s = preg_replace_callback('/(^|>)([^<]+)/u', function ($m) {
        return $m[1] . preg_replace('/\bPAG\b/u', '<span class="pag-tag">PAG</span>', $m[2]);
    }, $s);
    return $s;
}

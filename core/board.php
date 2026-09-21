<?php
/**
 * 포털 알림판 — 공지 / 중요 일정 / 포털 관리자.
 *
 * ┌──────────────────────────────────────────────────────────────────┐
 * │ 관리자 명단                                                        │
 * │   화면에서 바꾸지만(portal_admin), OWNER_ADMINS 는 코드에 고정한다.│
 * │   DB 가 비거나 잘못 저장돼도 관리자 없는 상태로 잠기지 않게 하는    │
 * │   안전장치다. learn/guard.php 의 OWNER_EMAILS 와 같은 생각이다.    │
 * └──────────────────────────────────────────────────────────────────┘
 *
 * 첨부파일은 learn 과 같은 방식이다. 저장 이름은 서버가 난수로 짓고 원본명은
 * DB 에만 둔다. 웹으로 직접 못 받게 var/notice 를 막아 두고, 열람은 반드시
 * api/notice_file.php (로그인 검사 + Content-Disposition 판정)를 거친다.
 */

require_once __DIR__ . '/auth.php';   // 세션 + current_portal_user() + portal_db()

// 명단이 어떻게 되든 이 사람들은 항상 관리자다.
const OWNER_ADMINS = ['kimhy@bluesoft.co.kr'];

/**
 * 날씨를 보여 줄 사무실 위치.
 *
 * 자료는 브라우저가 Open-Meteo 에서 직접 받는다(열쇠 없이 쓰는 무료 서비스).
 * 서버가 밖으로 못 나가는 곳에서도 동작하라고 그렇게 했다.
 * 옮기려면 여기만 고치거나, config.php 에 'weather' 를 넣어 덮어쓴다.
 */
// 충북 청주시 청원구 내덕동. Open-Meteo 지오코딩으로 확인한 값이다.
const OFFICE_WEATHER = ['lat' => 36.6553, 'lon' => 127.4890, 'label' => '청주'];

function board_office_weather()
{
    $cfg = require dirname(__DIR__) . '/config.php';
    $w   = isset($cfg['weather']) && is_array($cfg['weather']) ? $cfg['weather'] : [];
    return [
        'lat'   => isset($w['lat'])   ? (float)$w['lat']   : OFFICE_WEATHER['lat'],
        'lon'   => isset($w['lon'])   ? (float)$w['lon']   : OFFICE_WEATHER['lon'],
        'label' => isset($w['label']) ? (string)$w['label'] : OFFICE_WEATHER['label'],
    ];
}

/** 대시보드 배경 설정. 아무것도 안 고른 사람은 날씨를 따라간다. */
const BG_PREFS = ['weather', 'plain'];

function board_bg_pref($u)
{
    $v = is_array($u) && isset($u['bg_pref']) ? (string)$u['bg_pref'] : '';
    return in_array($v, BG_PREFS, true) ? $v : 'weather';
}

// 최초 기동 때 portal_admin 에 심을 초기 명단. 그 뒤로는 DB 가 원본이다.
const SEED_ADMINS = ['kimhy@bluesoft.co.kr'];

// 첨부 원본은 포털 루트의 var/ 아래다. 거기에 직접 접근을 막는 .htaccess 가 있다.
// 첨부 원본은 포털 루트의 var/ 아래다. 거기에 직접 접근을 막는 .htaccess 가 있다.
const NOTICE_UPLOAD_DIR = __DIR__ . '/../var/notice';
const NOTICE_MAX_MB     = 20;   // 우리가 바라는 상한. 실제 한계는 php.ini 가 더 낮을 수 있다.
const NOTICE_MAX_FILES  = 10;

// HTML/SVG 는 같은 오리진에서 열리면 포털 세션을 노린 XSS 가 된다.
// 화이트리스트 밖은 아예 받지 않고, 받은 것도 내려줄 때 한 번 더 판정한다.
const NOTICE_ALLOWED_EXT = [
    'png', 'jpg', 'jpeg', 'gif', 'webp', 'bmp',
    'pdf', 'txt', 'csv',
    'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'hwp', 'hwpx',
    'zip',
];

const NOTICE_INLINE_TYPES = [
    'pdf'  => 'application/pdf',
    'png'  => 'image/png',
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'gif'  => 'image/gif',
    'webp' => 'image/webp',
    'bmp'  => 'image/bmp',
];

/** 이 파일의 함수가 던지는 오류. api/* 가 잡아서 JSON 으로 바꾼다. */
class BoardError extends Exception
{
    public function __construct($message, $code = 400)
    {
        parent::__construct($message, $code);
    }
}

// ---------------------------------------------------------------------
// 관리자
// ---------------------------------------------------------------------

/** 최초 1회 초기 명단을 심는다. 한 번이라도 들어간 뒤로는 DB 가 원본이다. */
function board_seed_admins()
{
    static $done = false;
    if ($done) return;
    $done = true;

    $pdo = portal_db();
    if ((int)$pdo->query("SELECT COUNT(*) FROM portal_admin")->fetchColumn() > 0) {
        return;
    }
    $ins = $pdo->prepare("INSERT IGNORE INTO portal_admin (email, added_by, created_at) VALUES (?, 'seed', NOW())");
    foreach (SEED_ADMINS as $email) {
        $ins->execute([strtolower($email)]);
    }
}

/**
 * DB 명단 + 고정 관리자. 명단을 고친 직후에는 $reload 로 캐시를 갈아끼워야
 * 같은 요청 안에서 판정이 맞는다.
 *
 * @return string[] 소문자 이메일
 */
function board_admin_emails($reload = false)
{
    static $set = null;
    if ($set === null || $reload) {
        board_seed_admins();
        $rows = portal_db()->query("SELECT email FROM portal_admin")->fetchAll(PDO::FETCH_COLUMN);
        $set  = array_values(array_unique(array_map('strtolower',
                    array_merge($rows, OWNER_ADMINS))));
    }
    return $set;
}

function board_is_admin($email)
{
    return $email && in_array(strtolower($email), board_admin_emails(), true);
}

/** 명단에서 뺄 수 없는 사람인가 (고정 관리자) */
function board_is_owner($email)
{
    return $email && in_array(strtolower($email), array_map('strtolower', OWNER_ADMINS), true);
}

function board_require_admin(array $u)
{
    if (!board_is_admin($u['email'])) {
        throw new BoardError('포털 관리자만 할 수 있습니다', 403);
    }
}

/** 관리자 명단 + 이름. 화면의 관리 탭에서 쓴다. */
function board_admin_list()
{
    board_seed_admins();
    $rows = portal_db()->query(
        "SELECT a.email, a.created_at, u.name
           FROM portal_admin a
           LEFT JOIN portal_users u ON u.email = a.email
          ORDER BY a.id"
    )->fetchAll();

    $seen = [];
    $out  = [];
    foreach ($rows as $r) {
        $seen[strtolower($r['email'])] = true;
        $out[] = [
            'email'    => $r['email'],
            'name'     => $r['name'] ?: '(포털 계정 없음)',
            'is_owner' => board_is_owner($r['email']),
        ];
    }
    // DB 에 없어도 고정 관리자는 명단에 보여 준다. 화면과 실제 권한이 달라 보이면 안 된다.
    foreach (OWNER_ADMINS as $email) {
        if (isset($seen[strtolower($email)])) continue;
        $out[] = ['email' => $email, 'name' => board_name_of($email) ?: '(포털 계정 없음)', 'is_owner' => true];
    }
    return $out;
}

function board_name_of($email)
{
    $st = portal_db()->prepare("SELECT name FROM portal_users WHERE email = ?");
    $st->execute([$email]);
    return (string)$st->fetchColumn();
}

function board_add_admin($email, $by)
{
    $email = strtolower(trim((string)$email));
    if ($email === '') {
        throw new BoardError('이메일을 입력하세요', 422);
    }
    // 포털 계정이 있는 사람만 받는다. 오타로 아무 주소나 들어가면 명단이 지저분해진다.
    if (board_name_of($email) === '') {
        throw new BoardError('포털 계정에 없는 이메일입니다', 422);
    }
    portal_db()->prepare("INSERT IGNORE INTO portal_admin (email, added_by, created_at) VALUES (?, ?, NOW())")
               ->execute([$email, $by]);
    board_admin_emails(true);
}

function board_remove_admin($email)
{
    $email = strtolower(trim((string)$email));
    if (board_is_owner($email)) {
        throw new BoardError('고정 관리자는 뺄 수 없습니다', 422);
    }
    portal_db()->prepare("DELETE FROM portal_admin WHERE email = ?")->execute([$email]);
    board_admin_emails(true);
}

// ---------------------------------------------------------------------
// 공지
// ---------------------------------------------------------------------

/**
 * 지금 구성원에게 보여야 하는 공지인가.
 *
 * 노출 기간을 안 적으면(둘 다 NULL) 올린 즉시부터 내릴 때까지 계속 보인다.
 * 예전처럼 쓰고 싶으면 그냥 비워 두면 된다.
 */
const NOTICE_LIVE_WHERE = "(n.starts_on IS NULL OR n.starts_on <= CURDATE())
                       AND (n.ends_on   IS NULL OR n.ends_on   >= CURDATE())";

/**
 * 목록. 고정된 공지가 언제나 먼저 온다.
 *
 * @param int  $limit 0 이면 전체(페이징용 $offset 과 함께 쓴다)
 * @param bool $all   true 면 예약·종료된 것까지 — 관리 화면 전용
 */
function board_notices($limit = 5, $offset = 0, $all = false)
{
    $sql = "SELECT n.id, n.title, n.is_important, n.starts_on, n.ends_on,
                   n.author_name, n.view_count, n.created_at, n.updated_at,
                   (SELECT COUNT(*) FROM portal_notice_file f WHERE f.notice_id = n.id) AS file_count
              FROM portal_notice n";
    if (!$all) {
        $sql .= ' WHERE ' . NOTICE_LIVE_WHERE;
    }
    // 맨 위 고정은 없앴다. 중요 공지도 자기 자리에 있고 표시만 붙는다.
    $sql .= " ORDER BY n.id DESC";
    if ($limit > 0) {
        $sql .= ' LIMIT ' . (int)$limit . ' OFFSET ' . (int)$offset;
    }
    $rows = portal_db()->query($sql)->fetchAll();
    foreach ($rows as &$r) {
        $r['is_important'] = (int)$r['is_important'] === 1;
        $r['file_count'] = (int)$r['file_count'];
        $r['view_count'] = (int)$r['view_count'];
        $r['is_new']     = board_is_new($r['created_at']);
        $r['window']     = board_window_label($r);
    }
    return $rows;
}

function board_notice_count($all = false)
{
    $sql = "SELECT COUNT(*) FROM portal_notice n";
    if (!$all) {
        $sql .= ' WHERE ' . NOTICE_LIVE_WHERE;
    }
    return (int)portal_db()->query($sql)->fetchColumn();
}

/**
 * 관리 화면에서 한눈에 보라고 붙이는 딱지.
 * 노출중인 건에는 아무 딱지도 안 붙인다 — 그게 보통 상태라서.
 */
function board_window_label(array $n)
{
    $today = date('Y-m-d');
    if (!empty($n['starts_on']) && $n['starts_on'] > $today) {
        return ['state' => 'scheduled', 'label' => $n['starts_on'] . ' 공개 예정'];
    }
    if (!empty($n['ends_on']) && $n['ends_on'] < $today) {
        return ['state' => 'ended', 'label' => $n['ends_on'] . ' 내림'];
    }
    if (!empty($n['ends_on'])) {
        return ['state' => 'live', 'label' => $n['ends_on'] . ' 까지'];
    }
    return ['state' => 'live', 'label' => ''];
}

/** 올린 지 사흘이 안 지났으면 NEW. 목록에서 눈에 띄게 하려는 것뿐이다. */
function board_is_new($createdAt)
{
    return strtotime((string)$createdAt) >= strtotime('-3 days');
}

function board_notice($id, $countView = false)
{
    $st = portal_db()->prepare("SELECT * FROM portal_notice WHERE id = ?");
    $st->execute([(int)$id]);
    $n = $st->fetch();
    if (!$n) {
        throw new BoardError('공지를 찾을 수 없습니다', 404);
    }
    if ($countView) {
        portal_db()->prepare("UPDATE portal_notice SET view_count = view_count + 1 WHERE id = ?")
                   ->execute([(int)$id]);
        $n['view_count'] = (int)$n['view_count'] + 1;
    }
    $n['is_important'] = (int)$n['is_important'] === 1;
    $n['view_count'] = (int)$n['view_count'];
    $n['files']      = board_notice_files($id);
    $n['window']     = board_window_label($n);
    return $n;
}

function board_notice_files($noticeId)
{
    $st = portal_db()->prepare(
        "SELECT id, orig_name, file_size FROM portal_notice_file WHERE notice_id = ? ORDER BY id"
    );
    $st->execute([(int)$noticeId]);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        $r['id']        = (int)$r['id'];
        $r['file_size'] = (int)$r['file_size'];
    }
    return $rows;
}

/** @return array [제목, 본문, 노출 시작일|null, 노출 종료일|null] */
function board_validate_notice(array $in)
{
    $title = trim((string)(isset($in['title']) ? $in['title'] : ''));
    $body  = trim((string)(isset($in['body']) ? $in['body'] : ''));
    $from  = trim((string)(isset($in['starts_on']) ? $in['starts_on'] : ''));
    $to    = trim((string)(isset($in['ends_on']) ? $in['ends_on'] : ''));

    if ($title === '')            { throw new BoardError('제목을 입력하세요', 422); }
    if (mb_strlen($title) > 200)  { throw new BoardError('제목은 200자 이내로 입력하세요', 422); }
    if ($body === '')             { throw new BoardError('내용을 입력하세요', 422); }
    if (mb_strlen($body) > 20000) { throw new BoardError('내용은 20,000자 이내로 입력하세요', 422); }
    if ($from !== '' && !board_is_date($from)) { throw new BoardError('노출 시작일을 올바르게 입력하세요', 422); }
    if ($to   !== '' && !board_is_date($to))   { throw new BoardError('노출 종료일을 올바르게 입력하세요', 422); }
    if ($from !== '' && $to !== '' && $to < $from) {
        throw new BoardError('노출 종료일이 시작일보다 빠릅니다', 422);
    }
    return [$title, $body, $from ?: null, $to ?: null];
}

function board_create_notice(array $in, array $u)
{
    list($title, $body, $from, $to) = board_validate_notice($in);
    portal_db()->prepare(
        "INSERT INTO portal_notice (title, body, is_important, starts_on, ends_on,
                                    author_email, author_name, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, NOW())"
    )->execute([$title, $body, empty($in['is_important']) ? 0 : 1, $from, $to, $u['email'], $u['name']]);
    return (int)portal_db()->lastInsertId();
}

function board_update_notice($id, array $in)
{
    list($title, $body, $from, $to) = board_validate_notice($in);
    board_notice($id);   // 없으면 404
    portal_db()->prepare(
        "UPDATE portal_notice SET title = ?, body = ?, is_important = ?, starts_on = ?, ends_on = ?,
                updated_at = NOW() WHERE id = ?"
    )->execute([$title, $body, empty($in['is_important']) ? 0 : 1, $from, $to, (int)$id]);
}

/** 행을 지우기 전에 실물 파일부터 지운다. 행만 사라지면 파일이 영영 남는다. */
function board_delete_notice($id)
{
    foreach (board_notice_files($id) as $f) {
        board_delete_file($f['id']);
    }
    portal_db()->prepare("DELETE FROM portal_notice WHERE id = ?")->execute([(int)$id]);
}

// ---------------------------------------------------------------------
// 공지 첨부
// ---------------------------------------------------------------------

/**
 * 첨부를 쌓아 둘 폴더. 없으면 만든다.
 *
 * var/notice 는 .gitignore 대상이라 배포 직후에는 없다. 웹서버 계정이 포털
 * 루트에 쓸 수 없으면 mkdir 이 조용히 실패하고, 그 뒤 move_uploaded_file 만
 * 실패해 "왜 안 되는지 모르겠다" 가 된다. 여기서 미리 붙잡아 이유를 말한다.
 */
function board_upload_dir()
{
    if (!is_dir(NOTICE_UPLOAD_DIR)) {
        @mkdir(NOTICE_UPLOAD_DIR, 0777, true);
    }
    $dir = realpath(NOTICE_UPLOAD_DIR) ?: NOTICE_UPLOAD_DIR;

    if (!is_dir($dir)) {
        throw new BoardError(
            '첨부 저장 폴더를 만들지 못했습니다: ' . $dir .
            "\n웹서버 계정에 쓰기 권한을 주세요 (mkdir -p + chown).", 500);
    }
    if (!is_writable($dir)) {
        throw new BoardError(
            '첨부 저장 폴더에 쓸 수 없습니다: ' . $dir .
            "\n웹서버 계정 소유로 바꾸거나 쓰기 권한을 주세요.", 500);
    }
    return $dir;
}

/**
 * 실제로 올릴 수 있는 크기.
 *
 * php.ini 의 upload_max_filesize / post_max_size 기본값은 2M / 8M 이라
 * 우리가 20MB 를 허용해도 그 전에 막힌다. 게다가 post_max_size 를 넘기면
 * PHP 가 본문을 통째로 버려서 $_FILES 가 비고 아무 오류도 안 남는다.
 * 화면에 적는 한계와 서버가 실제로 받는 한계를 같게 맞추려고 여기서 계산한다.
 */
function board_max_upload_bytes()
{
    $ours = NOTICE_MAX_MB * 1024 * 1024;
    $ini  = min(board_ini_bytes(ini_get('upload_max_filesize')),
                board_ini_bytes(ini_get('post_max_size')));
    return $ini > 0 ? min($ours, $ini) : $ours;
}

/** '2M', '8M', '512K' 같은 php.ini 표기를 바이트로 */
function board_ini_bytes($v)
{
    $v = trim((string)$v);
    if ($v === '') return 0;
    $n = (int)$v;
    switch (strtolower(substr($v, -1))) {
        case 'g': return $n * 1024 * 1024 * 1024;
        case 'm': return $n * 1024 * 1024;
        case 'k': return $n * 1024;
    }
    return $n;
}

function board_max_upload_label()
{
    $mb = board_max_upload_bytes() / 1024 / 1024;
    return ($mb >= 1 ? round($mb) : round($mb, 1)) . 'MB';
}

function board_ensure_allowed($filename)
{
    $ext = strtolower(pathinfo((string)$filename, PATHINFO_EXTENSION));
    if (!in_array($ext, NOTICE_ALLOWED_EXT, true)) {
        throw new BoardError('올릴 수 없는 형식입니다 (' . implode(', ', NOTICE_ALLOWED_EXT) . ')', 422);
    }
    return $ext;
}

/** 저장 파일명은 서버가 만든다. 원본명은 DB 에만 둔다. */
function board_save_file($noticeId, array $file)
{
    if ((int)portal_db()->query("SELECT COUNT(*) FROM portal_notice_file WHERE notice_id = " . (int)$noticeId)
                        ->fetchColumn() >= NOTICE_MAX_FILES) {
        throw new BoardError('첨부는 공지 하나에 ' . NOTICE_MAX_FILES . '개까지입니다', 422);
    }

    $code = isset($file['error']) ? $file['error'] : UPLOAD_ERR_NO_FILE;
    if ($code !== UPLOAD_ERR_OK) {
        if ($code === UPLOAD_ERR_INI_SIZE || $code === UPLOAD_ERR_FORM_SIZE) {
            throw new BoardError(board_max_upload_label() . ' 까지 올릴 수 있습니다 '
                . '(php.ini upload_max_filesize=' . ini_get('upload_max_filesize') . ')', 413);
        }
        if ($code === UPLOAD_ERR_NO_TMP_DIR) {
            throw new BoardError('서버에 임시 폴더가 없습니다 (php.ini upload_tmp_dir)', 500);
        }
        if ($code === UPLOAD_ERR_CANT_WRITE) {
            throw new BoardError('서버가 임시 파일을 쓰지 못했습니다 (디스크/권한 확인)', 500);
        }
        throw new BoardError('파일을 올리지 못했습니다 (오류 코드 ' . $code . ')', 400);
    }
    if ((int)$file['size'] > board_max_upload_bytes()) {
        throw new BoardError(board_max_upload_label() . ' 까지 올릴 수 있습니다', 413);
    }

    $original = basename((string)(isset($file['name']) ? $file['name'] : '첨부'));
    $ext      = board_ensure_allowed($original);
    $stored   = (int)$noticeId . '_' . bin2hex(random_bytes(16)) . '.' . $ext;

    if (!move_uploaded_file($file['tmp_name'], board_upload_dir() . '/' . $stored)) {
        throw new BoardError('파일을 저장하지 못했습니다', 500);
    }

    portal_db()->prepare(
        "INSERT INTO portal_notice_file (notice_id, orig_name, stored_name, file_size, created_at)
         VALUES (?, ?, ?, ?, NOW())"
    )->execute([(int)$noticeId, $original, $stored, (int)$file['size']]);

    return (int)portal_db()->lastInsertId();
}

function board_file($fileId)
{
    $st = portal_db()->prepare("SELECT * FROM portal_notice_file WHERE id = ?");
    $st->execute([(int)$fileId]);
    $f = $st->fetch();
    if (!$f) {
        throw new BoardError('파일을 찾을 수 없습니다', 404);
    }
    return $f;
}

function board_delete_file($fileId)
{
    $f    = board_file($fileId);
    $path = board_upload_dir() . '/' . basename($f['stored_name']);
    if (is_file($path)) {
        @unlink($path);
    }
    portal_db()->prepare("DELETE FROM portal_notice_file WHERE id = ?")->execute([(int)$fileId]);
}

/** 경로 이탈 검사. DB 값이라도 그대로 이어 붙이지 않는다. */
function board_resolve_file($stored)
{
    $root = board_upload_dir();
    $path = realpath($root . '/' . basename((string)$stored));
    if (!$path || strncmp($path, $root, strlen($root)) !== 0 || !is_file($path)) {
        throw new BoardError('파일을 찾을 수 없습니다', 404);
    }
    return $path;
}

/** 화이트리스트 밖은 브라우저에서 열지 않고 강제로 내려받게 한다 */
function board_disposition($name)
{
    $ext    = strtolower(pathinfo((string)$name, PATHINFO_EXTENSION));
    $inline = isset(NOTICE_INLINE_TYPES[$ext]);
    return [
        $inline ? NOTICE_INLINE_TYPES[$ext] : 'application/octet-stream',
        ($inline ? 'inline' : 'attachment') . "; filename*=UTF-8''" . rawurlencode($name),
    ];
}

// ---------------------------------------------------------------------
// 중요 일정
// ---------------------------------------------------------------------

/**
 * 일정 종류. 제목·메모에서 낱말을 보고 고른다.
 *
 * 모든 카드가 같은 색이면 "다른 일정" 이라는 게 안 읽힌다. 종류마다 색과
 * 그림을 달리해 훑기만 해도 무슨 일인지 알게 하려는 것이다.
 *
 * 순서가 곧 우선순위다. 위에서부터 먼저 걸리는 것이 이긴다 —
 * '장례'(조사)가 '행사' 보다 위에 있어야 축하 색이 붙는 일이 없다.
 * 아무것도 안 걸리면 'etc' 로 떨어진다. 낱말은 그냥 덧붙이면 된다.
 */
const BOARD_EVENT_KINDS = [
    // '부친상' 처럼 '…상' 으로만 적는 경우가 가장 흔한데 그동안 아무 낱말에도
    // 안 걸려 달력 아이콘이 붙었다. 상을 당한 일정에는 그러면 안 된다.
    'condolence' => ['label' => '조사',   'icon' => '🕯',
                     'words' => ['장례', '부고', '발인', '빈소', '조문', '별세', '상중',
                                 '부친상', '모친상', '조부상', '조모상', '빙부상', '빙모상',
                                 '시부상', '시모상', '장인상', '장모상']],
    // '축 ' 과 '축!' 은 빈칸·느낌표가 뒤따라야 걸린다 — '축구', '축사' 까지
    // 끌려오지 않게 하려는 것이다. '추카' 와 '경축' 은 그럴 걱정이 없어 그냥 넣는다.
    'congrats'   => ['label' => '경사',   'icon' => '🎉',
                     'words' => ['결혼', '청첩', '혼례', '돌잔치', '출산', '승진', '개업', '생일',
                                 '추카', '경축', '축 ', '축!']],
    'holiday'    => ['label' => '휴무',   'icon' => '🌴',
                     'words' => ['휴무', '연휴', '휴가', '창립', '공휴일', '대체휴일', '워라밸']],
    'deadline'   => ['label' => '마감',   'icon' => '⏳',
                     'words' => ['마감', '제출', '만료', '접수', '신청 기한', '기한', '까지']],
    'edu'        => ['label' => '교육',   'icon' => '🎓',
                     'words' => ['교육', '세미나', '특강', '연수', '강의', '수료', '자격']],
    'ops'        => ['label' => '작업',   'icon' => '🛠',
                     'words' => ['점검', '배포', '릴리스', '오픈', '이전', '이사', '서버', '작업']],
    // 아이콘은 '색이 기본' 인 글자를 고른다. 처음 쓴 U+1F37D(🍽)는
    // Emoji_Presentation 이 아니라 윈도에서 검은 글자꼴로 그려져 안 읽혔다.
    'meal'       => ['label' => '회식',   'icon' => '🍜',
                     'words' => ['먹자', '회식', '만찬', '오찬', '다과', '뒤풀이', '맛집', '시식']],
    'event'      => ['label' => '행사',   'icon' => '🎈',
                     'words' => ['워크숍', '워크샵', 'MT', '행사', '체육대회', '송년', '신년', '축제', '간담회']],
    'meeting'    => ['label' => '회의',   'icon' => '📋',
                     'words' => ['회의', '미팅', '보고', '리뷰', '킥오프', '발표', '면담', '평가']],
];

function board_event_kind($text)
{
    $text = (string)$text;
    foreach (BOARD_EVENT_KINDS as $key => $def) {
        foreach ($def['words'] as $w) {
            if (mb_stripos($text, $w) !== false) {
                return ['key' => $key, 'label' => $def['label'], 'icon' => $def['icon']];
            }
        }
    }
    return ['key' => 'etc', 'label' => '일정', 'icon' => '📅'];
}

/**
 * 첫 화면에 띄울 일정.
 *
 * 오늘 이후로 다가오는 것만, 가까운 순으로 준다. 여러 날에 걸친 일정은
 * 마지막 날까지 "진행중" 으로 남긴다 — 워크숍 둘째 날에 사라지면 곤란하다.
 */
function board_events($limit = 4)
{
    $sql = "SELECT * FROM portal_event
             WHERE COALESCE(ends_on, starts_on) >= CURDATE()
             ORDER BY starts_on ASC, id ASC";
    if ($limit > 0) {
        $sql .= ' LIMIT ' . (int)$limit;
    }
    return array_map('board_decorate_event', portal_db()->query($sql)->fetchAll());
}

/** 관리 화면용 — 지난 것까지 전부, 최근 것부터 */
function board_all_events()
{
    return array_map('board_decorate_event',
        portal_db()->query("SELECT * FROM portal_event ORDER BY starts_on DESC, id DESC")->fetchAll());
}

/* ─────────────────────────────────────────────────────────────
   미리 축하 — 경사가 주말·공휴일이면 그 앞 마지막 평일에 터뜨린다.

   토·일이면 금요일, 월요일이 공휴일이면 그 전 금요일. "쉬는 날이면 그 앞의
   마지막 평일" 이라는 한 규칙으로 둘 다 걸린다. 연휴 한가운데도 마찬가지다.
   ───────────────────────────────────────────────────────────── */

/**
 * 날짜가 고정된 국경일.
 *
 * 설날·추석·부처님오신날은 음력이라 여기서 셈할 수 없다. 그런 날은
 *   1) config.php 의 'holidays' 목록, 또는
 *   2) 달력에 등록된 휴무 일정(board_event_kind 가 holiday 로 고른 것)
 * 으로 알아낸다. 둘 다 없으면 그날을 평일로 보고 당일에 축하한다 —
 * 놓쳐도 축하가 하루 늦을 뿐이라 굳이 음력 표를 들고 있지 않는다.
 */
const FIXED_HOLIDAYS = ['01-01', '03-01', '05-05', '06-06', '08-15', '10-03', '10-09', '12-25'];

/** 쉬는 날 모음. ['2026-10-03' => true, …] 한 번 만들고 재사용한다. */
function board_holiday_set()
{
    static $set = null;
    if ($set !== null) { return $set; }
    $set = [];

    // 1) 날짜가 고정된 국경일 — 올해와 내년치면 넉넉하다
    $y = (int)date('Y');
    foreach ([$y, $y + 1] as $year) {
        foreach (FIXED_HOLIDAYS as $md) { $set[$year . '-' . $md] = true; }
    }

    // 2) config.php 에 적어 둔 날 (음력 명절 등)
    $cfg = require dirname(__DIR__) . '/config.php';
    if (isset($cfg['holidays']) && is_array($cfg['holidays'])) {
        foreach ($cfg['holidays'] as $d) {
            if (board_is_date((string)$d)) { $set[(string)$d] = true; }
        }
    }

    // 3) 달력에 등록된 휴무 일정 — 연휴는 시작일부터 종료일까지 하루씩 전부
    $rows = portal_db()->query(
        "SELECT title, memo, starts_on, ends_on FROM portal_event
          WHERE COALESCE(ends_on, starts_on) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)"
    )->fetchAll();
    foreach ($rows as $r) {
        if (board_event_kind($r['title'] . ' ' . (string)$r['memo'])['key'] !== 'holiday') {
            continue;
        }
        $d   = new DateTimeImmutable($r['starts_on']);
        $end = new DateTimeImmutable($r['ends_on'] ?: $r['starts_on']);
        for ($i = 0; $i < 60 && $d <= $end; $i++) {
            $set[$d->format('Y-m-d')] = true;
            $d = $d->modify('+1 day');
        }
    }
    return $set;
}

/** 토·일과 쉬는 날을 뺀 나머지가 평일이다. */
function board_is_workday($ymd, array $holidays)
{
    $w = (int)(new DateTimeImmutable($ymd))->format('N');   // 1=월 … 7=일
    return $w <= 5 && !isset($holidays[$ymd]);
}

/**
 * 미리 축하할 날.
 *
 * 일정 당일이 평일이면 null 이다 — 그날 축하하면 되므로 미리 할 일이 없다.
 * 주말이나 쉬는 날이면 그 앞의 마지막 평일을 돌려준다.
 */
function board_cheer_early_on($ymd, array $holidays)
{
    if (board_is_workday($ymd, $holidays)) { return null; }
    $d = new DateTimeImmutable($ymd);
    for ($i = 0; $i < 14; $i++) {          // 연휴가 아무리 길어도 두 주면 닿는다
        $d = $d->modify('-1 day');
        $back = $d->format('Y-m-d');
        if (board_is_workday($back, $holidays)) { return $back; }
    }
    return null;                            // 못 찾으면 미리 축하는 건너뛴다
}

/**
 * D-day 를 붙인다. 날짜만 다루므로 시각은 자정으로 맞춰 계산한다 —
 * 그러지 않으면 오후에 본 '내일' 이 D-0 으로 나온다.
 */
function board_decorate_event(array $e)
{
    $today  = new DateTimeImmutable('today');
    $starts = new DateTimeImmutable($e['starts_on']);
    $ends   = new DateTimeImmutable($e['ends_on'] ?: $e['starts_on']);

    $days = (int)$today->diff($starts)->format('%r%a');

    $e['id']       = (int)$e['id'];
    $e['kind']     = board_event_kind($e['title'] . ' ' . (string)$e['memo']);
    $e['dday']     = $days;
    $e['ongoing']  = $days <= 0 && $today <= $ends;   // 시작했고 아직 안 끝남
    $e['dday_label'] = $days > 0 ? 'D-' . $days : ($days === 0 ? 'D-DAY' : 'D+' . abs($days));
    if ($e['ongoing'] && $days < 0) {
        $e['dday_label'] = '진행중';
    }
    // 임박할수록 뜨겁게. 화면에서 색을 고르는 기준이 된다.
    $e['heat'] = $days < 0 ? 'past' : ($days === 0 ? 'today' : ($days <= 7 ? 'soon' : 'later'));
    if ($e['ongoing']) {
        $e['heat'] = 'today';
    }

    // 오늘 폭죽을 터뜨릴 일정인지. 경사만 따진다.
    //   today = 바로 오늘이 그날 / early = 그날이 쉬는 날이라 오늘 미리
    $e['cheer'] = null;
    if ($e['kind']['key'] === 'congrats') {
        $ymd = $today->format('Y-m-d');
        if ($e['starts_on'] === $ymd) {
            $e['cheer'] = 'today';
        } elseif (board_cheer_early_on($e['starts_on'], board_holiday_set()) === $ymd) {
            $e['cheer'] = 'early';
        }
    }
    return $e;
}

function board_save_event($id, array $in, array $u)
{
    $title = trim((string)(isset($in['title']) ? $in['title'] : ''));
    $start = trim((string)(isset($in['starts_on']) ? $in['starts_on'] : ''));
    $end   = trim((string)(isset($in['ends_on']) ? $in['ends_on'] : ''));
    $place = trim((string)(isset($in['place']) ? $in['place'] : ''));
    $memo  = trim((string)(isset($in['memo']) ? $in['memo'] : ''));

    if ($title === '')           { throw new BoardError('일정 이름을 입력하세요', 422); }
    if (mb_strlen($title) > 200) { throw new BoardError('일정 이름은 200자 이내로 입력하세요', 422); }
    if (!board_is_date($start))  { throw new BoardError('시작일을 올바르게 입력하세요', 422); }
    if ($end !== '' && !board_is_date($end)) { throw new BoardError('종료일을 올바르게 입력하세요', 422); }
    if ($end !== '' && $end < $start)        { throw new BoardError('종료일이 시작일보다 빠릅니다', 422); }
    if (mb_strlen($place) > 120) { throw new BoardError('장소는 120자 이내로 입력하세요', 422); }
    if (mb_strlen($memo) > 500)  { throw new BoardError('메모는 500자 이내로 입력하세요', 422); }

    $args = [$title, $start, $end ?: null, $place ?: null, $memo ?: null];

    if ($id) {
        $args[] = (int)$id;
        portal_db()->prepare(
            "UPDATE portal_event SET title = ?, starts_on = ?, ends_on = ?, place = ?, memo = ?,
                    updated_at = NOW() WHERE id = ?"
        )->execute($args);
        return (int)$id;
    }

    $args[] = $u['email'];
    portal_db()->prepare(
        "INSERT INTO portal_event (title, starts_on, ends_on, place, memo, author_email, created_at)
         VALUES (?, ?, ?, ?, ?, ?, NOW())"
    )->execute($args);
    return (int)portal_db()->lastInsertId();
}

function board_delete_event($id)
{
    portal_db()->prepare("DELETE FROM portal_event WHERE id = ?")->execute([(int)$id]);
}

function board_is_date($s)
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$s)) return false;
    list($y, $m, $d) = array_map('intval', explode('-', $s));
    return checkdate($m, $d, $y);
}

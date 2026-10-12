<?php
/**
 * 웹 푸시 시험 발송 — 브라우저에서 직접 보내 보고 결과(푸시 서비스 응답 코드)를 본다.
 *
 * 받는 사람은 처음에 **로그인한 본인**이 골라져 있다.
 *  - 포털 관리자는 여러 사람을 골라 보낼 수 있다.
 *  - 그 밖의 사람은 본인에게만 보낸다. 아무나 남에게 알림을 보내면 안 되기 때문이다.
 * 받는 사람이 그 종류를 꺼 두었으면 빠진다 — 본인 설정이 먼저다.
 *
 * 다른 사람의 구독 현황과 발송 기록은 포털 관리자에게만 보인다. 일반 사용자는
 * 자기 기록만 본다.
 *
 * 이 페이지에서도 "이 기기에서 알림 받기" 를 할 수 있다(마이페이지와 같은 pwa.js).
 */
require_once __DIR__ . '/../core/board.php';
require_once __DIR__ . '/../core/push.php';
require_once __DIR__ . '/../core/pwa.php';
header('Cache-Control: no-store');

$u = current_portal_user();
if (!$u) {
    header('Location: ../index.php?need_login=push');
    exit;
}
$isAdmin = board_is_admin($u['email']);
// 여러 사람에게 보낼 수 있는 사람 — 포털 관리자
$canPick = $isAdmin;
$selfId  = (int)$u['id'];

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

if (empty($_SESSION['push_test_csrf'])) $_SESSION['push_test_csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['push_test_csrf'];

$form = [
    'kind'  => 'test.ping',
    'title' => '알림 시험',
    'body'  => date('H:i:s') . ' 에 보낸 시험 알림입니다.',
    'url'   => './',
    'tag'   => '',
];
$result = null;
$error  = null;
$toIds  = [$selfId];      // 고른 사람. 처음에는 본인

// 고를 수 있는 사람 목록(기기 수 포함). 고를 수 없는 사람에게는 필요 없다.
$people = $canPick ? portal_db()->query("
    SELECT u.id, u.name, u.email, COUNT(s.id) AS devices
      FROM portal_users u
 LEFT JOIN portal_push_subscription s ON s.user_id = u.id
  GROUP BY u.id, u.name, u.email
  ORDER BY (u.id = {$selfId}) DESC, devices DESC, u.name
")->fetchAll() : [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($form as $k => $_) $form[$k] = trim((string)($_POST[$k] ?? ''));
    if ($canPick) {
        // 목록에 있는 사람만 받는다 — 화면을 고쳐 다른 번호를 넣어도 걸러진다
        $valid = array_map('intval', array_column($people, 'id'));
        $toIds = array_values(array_intersect(array_map('intval', (array)($_POST['to'] ?? [])), $valid));
    }
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        $error = '화면이 오래되었습니다. 새로고침한 뒤 다시 보내 주세요.';
    } elseif (!$toIds) {
        $error = '받는 사람을 한 명 이상 고르세요.';
    } else {
        $result = push_send([
            'kind'  => $form['kind'],
            'title' => $form['title'],
            'body'  => $form['body'],
            'url'   => $form['url'],
            'tag'   => $form['tag'],
            'to'    => $toIds,              // 고를 수 없는 사람은 언제나 본인 하나
        ]);
    }
}

$pdo = portal_db();
$vapidPath = push_vapid_path();
$publicKey = null;
try { $publicKey = push_public_key(); } catch (Throwable $e) { $error = $error ?: $e->getMessage(); }

// 다른 사람의 구독·기록은 관리자에게만. 일반 사용자는 자기 것만 본다.
$accounts = $isAdmin ? $pdo->query("
    SELECT u.id, u.name, u.email, COUNT(s.id) AS devices, MAX(s.last_ok_at) AS last_ok
      FROM portal_users u
 LEFT JOIN portal_push_subscription s ON s.user_id = u.id
  GROUP BY u.id, u.name, u.email
  ORDER BY devices DESC, u.name
")->fetchAll() : [];
$myDevices = push_subscriptions_of((int)$u['id']);
$logSql = "SELECT l.*, u.email FROM portal_push_log l LEFT JOIN portal_users u ON u.id = l.user_id"
        . ($isAdmin ? '' : ' WHERE l.user_id = ?') . " ORDER BY l.id DESC LIMIT 30";
$logSt = $pdo->prepare($logSql);
$logSt->execute($isAdmin ? [] : [(int)$u['id']]);
$logs = $logSt->fetchAll();
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>웹 푸시 시험 — blue-iWorks</title>
<link rel="icon" href="../styles/favicon.ico">
<?= pwa_head_tags('../') ?>
<style>
  body{font-family:system-ui,-apple-system,"Malgun Gothic",sans-serif;margin:0;padding:24px;background:#f6f7fb;color:#1d2433}
  h1{font-size:20px;margin:0 0 4px} h2{font-size:15px;margin:28px 0 10px}
  .sub{color:#667085;font-size:13px;margin-bottom:18px}
  .card{background:#fff;border:1px solid #e4e7ec;border-radius:12px;padding:16px 18px;max-width:980px}
  .grid{display:grid;grid-template-columns:110px 1fr;gap:10px 12px;align-items:center}
  label{font-size:13px;color:#475467}
  input,select,textarea{font:inherit;font-size:14px;padding:8px 10px;border:1px solid #d0d5dd;border-radius:8px;width:100%;box-sizing:border-box}
  textarea{min-height:60px}
  small{color:#667085}
  button{font:inherit;font-size:14px;padding:9px 18px;border-radius:999px;border:0;background:#1C5DE5;color:#fff;cursor:pointer}
  button.ghost{background:#fff;color:#1d2433;border:1px solid #d0d5dd}
  button:disabled{opacity:.45;cursor:not-allowed}
  table{border-collapse:collapse;width:100%;font-size:13px}
  th,td{border-bottom:1px solid #eef0f4;padding:6px 8px;text-align:left;vertical-align:top}
  th{color:#667085;font-weight:600}
  code{font-size:12px;word-break:break-all}
  .ok{color:#067647} .bad{color:#b42318} .muted{color:#98a2b3}
  .msg{padding:10px 14px;border-radius:8px;margin:12px 0;max-width:980px}
  .msg.bad{background:#fef3f2} .msg.ok{background:#ecfdf3}
  .row{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
  button.sm{font-size:12.5px;padding:5px 12px}
  .pick-acts{margin-bottom:8px}
  .people{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:6px;margin-bottom:6px}
  label.person{display:flex;gap:8px;align-items:flex-start;padding:7px 10px;border:1px solid #e4e7ec;border-radius:8px;cursor:pointer;color:#1d2433}
  label.person input{width:auto;margin:3px 0 0}
  label.person small{display:block}
  label.person.nodev{opacity:.6}
  label.person:has(input:checked){border-color:#1C5DE5;background:#f3f7ff;opacity:1}
  .me{font-size:11px;font-weight:700;color:#1C5DE5;background:#e8f0fe;border-radius:999px;padding:0 6px}
</style>
</head>
<body>
<h1>🔔 웹 푸시 시험</h1>
<div class="sub"><?= h($u['name']) ?>(<?= h($u['email']) ?>) · <a href="../index.php">포털로</a></div>

<?php if ($error): ?><div class="msg bad"><?= h($error) ?></div><?php endif; ?>

<div class="card">
  <div class="grid">
    <label>내 기기</label>
    <div><?php if ($myDevices): ?>
        <?= h(implode(', ', array_column($myDevices, 'label'))) ?> <small>(<?= count($myDevices) ?>대)</small>
      <?php else: ?><span class="bad">없음</span> <small>— 아래 "이 기기에서 알림 받기" 를 먼저 누르세요</small><?php endif; ?></div>
    <label>VAPID 키</label>
    <div><?php if (is_file($vapidPath)): ?>
        <span class="ok">있음</span> <small><?= h(date('Y-m-d H:i', filemtime($vapidPath))) ?> 생성</small>
      <?php else: ?><span class="bad">없음</span><?php endif; ?>
      <br><code><?= h($publicKey) ?></code></div>
    <label>이 기기</label>
    <div class="row">
      <button type="button" class="ghost" id="dev-btn" disabled>확인 중…</button>
      <small id="dev-state"></small>
    </div>
  </div>
</div>

<h2>보내기</h2>
<form method="post" class="card">
  <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
  <div class="grid">
    <label>종류</label>
    <select name="kind">
      <?php foreach (push_kinds() as $k): ?>
        <option value="<?= h($k['key']) ?>" <?= $k['key'] === $form['kind'] ? 'selected' : '' ?>><?= h($k['label']) ?> (<?= h($k['key']) ?>)</option>
      <?php endforeach; ?>
    </select>
    <label>제목</label>
    <input name="title" value="<?= h($form['title']) ?>" required>
    <label>본문</label>
    <textarea name="body"><?= h($form['body']) ?></textarea>
    <label>이동 주소</label>
    <div><input name="url" value="<?= h($form['url']) ?>">
      <small>알림을 누르면 열 주소. 포털 루트 기준(예: <code>dti/index.php</code>, <code>learn/index.php</code>) 또는 https:// 절대주소. 비우면 포털 첫 화면.</small></div>
    <label>tag</label>
    <div><input name="tag" value="<?= h($form['tag']) ?>">
      <small>같은 tag 의 알림은 기기에서 하나로 겹쳐집니다. 비우면 매번 새 알림.</small></div>
    <label>받는 사람</label>
    <?php if ($canPick): ?>
    <div>
      <div class="row pick-acts">
        <button type="button" class="ghost sm" onclick="pick('me')">나만</button>
        <button type="button" class="ghost sm" onclick="pick('dev')">기기 있는 사람</button>
        <button type="button" class="ghost sm" onclick="pick('all')">모두</button>
        <button type="button" class="ghost sm" onclick="pick('none')">해제</button>
        <small id="pick-n"></small>
      </div>
      <div class="people">
        <?php foreach ($people as $pp): $pid = (int)$pp['id']; ?>
          <label class="person<?= $pp['devices'] ? '' : ' nodev' ?>">
            <input type="checkbox" name="to[]" value="<?= $pid ?>" data-dev="<?= (int)$pp['devices'] ?>"
                   data-me="<?= $pid === $selfId ? 1 : 0 ?>" <?= in_array($pid, $toIds, true) ? 'checked' : '' ?>>
            <span><b><?= h($pp['name']) ?></b><?= $pid === $selfId ? ' <span class="me">나</span>' : '' ?>
              <small><?= h($pp['email']) ?> · 기기 <?= (int)$pp['devices'] ?>대</small></span>
          </label>
        <?php endforeach; ?>
      </div>
      <small>그 종류의 알림을 꺼 둔 사람은 빠집니다.</small>
    </div>
    <?php else: ?>
    <div><b><?= h($u['name']) ?></b> <small>(<?= h($u['email']) ?>) — 본인에게만 보낼 수 있습니다</small></div>
    <?php endif; ?>
    <span></span>
    <div><button type="submit"><?= $canPick ? '보내기' : '나에게 보내기' ?></button></div>
  </div>
</form>

<?php if ($result !== null): ?>
  <h2>결과</h2>
  <div class="msg <?= $result['sent'] ? 'ok' : 'bad' ?>">
    성공 <?= $result['sent'] ?> · 실패 <?= $result['failed'] ?> · 정리(만료 구독 삭제) <?= $result['pruned'] ?>
    · 알림함 저장 <?= $result['notified'] ?? 0 ?>건 · 대상 기기 <?= $result['recipients'] ?>대
    <?php if (!empty($result['error'])): ?><br><?= h($result['error']) ?><?php endif; ?>
    <?php $off = count($toIds) - (int)($result['notified'] ?? 0); ?>
    <?php if (empty($result['error']) && $off > 0): ?>
      <br>고른 <?= count($toIds) ?>명 중 <?= $off ?>명은 이 종류의 알림을 꺼 두어 빠졌습니다(마이페이지 알림 칸에서 켤 수 있습니다).
    <?php endif; ?>
    <?php if (!$result['recipients'] && !empty($result['notified']) && empty($result['error'])): ?>
      <br>받는 사람에게 알림을 받는 기기가 없습니다. 각자 "이 기기에서 알림 받기" 를 먼저 눌러야 합니다.
      알림함에는 남았습니다(포털 상단바 🔔 단추).
    <?php endif; ?>
  </div>
  <?php if ($result['detail']): ?>
  <div class="card"><table>
    <tr><th>계정</th><th>구독</th><th>결과</th><th>HTTP</th><th>오류</th></tr>
    <?php foreach ($result['detail'] as $d): ?>
      <tr><td><?= h($d['email']) ?></td><td>#<?= h($d['sub_id']) ?></td>
        <td class="<?= $d['result'] === 'sent' ? 'ok' : 'bad' ?>"><?= h($d['result']) ?></td>
        <td><?= h($d['status'] ?? '—') ?></td><td><code><?= h($d['error']) ?></code></td></tr>
    <?php endforeach; ?>
  </table></div>
  <?php endif; ?>
<?php endif; ?>

<?php if ($isAdmin): ?>
<h2>계정별 구독 <small>(관리자)</small></h2>
<div class="card"><table>
  <tr><th>이름</th><th>이메일</th><th>기기</th><th>마지막 성공</th></tr>
  <?php foreach ($accounts as $a): ?>
    <tr class="<?= $a['devices'] ? '' : 'muted' ?>">
      <td><?= h($a['name']) ?></td><td><?= h($a['email']) ?></td><td><?= (int)$a['devices'] ?></td>
      <td><?= h($a['last_ok'] ?? '—') ?></td></tr>
  <?php endforeach; ?>
</table></div>
<?php endif; ?>

<h2><?= $isAdmin ? '최근 발송 기록 <small>(전체)</small>' : '내 발송 기록' ?></h2>
<div class="card"><table>
  <tr><th>시각</th><th>계정</th><th>종류</th><th>제목</th><th>이동 주소</th><th>HTTP</th><th>오류</th></tr>
  <?php if (!$logs): ?><tr><td colspan="7" class="muted">아직 없습니다.</td></tr><?php endif; ?>
  <?php foreach ($logs as $l): ?>
    <tr><td><?= h($l['created_at']) ?></td><td><?= h($l['email'] ?? '—') ?></td><td><?= h($l['kind']) ?></td>
      <td><?= h($l['title']) ?></td><td><code><?= h($l['url']) ?></code></td>
      <td class="<?= $l['status_code'] >= 200 && $l['status_code'] < 300 ? 'ok' : 'bad' ?>"><?= h($l['status_code'] ?? '—') ?></td>
      <td><code><?= h($l['error']) ?></code></td></tr>
  <?php endforeach; ?>
</table></div>

<script>
const PUBLIC_KEY = <?= json_encode($publicKey) ?>;
async function devRender(){
  const btn = document.getElementById('dev-btn'), st = document.getElementById('dev-state');
  if (!window.bluePush || !bluePush.supported()) {
    btn.textContent = '알림 받기 불가'; btn.disabled = true;
    st.textContent = 'https(또는 localhost)가 아니거나 브라우저가 웹 푸시를 지원하지 않습니다. iOS 는 홈 화면에 추가한 뒤 그 아이콘으로 열어야 합니다.';
    return;
  }
  if (bluePush.permission() === 'denied') {
    btn.textContent = '차단됨'; btn.disabled = true;
    st.textContent = '브라우저에서 이 사이트 알림이 차단되어 있습니다.';
    return;
  }
  const sub = await bluePush.status().catch(() => null);
  btn.disabled = false;
  btn.dataset.on = sub ? '1' : '';
  btn.textContent = sub ? '이 기기 알림 끄기' : '이 기기에서 알림 받기';
  st.textContent = sub ? '이 기기에서 받는 중' : '이 기기는 받지 않음';
}
document.getElementById('dev-btn').addEventListener('click', async (e) => {
  const btn = e.currentTarget; btn.disabled = true;
  try {
    if (btn.dataset.on) await bluePush.unsubscribe(); else await bluePush.subscribe(PUBLIC_KEY);
    location.reload();
  } catch (err) { alert(err.message); devRender(); }
});
window.addEventListener('load', devRender);

// 받는 사람 빠른 선택 (고를 수 있는 사람에게만 목록이 있다)
const boxes = () => Array.from(document.querySelectorAll('input[name="to[]"]'));
function pick(mode) {
  boxes().forEach(b => {
    b.checked = mode === 'all' || (mode === 'me' && b.dataset.me === '1') || (mode === 'dev' && +b.dataset.dev > 0);
  });
  pickCount();
}
function pickCount() {
  const el = document.getElementById('pick-n');
  if (el) el.textContent = boxes().filter(b => b.checked).length + '명 선택';
}
document.addEventListener('change', (e) => { if (e.target.name === 'to[]') pickCount(); });
pickCount();
</script>
</body>
</html>

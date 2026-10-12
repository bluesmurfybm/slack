<?php
/**
 * 웹 푸시 시험 발송 — 브라우저에서 직접 보내 보고 결과(푸시 서비스 응답 코드)를 본다.
 *
 * 들어올 수 있는 사람: 포털 관리자, 그리고 시험 기간에는 PUSH_TEST_ALLOW 에 든 계정.
 * 받는 사람은 언제나 core/push.php 의 허용 목록으로 걸러진다 — 여기서 '전원' 을
 * 골라도 시험 기간에는 허용된 계정에만 간다.
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
$allowed = board_is_admin($u['email']) || (PUSH_TEST_ALLOW && push_can_receive($u['email']));
if (!$allowed) {
    http_response_code(403);
    echo '<!DOCTYPE html><meta charset="utf-8"><p>포털 관리자나 알림 시험 계정만 쓸 수 있습니다.</p>';
    exit;
}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

if (empty($_SESSION['push_test_csrf'])) $_SESSION['push_test_csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['push_test_csrf'];

$form = [
    'kind'  => 'test.ping',
    'title' => '알림 시험',
    'body'  => date('H:i:s') . ' 에 보낸 시험 알림입니다.',
    'url'   => './',
    'tag'   => '',
    'to'    => $u['email'],
];
$result = null;
$error  = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($form as $k => $_) $form[$k] = trim((string)($_POST[$k] ?? ''));
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        $error = '화면이 오래되었습니다. 새로고침한 뒤 다시 보내 주세요.';
    } else {
        $result = push_send([
            'kind'  => $form['kind'],
            'title' => $form['title'],
            'body'  => $form['body'],
            'url'   => $form['url'],
            'tag'   => $form['tag'],
            'to'    => $form['to'] === '' ? null : $form['to'],
        ]);
    }
}

$pdo = portal_db();
$vapidPath = push_vapid_path();
$publicKey = null;
try { $publicKey = push_public_key(); } catch (Throwable $e) { $error = $error ?: $e->getMessage(); }

$accounts = $pdo->query("
    SELECT u.id, u.name, u.email, COUNT(s.id) AS devices, MAX(s.last_ok_at) AS last_ok
      FROM portal_users u
 LEFT JOIN portal_push_subscription s ON s.user_id = u.id
  GROUP BY u.id, u.name, u.email
  ORDER BY devices DESC, u.name
")->fetchAll();
$logs = $pdo->query("
    SELECT l.*, u.email FROM portal_push_log l LEFT JOIN portal_users u ON u.id = l.user_id
     ORDER BY l.id DESC LIMIT 30
")->fetchAll();
$allowSubscribed = 0;
foreach ($accounts as $a) if ($a['devices'] > 0 && push_can_receive($a['email'])) $allowSubscribed++;
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
</style>
</head>
<body>
<h1>🔔 웹 푸시 시험</h1>
<div class="sub"><?= h($u['name']) ?>(<?= h($u['email']) ?>) · <a href="../index.php">포털로</a></div>

<?php if ($error): ?><div class="msg bad"><?= h($error) ?></div><?php endif; ?>

<div class="card">
  <div class="grid">
    <label>허용 목록</label>
    <div><?= PUSH_TEST_ALLOW ? h(implode(', ', PUSH_TEST_ALLOW)) : '<b>비어 있음 — 전원 발송</b>' ?>
      <small> · 허용·구독 중인 계정 <?= $allowSubscribed ?>명</small></div>
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
    <div><input name="to" value="<?= h($form['to']) ?>">
      <small>이메일을 콤마로. 비우면 이 종류를 켜 둔 전원(시험 기간에는 허용 목록 안에서만).</small></div>
    <span></span>
    <div><button type="submit">보내기</button></div>
  </div>
</form>

<?php if ($result !== null): ?>
  <h2>결과</h2>
  <div class="msg <?= $result['sent'] ? 'ok' : 'bad' ?>">
    성공 <?= $result['sent'] ?> · 실패 <?= $result['failed'] ?> · 정리(만료 구독 삭제) <?= $result['pruned'] ?>
    · 허용 목록 밖 <?= $result['skipped'] ?>명 · 알림함 저장 <?= $result['notified'] ?? 0 ?>명 · 대상 기기 <?= $result['recipients'] ?>
    <?php if (!empty($result['error'])): ?><br><?= h($result['error']) ?><?php endif; ?>
    <?php if (!$result['recipients'] && empty($result['error'])): ?>
      <br>보낼 기기가 없습니다. 받는 사람이 "알림 받기" 를 했는지, 이 종류를 꺼 두지 않았는지 확인하세요.
      <?php if (!empty($result['notified'])): ?>알림함에는 남았습니다(포털 상단바 🔔 단추).<?php endif; ?>
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

<h2>계정별 구독</h2>
<div class="card"><table>
  <tr><th>이름</th><th>이메일</th><th>기기</th><th>마지막 성공</th><th>시험 발송</th></tr>
  <?php foreach ($accounts as $a): ?>
    <tr class="<?= $a['devices'] ? '' : 'muted' ?>">
      <td><?= h($a['name']) ?></td><td><?= h($a['email']) ?></td><td><?= (int)$a['devices'] ?></td>
      <td><?= h($a['last_ok'] ?? '—') ?></td>
      <td><?= push_can_receive($a['email']) ? '<span class="ok">받음</span>' : '<span class="muted">제외</span>' ?></td></tr>
  <?php endforeach; ?>
</table></div>

<h2>최근 발송 기록</h2>
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
</script>
</body>
</html>

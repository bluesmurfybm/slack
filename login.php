<?php
require_once __DIR__ . '/auth.php';

if (current_user()) { header('Location: lists.php'); exit; }

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $r = login_with_token(isset($_POST['token']) ? $_POST['token'] : '');
    if ($r['ok']) { header('Location: lists.php'); exit; }
    $error = $r['error'];
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>로그인 · 유지보수 요청</title>
<style>
  :root { --bg:#f6f7f8; --card:#fff; --line:#e3e5e8; --txt:#1f2328; --muted:#6e7781; --info:#0c447c; --err:#e24b4a; }
  @media (prefers-color-scheme: dark) {
    :root { --bg:#1a1d21; --card:#222529; --line:#383a3f; --txt:#e8e8e8; --muted:#9aa0a6; --info:#85b7eb; }
  }
  * { box-sizing:border-box; }
  body { font-family:-apple-system,"Malgun Gothic","Apple SD Gothic Neo",sans-serif; background:var(--bg); color:var(--txt);
         margin:0; min-height:100vh; display:flex; align-items:center; justify-content:center; padding:24px; }
  .card { background:var(--card); border:1px solid var(--line); border-radius:14px; padding:32px; width:340px; }
  h1 { font-size:18px; margin:0 0 4px; }
  p.sub { font-size:13px; color:var(--muted); margin:0 0 22px; }
  label { font-size:12px; color:var(--muted); display:block; margin-bottom:6px; }
  input { width:100%; height:38px; border:1px solid var(--line); border-radius:9px; background:var(--bg); color:var(--txt);
          font-size:14px; padding:0 12px; }
  button { width:100%; height:40px; margin-top:16px; border:0; border-radius:9px; background:var(--info); color:#fff;
           font-size:14px; font-weight:600; cursor:pointer; }
  .err { color:var(--err); font-size:12px; margin-top:12px; }
  .hint { font-size:11px; color:var(--muted); margin-top:14px; line-height:1.6; }
</style>
</head>
<body>
  <form class="card" method="post">
    <h1>📥 유지보수 요청</h1>
    <p class="sub">개인 Slack 토큰으로 로그인</p>
    <label for="token">Slack User Token</label>
    <input id="token" name="token" type="password" placeholder="xoxp-..." autocomplete="off" autofocus required>
    <button type="submit">로그인</button>
    <?php if ($error): ?><div class="err"><?= htmlspecialchars($error, ENT_QUOTES) ?></div><?php endif; ?>
    <div class="hint">본인 토큰으로 접속하면 댓글이 <b>본인 명의</b>로 등록됩니다. 토큰은 세션에만 보관됩니다.</div>
  </form>
</body>
</html>

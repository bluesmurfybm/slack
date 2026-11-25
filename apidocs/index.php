<?php
require_once __DIR__ . '/../core/auth.php';

if (!current_portal_user()) {
    header('Location: ../index.php?need_login=apidocs');
    exit;
}
?>
<!DOCTYPE html>
<html lang="ko">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>iWorks API 문서</title>
  <link rel="icon" href="../styles/favicon.ico">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/swagger-ui/5.32.15/swagger-ui.min.css"
    integrity="sha512-Pp3kl5D/UAfxfPfbKWeE9PXTs11Ildhz3Bg5rzW4WQ3wO1ETC4YyLL10xOLlEASL+c+CAx9PwrlioR6Hh1mwfw=="
    crossorigin="anonymous" referrerpolicy="no-referrer">
  <style>
    body { margin: 0; background: #fff; }
    .apidocs-top { padding: 12px 20px; border-bottom: 1px solid #e5e7eb; font: 14px sans-serif; }
    .apidocs-top a { color: #1d4ed8; text-decoration: none; }
  </style>
</head>

<body>
  <div class="apidocs-top"><a href="../">← iWorks 로 돌아가기</a> · 토큰은 <a href="../?view=profile">마이페이지 → API 토큰</a>에서 받습니다</div>
  <div id="swagger-ui"></div>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/swagger-ui/5.32.15/swagger-ui-bundle.min.js"
    integrity="sha512-sxyhnvFY/I0Cmp+/VQ1Rebq0QG11vY6xkNDusMYhAGK5fRLMp335CazHF/fubVcwA0r375UAz+RNKd6XYVxVJQ=="
    crossorigin="anonymous" referrerpolicy="no-referrer"></script>
  <script>
    SwaggerUIBundle({
      url: "spec.php",
      dom_id: "#swagger-ui",
      deepLinking: true,
      validatorUrl: null,
      // 토큰이 브라우저 저장소에 남지 않게 한다
      persistAuthorization: false,
    });
  </script>
</body>

</html>

<?php
/** 외부 연동 설정 — 구글 드라이브·피그마 링크를 서버가 읽을 수 있게 한다. 관리자 전용. */

declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/service/Integration.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$user = bs_require_login();
if (!bs_is_admin()) {
    header('Location: index.php?err=denied');
    exit;
}

// ┌──────────────────────────────────────────────────────────────────┐
// │ 비밀을 화면에 뿌리지 않는다                                       │
// │                                                                  │
// │ 여기서 읽는 것은 '있다/없다' 와 연결된 계정뿐이다. client_secret · │
// │ 갱신 토큰 · 피그마 토큰은 어떤 경로로도 내려오지 않는다. 한 번     │
// │ 넣으면 관리자도 다시 못 본다 — 바꾸려면 새로 넣는다.               │
// └──────────────────────────────────────────────────────────────────┘
$store  = new Integration(bs_db());
$google = $store->status(Integration::GOOGLE);
$figma  = $store->status(Integration::FIGMA);
$redir  = bs_oauth_redirect_uri();

// google_oauth.php 가 왕복을 마치고 결과를 들고 돌아온다.
$okMsg  = bs_param_str('ok');
$errMsg = bs_param_str('err');

bs_layout_head(
    $user,
    '외부 연동',
    '과업 편성/현황',
    '구글 드라이브·피그마 링크를 서버가 직접 읽게 합니다. 관리자만 봅니다.',
    'settings'
);
?>

<?php if ($okMsg !== ''): ?>
  <div class="ba-alert ba-alert--ok"><?= h($okMsg) ?></div>
<?php endif; ?>
<?php if ($errMsg !== ''): ?>
  <div class="ba-alert"><?= h($errMsg) ?></div>
<?php endif; ?>

<div class="ba-list" id="ba-settings">

  <p class="ba-head__sub" style="margin-bottom:14px">
    출처 문서에 <b>구글 드라이브·피그마 링크</b>를 올리면 지금은 '분석 안 함' 으로 남습니다.
    서버가 그 문서를 열 권한이 없기 때문입니다. 아래에서 연결하면
    <b>[문서 분석]</b> 이 링크도 읽어 WBS 도출에 씁니다.
    <br>
    연결 전에 올려 둔 링크도 연결한 뒤 <b>[문서 분석]</b> 을 다시 누르면 읽힙니다.
  </p>

  <!-- ============ 구글 드라이브 ============ -->
  <section class="ba-ig">
    <div class="ba-ig__head">
      <h3>구글 드라이브</h3>
      <?php if ($google['connected']): ?>
        <span class="ba-badge ba-badge--ok">연결됨</span>
      <?php elseif ($google['configured']): ?>
        <span class="ba-badge">동의 대기</span>
      <?php else: ?>
        <span class="ba-badge">설정 전</span>
      <?php endif; ?>
    </div>

    <?php if ($google['connected']): ?>
      <p class="ba-head__sub">
        <b><?= h((string)($google['account'] ?: '계정 미확인')) ?></b> 권한으로 읽습니다.
        그 계정이 볼 수 있는 문서만 읽힙니다.
        <?php if ($google['last_ok_at']): ?>
          · 마지막으로 읽은 때 <?= h(bs_date((string)$google['last_ok_at'])) ?>
        <?php endif; ?>
      </p>
    <?php endif; ?>

    <?php if ($google['last_error']): ?>
      <div class="ba-alert" style="margin:8px 0"><?= h((string)$google['last_error']) ?></div>
    <?php endif; ?>

    <details class="ba-ig__how"<?= $google['connected'] ? '' : ' open' ?>>
      <summary>구글 콘솔에서 먼저 할 일</summary>
      <ol>
        <li><a href="https://console.cloud.google.com/" target="_blank" rel="noopener">구글 클라우드 콘솔</a>에서 프로젝트를 하나 만듭니다.</li>
        <li><b>API 및 서비스 → 라이브러리</b> 에서 <b>Google Drive API</b> 를 사용 설정합니다.</li>
        <li><b>OAuth 동의 화면</b> 을 <b>내부</b>(조직 전용)로 만듭니다.
            외부로 만들면 구글 심사를 받아야 할 수 있습니다.</li>
        <li><b>사용자 인증 정보 → OAuth 클라이언트 ID → 웹 애플리케이션</b> 을 만들고,
            <b>승인된 리디렉션 URI</b> 에 아래 주소를 <b>글자 그대로</b> 넣습니다.
            <div class="ba-ig__uri"><code id="ba-ig-redir"><?= h($redir) ?></code>
              <button type="button" class="ba-btn ba-btn--sm" id="ba-ig-copy">복사</button></div>
            한 글자라도 다르면 <code>redirect_uri_mismatch</code> 로 거부됩니다.</li>
        <li>발급된 <b>클라이언트 ID</b> 와 <b>시크릿</b> 을 아래에 넣고 저장한 뒤 <b>[구글 연결]</b> 을 누릅니다.</li>
      </ol>
      <p class="ba-head__sub">
        받는 권한은 <b>읽기 전용(drive.readonly)</b> 하나입니다. 서버가 드라이브에 쓰거나 지우지 않습니다.
      </p>
    </details>

    <div class="ba-ig__form">
      <label class="ba-field ba-field--wide">
        <span>클라이언트 ID</span>
        <input type="text" id="ba-ig-gid" value="" placeholder="000000000000-xxxxxxxx.apps.googleusercontent.com"
               autocomplete="off">
      </label>
      <label class="ba-field ba-field--wide">
        <span>클라이언트 시크릿<?= $google['configured'] ? ' (비워 두면 그대로)' : '' ?></span>
        <input type="password" id="ba-ig-gsecret" value=""
               placeholder="<?= $google['configured'] ? '이미 저장돼 있습니다' : 'GOCSPX-…' ?>"
               autocomplete="new-password">
      </label>
      <div class="ba-ig__btns">
        <button type="button" class="ba-btn" id="ba-ig-gsave">저장</button>
        <?php if ($google['configured']): ?>
          <a class="ba-btn ba-btn--primary" href="api/google_oauth.php?start=1">
            <?= $google['connected'] ? '다시 연결' : '구글 연결' ?></a>
        <?php endif; ?>
        <?php if ($google['configured'] || $google['connected']): ?>
          <button type="button" class="ba-btn ba-btn--danger" data-ig-off="google">연결 끊기</button>
        <?php endif; ?>
      </div>
    </div>
  </section>

  <!-- ============ 피그마 ============ -->
  <section class="ba-ig">
    <div class="ba-ig__head">
      <h3>피그마</h3>
      <?= $figma['connected']
            ? '<span class="ba-badge ba-badge--ok">연결됨</span>'
            : '<span class="ba-badge">설정 전</span>' ?>
    </div>

    <?php if ($figma['last_error']): ?>
      <div class="ba-alert" style="margin:8px 0"><?= h((string)$figma['last_error']) ?></div>
    <?php endif; ?>
    <?php if ($figma['connected'] && $figma['last_ok_at']): ?>
      <p class="ba-head__sub">마지막으로 읽은 때 <?= h(bs_date((string)$figma['last_ok_at'])) ?></p>
    <?php endif; ?>

    <details class="ba-ig__how"<?= $figma['connected'] ? '' : ' open' ?>>
      <summary>피그마에서 먼저 할 일</summary>
      <ol>
        <li>피그마 <b>Settings → Security → Personal access tokens</b> 에서 토큰을 만듭니다.</li>
        <li>권한은 <b>File content: Read only</b> 면 충분합니다.</li>
        <li>만든 토큰을 아래에 넣습니다. <b>그 토큰을 만든 사람이 볼 수 있는 파일만</b> 읽힙니다.</li>
      </ol>
      <p class="ba-head__sub">
        토큰은 만료되지 않지만 만든 사람이 퇴사하면 끊깁니다.
        <b>개인 계정보다 공용 계정에서 만드는 편이 낫습니다.</b>
      </p>
    </details>

    <div class="ba-ig__form">
      <label class="ba-field ba-field--wide">
        <span>개인 접근 토큰</span>
        <input type="password" id="ba-ig-ftoken" value=""
               placeholder="<?= $figma['connected'] ? '이미 저장돼 있습니다' : 'figd_…' ?>"
               autocomplete="new-password">
      </label>
      <div class="ba-ig__btns">
        <button type="button" class="ba-btn" id="ba-ig-fsave">저장</button>
        <?php if ($figma['connected']): ?>
          <button type="button" class="ba-btn ba-btn--danger" data-ig-off="figma">연결 끊기</button>
        <?php endif; ?>
      </div>
    </div>
  </section>

  <!-- ============ 확인 ============ -->
  <section class="ba-ig">
    <div class="ba-ig__head"><h3>링크로 확인하기</h3></div>
    <p class="ba-head__sub">
      실제 주소를 하나 넣어 읽히는지 봅니다. <b>아무것도 저장하지 않습니다</b> —
      프로젝트에 올리기 전에 설정이 맞는지만 확인하는 자리입니다.
    </p>
    <div class="ba-ig__form">
      <label class="ba-field ba-field--wide">
        <span>구글 드라이브 또는 피그마 주소</span>
        <input type="url" id="ba-ig-test" placeholder="https://docs.google.com/spreadsheets/d/…">
      </label>
      <div class="ba-ig__btns">
        <button type="button" class="ba-btn" id="ba-ig-testbtn">읽어 보기</button>
      </div>
    </div>
    <pre class="ba-ig__out" id="ba-ig-out" hidden></pre>
  </section>

  <p class="ba-head__sub" style="margin-top:4px">
    읽지 못하는 주소(노션·사내 위키 등)는 지금처럼 <b>분석 안 함</b> 으로 남습니다.
    오류가 아니라 근거 기록으로 그대로 두셔도 됩니다.
  </p>
</div>

<?php bs_layout_foot(); ?>

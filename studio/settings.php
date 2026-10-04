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

/**
 * 연동 하나의 '지금 상태' 판 — 켜고 끄기 · 쉬는 시각 · 사용량.
 *
 * ┌──────────────────────────────────────────────────────────────────┐
 * │ 왜 이 판이 필요한가                                               │
 * │                                                                  │
 * │ 2026-10-04 에 피그마 분석이 하룻밤 돌고도 3건밖에 못 읽었다.      │
 * │ 원인을 찾는 데 하루가 걸렸는데, 그동안 화면은 아무 말도 하지      │
 * │ 않았다. 피그마는 응답 헤더로 "2일 14시간 쉬어라" 고 또렷이        │
 * │ 말하고 있었는데 그 사실이 **어디에도 보이지 않았다.**             │
 * │                                                                  │
 * │ 그래서 세 가지를 한눈에 둔다.                                     │
 * │   · 지금 쓸 수 있는가, 못 쓴다면 왜                               │
 * │   · 얼마나 쓰고 있는가 (최근 14일)                                │
 * │   · 끌 수 있는가                                                  │
 * └──────────────────────────────────────────────────────────────────┘
 */
function ig_panel(Integration $store, array $st, string $who): void
{
    $p      = (string)$st['provider'];
    $series = $store->usageSeries($p, 14);
    $max    = 1;
    $sum    = 0;
    foreach ($series as $d) {
        $max = max($max, (int)$d['calls']);
        $sum += (int)$d['calls'];
    }
    ?>
  <div class="ba-ig__state">
    <div class="ba-ig__sw">
      <?php if ($st['connected']): ?>
        <button type="button"
                class="ba-btn ba-btn--sm<?= $st['enabled'] ? ' ba-btn--primary' : '' ?>"
                data-ig-toggle="<?= h($p) ?>" data-on="<?= $st['enabled'] ? '1' : '0' ?>"
                data-who="<?= h($who) ?>">
          <?= $st['enabled'] ? '연동 사용 중' : '연동 제외됨' ?>
        </button>
        <span class="ba-ig__hint">
          <?= $st['enabled']
              ? '끄면 토큰은 그대로 두고 호출만 하지 않습니다.'
              : '토큰은 남아 있습니다. 켜면 대기 중인 링크부터 이어서 읽습니다.' ?>
        </span>
      <?php endif; ?>
    </div>

    <?php if ($st['cooldown_left'] > 0): ?>
      <div class="ba-alert ba-alert--wait">
        <b>호출 제한으로 쉬는 중입니다.</b>
        <?= h(bs_date((string)$st['cooldown_until'], 'n월 j일 H:i')) ?> 까지 —
        남은 시간 <b><?= h(Integration::humanSpan((int)$st['cooldown_left'])) ?></b>
        <?php if ($st['cooldown_reason']): ?><br><?= h((string)$st['cooldown_reason']) ?><?php endif; ?>
        <br>
        <span class="ba-ig__hint">
          상대가 응답 헤더로 알려 준 시각입니다. 그때까지는 호출을 만들지 않습니다 —
          두드릴수록 제한이 길어지기 때문입니다.
        </span>
        <button type="button" class="ba-btn ba-btn--sm" data-ig-clear="<?= h($p) ?>">
          지금 다시 시도</button>
      </div>
    <?php elseif ($st['connected'] && !$st['usable'] && $st['blocked']): ?>
      <div class="ba-alert ba-alert--wait"><?= h((string)$st['blocked']) ?></div>
    <?php endif; ?>

    <?php if ($st['connected']): ?>
      <div class="ba-spark">
        <div class="ba-spark__num">
          오늘 <b><?= (int)$st['used_today'] ?></b>회
          <?php if ((int)$st['daily_cap'] > 0): ?>
            / 한도 <?= (int)$st['daily_cap'] ?>회
          <?php endif; ?>
          · 14일 합계 <b><?= $sum ?></b>회
        </div>
        <?php if ($sum === 0): ?>
          <p class="ba-ig__hint">아직 호출 기록이 없습니다.</p>
        <?php else: ?>
          <!-- 막대 하나가 하루. 붉은 부분이 실패다. 의존 라이브러리 없이
               인라인 SVG 로 그린다 — 역량 레이더와 같은 방식이다. -->
          <svg class="ba-spark__svg" viewBox="0 0 294 44" role="img"
               aria-label="최근 14일 호출 수">
            <?php foreach ($series as $i => $d):
              $c = (int)$d['calls']; $f = (int)$d['fail'];
              $hAll = $c > 0 ? max(2, (int)round($c / $max * 36)) : 0;
              $hBad = $f > 0 ? max(1, (int)round($f / $max * 36)) : 0;
              $x    = $i * 21 + 2;
              $ttl  = sprintf('%s · %d회 (실패 %d · 처리 %d건)',
                              (string)$d['d'], $c, $f, (int)$d['items']);
            ?>
              <rect x="<?= $x ?>" y="<?= 40 - $hAll ?>" width="16" height="<?= $hAll ?>"
                    rx="2" class="ba-spark__bar"><title><?= h($ttl) ?></title></rect>
              <?php if ($hBad > 0): ?>
                <rect x="<?= $x ?>" y="<?= 40 - $hBad ?>" width="16" height="<?= $hBad ?>"
                      rx="2" class="ba-spark__bad"><title><?= h($ttl) ?></title></rect>
              <?php endif; ?>
            <?php endforeach; ?>
            <line x1="0" y1="41" x2="294" y2="41" class="ba-spark__axis"/>
          </svg>
          <div class="ba-spark__ends">
            <span><?= h(bs_date((string)$series[0]['d'], 'n/j')) ?></span>
            <span>오늘</span>
          </div>
        <?php endif; ?>

        <label class="ba-spark__cap">
          <span>하루 호출 상한</span>
          <input type="number" min="0" step="10" value="<?= (int)$st['daily_cap'] ?>"
                 data-ig-cap="<?= h($p) ?>">
          <span class="ba-ig__hint">0 이면 제한 없음. 상대가 막기 전에 우리가 먼저 멈춥니다.</span>
        </label>
      </div>
    <?php endif; ?>
  </div>
    <?php
}
// localhost 는 구글이 http 를 받아 준다. 로컬 개발에서는 경고하지 않는다.
$__h    = (string)($_SERVER['HTTP_HOST'] ?? '');
$isLocal = str_starts_with($__h, 'localhost') || str_starts_with($__h, '127.0.0.1');

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
      <?php if ($google['connected'] && !$google['enabled']): ?>
        <span class="ba-badge ba-badge--off">연동 제외</span>
      <?php elseif ($google['cooldown_left'] > 0): ?>
        <span class="ba-badge ba-badge--off">쉬는 중</span>
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

    <?php ig_panel($store, $google, '구글 드라이브'); ?>

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
            한 글자라도 다르면 <code>redirect_uri_mismatch</code> 로 거부됩니다.
            <?php if (str_starts_with($redir, 'http://') && !$isLocal): ?>
              <div class="ba-alert" style="margin:8px 0">
                <b>이 주소가 <code>http://</code> 입니다. 이대로는 구글이 거부합니다.</b>
                구글은 리디렉션 주소에 <b>https 만</b> 받습니다
                (<code>localhost</code> 만 예외입니다). 둘 중 어느 쪽인지 보고 고르십시오.
                <br><br>
                <b>① 브라우저로는 https 로 들어오는데 이 주소만 http 로 보인다면</b><br>
                리버스 프록시가 TLS 를 끊어 주는데 서버가 그 사실을 모르는 것입니다.
                <code>studio/inc/env.config.php</code> 에 아래 한 줄을 넣으십시오.
                <br>
                <code>'base_url' =&gt; 'https://<?= h((string)($_SERVER['HTTP_HOST'] ?? '')) ?><?php
                  $p = parse_url($redir, PHP_URL_PATH);
                  echo h(substr((string)$p, 0, -strlen('/api/google_oauth.php')));
                ?>',</code>
                <br><br>
                <b>② 이 사이트가 정말 http 로만 열린다면</b><br>
                <b>구글 드라이브 연동은 쓸 수 없습니다.</b> 먼저 사이트에 HTTPS 를
                붙여야 합니다. 이 경우 <code>base_url</code> 에 https 를 적어도 소용없습니다 —
                동의 뒤 브라우저가 열리지 않는 주소로 되돌아올 뿐입니다.
                <br>
                그동안은 <b>피그마 연동</b>(아래)과 <b>파일 업로드 · 엑셀 붙여넣기</b>는
                그대로 쓰실 수 있습니다. 그쪽은 https 가 필요 없습니다.
              </div>
            <?php endif; ?></li>
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
      <?php if ($figma['connected'] && !$figma['enabled']): ?>
        <span class="ba-badge ba-badge--off">연동 제외</span>
      <?php elseif ($figma['cooldown_left'] > 0): ?>
        <span class="ba-badge ba-badge--off">쉬는 중</span>
      <?php endif; ?>
    </div>

    <?php if ($figma['last_error']): ?>
      <div class="ba-alert" style="margin:8px 0"><?= h((string)$figma['last_error']) ?></div>
    <?php endif; ?>
    <?php if ($figma['connected'] && $figma['last_ok_at']): ?>
      <p class="ba-head__sub">마지막으로 읽은 때 <?= h(bs_date((string)$figma['last_ok_at'])) ?></p>
    <?php endif; ?>

    <?php ig_panel($store, $figma, '피그마'); ?>

    <details class="ba-ig__how"<?= $figma['connected'] ? '' : ' open' ?>>
      <summary>피그마에서 먼저 할 일</summary>
      <ol>
        <li>피그마 <b>Settings → Security → Personal access tokens</b> 에서 토큰을 만듭니다.</li>
        <li>권한은 <b>File content: Read only</b> 면 충분합니다.</li>
        <li>만든 토큰을 아래에 넣습니다. <b>그 토큰을 만든 사람이 볼 수 있는 파일만</b> 읽힙니다.</li>
      </ol>
      <p class="ba-head__sub">
        토큰에는 <b>만료일이 있습니다(보통 3개월)</b>. 지나면 분석이 조용히 멈추므로
        달력에 미리 적어 두십시오. 만든 사람이 퇴사해도 끊기니
        <b>개인 계정보다 공용 계정에서 만드는 편이 낫습니다.</b>
      </p>
      <div class="ba-alert ba-alert--wait" style="margin:8px 0">
        <b>피그마의 호출 제한은 '분당 몇 번' 이 아니라 며칠 단위 예산입니다.</b>
        2026-10-04 에 링크를 하나씩 읽다가 <b>2일 14시간</b> 정지를 받았습니다.
        지금은 같은 파일의 화면들을 <b>한 번에 묶어</b> 묻고, 제한에 걸리면
        상대가 알려 준 시각까지 <b>호출을 아예 만들지 않습니다</b>.
        그래도 분석 전에 위 사용량을 한 번 보시는 편이 안전합니다.
      </div>
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

<?php
/**
 * 감시 만들기 / 고치기 / 복제.
 *   edit.php              새로
 *   edit.php?id=3         고치기(등록자·관리자)
 *   edit.php?clone=3      복제 — 설정만 가져와 새로 만든다(누구나)
 * 저장은 api/save.php, '다음 실행' 미리보기는 api/preview.php.
 */
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/templates.php';

$me = ow_require_login();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$id    = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$clone = isset($_GET['clone']) ? (int)$_GET['clone'] : 0;
$w     = null;
$v     = [
    'title' => '', 'prompt' => '', 'baseline' => '',
    'interval_min' => 1440, 'run_time' => '09:00', 'weekday' => (int)date('N'),
    'weekdays_only' => 0, 'hour_from' => 0, 'hour_to' => 24,
    // 슬랙은 고르는 사람만 — 채널이 감시마다 쌓이는 알림으로 시끄러워지지 않게 기본은 끈다
    'notify_mode' => 'change', 'min_importance' => 'low', 'notify_slack' => 0,
    'prefer_domains' => '', 'block_domains' => '',
    'max_searches' => (int)ow_config('default_searches'),
    'monthly_budget_usd' => (float)ow_config('default_budget_usd'),
    'starts_at' => date('Y-m-d\TH:i'),
    'ends_at' => date('Y-m-d', strtotime('+' . (int)ow_config('default_days') . ' days')),
    'has_webhook' => false,
];

if ($id) {
    $w = ow_watch_get($id);
    if (!$w) { http_response_code(404); exit('감시를 찾을 수 없습니다.'); }
    if (!ow_perms($w, $me)['can_edit']) { header('Location: view.php?id=' . $id); exit; }
    $v = array_merge($v, $w);
    $v['starts_at'] = date('Y-m-d\TH:i', strtotime((string)$w['starts_at']));
    $v['ends_at'] = date('Y-m-d', strtotime((string)$w['ends_at']));
    $v['has_webhook'] = !empty($w['slack_webhook_enc']);
} elseif ($clone) {
    $src = ow_watch_get($clone);
    if ($src) $v = array_merge($v, ow_clone_fields($src));
}
$isNew = !$w;
$pageTitle = $isNew ? ($clone ? '감시 복제' : '새 감시') : '감시 고치기';
$weekdays = [1 => '월', 2 => '화', 3 => '수', 4 => '목', 5 => '금', 6 => '토', 7 => '일'];

ow_page_head($me, $pageTitle . ' · OnWatch');
?>
<div class="wrap narrow">
  <div class="hero">
    <div>
      <h1><?= h($pageTitle) ?></h1>
      <p><?= $isNew ? '무엇을, 언제까지, 얼마나 자주 살필지 정합니다. 저장하면 누구나 이 감시를 보고 소식을 받을 수 있습니다.'
                    : '바꾼 설정은 다음 실행부터 적용됩니다.' ?></p>
    </div>
    <div class="hero-links">
      <a class="back" href="<?= $w ? 'view.php?id=' . (int)$w['id'] : 'index.php' ?>">← 돌아가기</a>
    </div>
  </div>

  <form id="owForm" class="form" novalidate>
    <?php if ($w): ?><input type="hidden" name="id" value="<?= (int)$w['id'] ?>"><?php endif; ?>

    <section class="card fsec">
      <div class="fsec-h">
        <h2><span class="num">1</span>무엇을 살필까요</h2>
        <?php if ($isNew): ?>
        <div class="tpl">
          <label for="tplSel" class="sr">예시 불러오기</label>
          <select id="tplSel">
            <option value="">예시 불러오기…</option>
            <?php foreach (ow_templates() as $k => $t): ?>
              <option value="<?= h($k) ?>"><?= h($t['label']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php endif; ?>
      </div>

      <div class="field">
        <label for="f_title">이름</label>
        <input id="f_title" name="title" maxlength="200" required value="<?= h($v['title']) ?>" placeholder="예: PHP2Shell 구버전 패치 추적">
        <div class="err" data-for="title"></div>
      </div>

      <div class="field">
        <label for="f_prompt">지시문 <span class="cnt" data-count="f_prompt" data-max="<?= (int)ow_config('prompt_max_chars') ?>"></span></label>
        <textarea id="f_prompt" name="prompt" rows="12" required maxlength="<?= (int)ow_config('prompt_max_chars') ?>"
                  placeholder="무엇을, 어디서 찾고, 어떤 경우에만 알리며, 알릴 때 무엇을 담을지 적어 주세요."><?= h($v['prompt']) ?></textarea>
        <details class="hint">
          <summary>좋은 지시문의 다섯 가지</summary>
          <ol>
            <li><b>대상</b> — 무엇의 변화인가(제품·버전·문서·번호를 구체적으로)</li>
            <li><b>출처</b> — 어디를 보라(공식 공지, 저장소, 배포판 트래커…)</li>
            <li><b>알릴 조건</b> — 무엇이 생기면 알리나. "변화가 없으면 알리지 마라" 는 따로 쓰지 않아도 됩니다</li>
            <li><b>제외</b> — 무엇은 소식으로 치지 말라(추정, 단순 언급, 광고…)</li>
            <li><b>담을 내용</b> — 알릴 때 꼭 넣을 것(버전, 링크, 발표일, 확인 수준…)</li>
          </ol>
          <p>이미 알린 소식은 OnWatch 가 따로 기억해 다시 알리지 않습니다.</p>
        </details>
        <div class="err" data-for="prompt"></div>
      </div>

      <div class="field">
        <label for="f_baseline">기준 상태 <span class="opt">선택</span></label>
        <textarea id="f_baseline" name="baseline" rows="4" maxlength="8000"
                  placeholder="지금 이미 알고 있는 것. 이것과 달라진 것만 새 소식으로 봅니다."><?= h($v['baseline']) ?></textarea>
        <p class="help">실행 결과가 기준 상태를 바꾸자고 제안하면 상세 화면에서 한 번에 반영할 수 있습니다.</p>
        <div class="err" data-for="baseline"></div>
      </div>
    </section>

    <section class="card fsec">
      <div class="fsec-h"><h2><span class="num">2</span>언제까지, 얼마나 자주</h2></div>

      <div class="row2">
        <div class="field">
          <label for="f_starts">시작</label>
          <input id="f_starts" type="datetime-local" name="starts_at" value="<?= h($v['starts_at']) ?>">
          <div class="err" data-for="starts_at"></div>
        </div>
        <div class="field">
          <label for="f_ends">종료일</label>
          <input id="f_ends" type="date" name="ends_at" required value="<?= h($v['ends_at']) ?>">
          <div class="quick" data-ends>
            <button type="button" data-days="7">1주</button><button type="button" data-days="14">2주</button>
            <button type="button" data-days="30">1달</button><button type="button" data-days="90">3달</button>
          </div>
          <div class="err" data-for="ends_at"></div>
        </div>
      </div>

      <div class="field">
        <span class="lbl">주기</span>
        <div class="seg" role="radiogroup" aria-label="주기">
          <?php foreach (OW_INTERVALS as $min => $label): ?>
            <label><input type="radio" name="interval_min" value="<?= $min ?>"<?= (int)$v['interval_min'] === $min ? ' checked' : '' ?>><span><?= h($label) ?></span></label>
          <?php endforeach; ?>
        </div>
        <div class="err" data-for="interval_min"></div>
      </div>

      <div class="row3 sched">
        <div class="field" data-show="daily weekly">
          <label for="f_runtime">실행 시각</label>
          <input id="f_runtime" type="time" name="run_time" value="<?= h($v['run_time'] ?: '09:00') ?>">
          <div class="err" data-for="run_time"></div>
        </div>
        <div class="field" data-show="weekly">
          <label for="f_weekday">요일</label>
          <select id="f_weekday" name="weekday">
            <?php foreach ($weekdays as $n => $label): ?>
              <option value="<?= $n ?>"<?= (int)$v['weekday'] === $n ? ' selected' : '' ?>><?= $label ?>요일</option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field" data-show="hourly">
          <span class="lbl">실행 시간대</span>
          <div class="hours">
            <select name="hour_from" aria-label="시작 시">
              <?php for ($i = 0; $i <= 23; $i++): ?><option value="<?= $i ?>"<?= (int)$v['hour_from'] === $i ? ' selected' : '' ?>><?= sprintf('%02d', $i) ?>시</option><?php endfor; ?>
            </select>
            <span>~</span>
            <select name="hour_to" aria-label="끝 시">
              <?php for ($i = 1; $i <= 24; $i++): ?><option value="<?= $i ?>"<?= (int)$v['hour_to'] === $i ? ' selected' : '' ?>><?= sprintf('%02d', $i) ?>시</option><?php endfor; ?>
            </select>
          </div>
          <div class="err" data-for="hour_from"></div>
        </div>
        <div class="field" data-show="hourly daily">
          <span class="lbl">&nbsp;</span>
          <label class="check"><input type="checkbox" name="weekdays_only" value="1"<?= $v['weekdays_only'] ? ' checked' : '' ?>> 평일만 (토·일 건너뜀)</label>
        </div>
      </div>

      <div class="preview" id="schedPreview" aria-live="polite"><span class="muted">일정을 계산하는 중…</span></div>
    </section>

    <section class="card fsec">
      <div class="fsec-h"><h2><span class="num">3</span>어떻게 알릴까요</h2></div>
      <div class="row2">
        <div class="field">
          <span class="lbl">알림</span>
          <?php foreach (OW_NOTIFY_MODES as $k => $label): ?>
            <label class="radio"><input type="radio" name="notify_mode" value="<?= h($k) ?>"<?= $v['notify_mode'] === $k ? ' checked' : '' ?>> <?= h($label) ?></label>
          <?php endforeach; ?>
          <p class="help">'매번' 을 고르면 변화가 없어도 "변화 없음" 을 알립니다. 처음 며칠 지시문을 다듬을 때 쓰기 좋습니다.</p>
        </div>
        <div class="field">
          <label for="f_minimp">알릴 최소 중요도</label>
          <select id="f_minimp" name="min_importance">
            <option value="low"<?= $v['min_importance'] === 'low' ? ' selected' : '' ?>>모두 알림</option>
            <option value="medium"<?= $v['min_importance'] === 'medium' ? ' selected' : '' ?>>보통 이상만</option>
            <option value="high"<?= $v['min_importance'] === 'high' ? ' selected' : '' ?>>높음만</option>
          </select>
          <p class="help">낮은 소식도 기록은 남아 상세 화면에서 볼 수 있습니다.</p>
        </div>
      </div>
      <div class="field">
        <span class="lbl">받는 곳</span>
        <p class="help strong">포털 알림함·종 숫자·OnWatch 카드 배지, 그리고 알림을 켜 둔 기기의 푸시로 받는 사람 모두에게 갑니다.</p>
        <?php if (!ow_config('slack_enabled')): ?>
        <p class="help">슬랙 알림은 관리자가 꺼 두었습니다.<?= $v['notify_slack'] ? ' 이 감시의 슬랙 설정은 그대로 남아 있다가 다시 켜지면 적용됩니다.' : '' ?></p>
        <?php else: ?>
        <label class="check"><input type="checkbox" name="notify_slack" value="1" id="f_slack"<?= $v['notify_slack'] ? ' checked' : '' ?>> 슬랙 채널에도 보내기 <span class="muted">— 선택</span></label>
        <div class="sub-field" data-slack>
          <input name="slack_webhook" id="f_webhook" type="url" autocomplete="off"
                 placeholder="<?= $v['has_webhook'] ? '이 감시 전용 웹훅이 있습니다. 바꾸려면 새 주소를 넣으세요' : '비우면 OnWatch 기본 채널. 다른 채널이면 https://hooks.slack.com/… 웹훅' ?>"
                 value="<?= $v['has_webhook'] ? '__keep__' : '' ?>">
          <?php if ($v['has_webhook']): ?>
            <button type="button" class="link" data-clear-webhook>기본 채널로 되돌리기</button>
          <?php endif; ?>
          <div class="err" data-for="slack_webhook"></div>
        </div>
        <?php endif; ?>
      </div>
    </section>

    <details class="card fsec adv"<?= ($v['prefer_domains'] || $v['block_domains']) ? ' open' : '' ?>>
      <summary><h2><span class="num">4</span>조사 범위와 비용 <span class="opt">고급</span></h2></summary>
      <div class="row2">
        <div class="field">
          <label for="f_prefer">먼저 볼 출처</label>
          <textarea id="f_prefer" name="prefer_domains" rows="4" placeholder="php.net&#10;github.com&#10;security-tracker.debian.org"><?= h($v['prefer_domains']) ?></textarea>
          <p class="help">한 줄에 하나. 먼저 살피라는 뜻이지 여기만 보라는 뜻은 아닙니다.</p>
        </div>
        <div class="field">
          <label for="f_block">보지 않을 도메인</label>
          <textarea id="f_block" name="block_domains" rows="4" placeholder="광고·요약 재게시 사이트 등"><?= h($v['block_domains']) ?></textarea>
          <p class="help">검색 결과에서 아예 뺍니다.</p>
        </div>
      </div>
      <div class="row2">
        <div class="field">
          <label for="f_searches">한 번 실행의 웹 검색 상한</label>
          <input id="f_searches" type="number" name="max_searches" min="1" max="<?= (int)ow_config('max_searches') ?>" value="<?= (int)$v['max_searches'] ?>">
          <p class="help">많을수록 꼼꼼하지만 비용이 늡니다. 출처가 많은 조사는 15~25회.</p>
          <div class="err" data-for="max_searches"></div>
        </div>
        <div class="field">
          <label for="f_budget">한 달 비용 상한 (USD)</label>
          <input id="f_budget" type="number" name="monthly_budget_usd" step="0.5" min="0.5" max="<?= h(ow_config('max_budget_usd')) ?>" value="<?= h($v['monthly_budget_usd']) ?>">
          <p class="help">넘으면 그달 남은 실행을 건너뛰고 등록한 사람에게 알립니다.</p>
          <div class="err" data-for="monthly_budget_usd"></div>
        </div>
      </div>
    </details>

    <div class="form-foot">
      <?php if ($isNew): ?>
        <label class="check"><input type="checkbox" name="run_now" value="1" checked> 저장하고 바로 한 번 실행 <span class="muted">— 지시문이 원하는 대로 동작하는지 먼저 봅니다</span></label>
      <?php endif; ?>
      <div class="btns">
        <a class="btn ghost" href="<?= $w ? 'view.php?id=' . (int)$w['id'] : 'index.php' ?>">취소</a>
        <button class="btn primary" type="submit"><?= $isNew ? '감시 시작' : '저장' ?></button>
      </div>
    </div>
  </form>
</div>
<?php ow_page_foot(['page' => 'edit', 'templates' => $isNew ? ow_templates() : new stdClass()]); ?>

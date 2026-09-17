<?php
/** 모달 3종: 요청 작성/수정, 상세, 처리 확인. */
declare(strict_types=1);
?>

<!-- ===== 요청 작성 / 수정 ===== -->
<div class="bc-modal" id="bc-m-form" hidden>
  <div class="bc-modal__box" role="dialog" aria-modal="true" aria-labelledby="bc-m-form-title">
    <div class="bc-modal__head">
      <h2 id="bc-m-form-title">새 구매 요청</h2>
      <button type="button" class="bc-close" data-close aria-label="닫기">&times;</button>
    </div>
    <div class="bc-modal__body">
      <div class="bc-alert" id="bc-form-error" hidden></div>
      <div class="bc-form" style="margin-top:12px">
        <input type="hidden" id="bc-in-id">

        <div class="bc-form__row">
          <label>
            <span class="bc-form__req">사용처</span>
            <select id="bc-in-category">
              <?php foreach ($categories as $c): ?>
                <option value="<?= (int)$c['id'] ?>"><?= h($c['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>
            <span>희망 수령일</span>
            <input type="date" id="bc-in-needby">
          </label>
        </div>

        <label>
          <span class="bc-form__req">필요 물품</span>
          <input type="text" id="bc-in-item" maxlength="200" placeholder="예) 16OZ 아이스 컵">
        </label>

        <div class="bc-form__row">
          <label>
            <span class="bc-form__req">필요 갯수</span>
            <input type="number" id="bc-in-qty" min="1" max="100000" value="1">
          </label>
          <label>
            <span>단위</span>
            <input type="text" id="bc-in-unit" maxlength="20" value="개" placeholder="개 / 박스 / 세트">
          </label>
        </div>

        <div class="bc-form__row">
          <label>
            <span>예상 금액 <em class="bc-form__help">모르면 비워 두세요</em></span>
            <input type="number" id="bc-in-amount" min="0" step="100" placeholder="원">
          </label>
          <label>
            <span>수령 장소</span>
            <select id="bc-in-deliver">
              <option value="">선택 안 함</option>
              <?php foreach (BC_DELIVER_PLACES as $place): ?>
                <option value="<?= h($place) ?>"><?= h($place) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
        </div>

        <label>
          <span>참고 링크 <em class="bc-form__help">구매할 상품 페이지가 있으면 붙여 주세요</em></span>
          <input type="text" id="bc-in-url" placeholder="https://">
        </label>

        <label>
          <span>비고</span>
          <textarea id="bc-in-note" maxlength="2000" placeholder="색상, 규격, 배송 관련 요청 등"></textarea>
        </label>

        <label>
          <span>첨부파일 <em class="bc-form__help">견적서, 제품 사진 등. 최대 10개</em></span>
          <input type="file" id="bc-in-files" multiple>
          <div class="bc-filelist" id="bc-form-files"></div>
        </label>
      </div>
    </div>
    <div class="bc-modal__foot">
      <button type="button" class="bc-btn" data-close>취소</button>
      <!-- 검토승인 권한자에게만 보입니다. app.js 가 표시 여부를 정합니다. -->
      <button type="button" class="bc-btn" id="bc-form-submit-skip" hidden>승인없이 구매진행</button>
      <button type="button" class="bc-btn bc-btn--primary" id="bc-form-submit">요청 올리기</button>
    </div>
  </div>
</div>

<!-- ===== 상세 ===== -->
<div class="bc-modal" id="bc-m-detail" hidden>
  <div class="bc-modal__box" role="dialog" aria-modal="true" aria-labelledby="bc-m-detail-title">
    <div class="bc-modal__head">
      <h2 id="bc-m-detail-title">요청 상세</h2>
      <button type="button" class="bc-close" data-close aria-label="닫기">&times;</button>
    </div>
    <div class="bc-modal__body" id="bc-detail-body">
      <p class="bc-loading">불러오는 중…</p>
    </div>
    <div class="bc-modal__foot" id="bc-detail-actions"></div>
  </div>
</div>

<!-- ===== 처리 확인 ===== -->
<div class="bc-modal" id="bc-m-action" hidden>
  <div class="bc-modal__box bc-modal__box--narrow" role="dialog" aria-modal="true" aria-labelledby="bc-m-action-title">
    <div class="bc-modal__head">
      <h2 id="bc-m-action-title">처리</h2>
      <button type="button" class="bc-close" data-close aria-label="닫기">&times;</button>
    </div>
    <div class="bc-modal__body">
      <div class="bc-alert" id="bc-action-error" hidden></div>
      <p id="bc-action-desc" style="margin:8px 0 14px"></p>
      <div class="bc-form">
        <label id="bc-action-comment-wrap">
          <span id="bc-action-comment-label">의견</span>
          <textarea id="bc-action-comment" maxlength="1000"></textarea>
        </label>
        <label id="bc-action-assignee-wrap" hidden>
          <span id="bc-action-assignee-label">구매 담당</span>
          <select id="bc-action-assignee">
            <option value="">지정하지 않음 — 구매담당자 누구나 처리</option>
          </select>
        </label>
        <div class="bc-form__row" id="bc-action-purchase-wrap" hidden>
          <label>
            <span>실제 구매 금액</span>
            <input type="number" id="bc-action-amount" min="0" step="100" placeholder="원">
          </label>
          <label>
            <span>구매 메모</span>
            <input type="text" id="bc-action-pnote" maxlength="500" placeholder="구매처, 결제 수단 등">
          </label>
        </div>
      </div>
    </div>
    <div class="bc-modal__foot">
      <button type="button" class="bc-btn" data-close>취소</button>
      <button type="button" class="bc-btn bc-btn--primary" id="bc-action-submit">처리</button>
    </div>
  </div>
</div>

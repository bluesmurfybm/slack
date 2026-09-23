<?php
/**
 * 🤖 AI 패널 템플릿 — lists.php 가 require_login() 뒤에 include 한다(단독 페이지 아님). 마크업만 있고 동작은 ai/panel.js.
 *  - #aiConfirm       : 2단계 확인 모달(AI.confirm). lists.css 의 .sm-overlay/.sm-box/.sm-head/.sm-x 를 그대로 쓴다.
 *  - #aiTagPickerTpl  : 태그 멀티선택 편집기 셸({{list}} 자리에 panel.js 가 체크박스 칩을 채운다)
 *  - #aiToast         : 액션 결과 토스트
 *  - window.AI_CFG    : 현재 사용자(이메일/승인자/관리자) — 상태 응답이 오기 전 버튼 표시용
 * 이미 HTML 출력이 시작된 뒤라 current_portal_user()(쿠키 갱신) 대신 세션 캐시 current_user() 만 쓴다.
 */
require_once __DIR__ . '/ai_lib.php';
$__aiU     = function_exists('current_user') ? current_user() : null;
$__aiEmail = (string)($__aiU['portal_email'] ?? '');
$__aiAdmin = $__aiEmail !== '' && ai_is_admin($__aiEmail);
$__aiCfg   = ['me' => $__aiEmail, 'is_admin' => $__aiAdmin, 'can_approve' => $__aiAdmin || ($__aiEmail !== '' && ai_is_approver($__aiEmail))];
?>
<!-- 🤖 AI 확인 모달 (AI.confirm) -->
<div id="aiConfirm" class="sm-overlay ai-cf" hidden role="dialog" aria-modal="true" aria-labelledby="aiCfTitle">
  <div class="sm-box ai-cf-box">
    <div class="sm-head"><span class="ai-cf-title" id="aiCfTitle">확인</span><button type="button" class="sm-x ai-cf-x" aria-label="닫기 (Esc)">✕</button></div>
    <div class="ai-cf-body"></div>
    <div class="ai-cf-foot">
      <span class="ai-cf-me" title="승인·커밋 컬럼과 감사 로그에 기록되는 계정">👤 <?= htmlspecialchars($__aiEmail, ENT_QUOTES, 'UTF-8') ?><?= $__aiCfg['can_approve'] ? ' · 승인자' : ' · 조회 전용' ?></span>
      <span class="ai-cf-btns"><button type="button" class="ai-cf-cancel">취소</button><button type="button" class="ai-cf-ok primary" disabled>확인</button></span>
    </div>
  </div>
</div>
<!-- 태그 편집기 셸 -->
<template id="aiTagPickerTpl">
  <div class="ai-tagpick">
    <div class="ai-tagpick-list">{{list}}</div>
    <div class="ai-tagpick-foot">
      <span class="ai-muted">체크한 태그로 전체 교체됩니다 (source=user · AI 재분석 시에도 유지)</span>
      <span><button type="button" class="ai-btn" data-act="tags_cancel">취소</button><button type="button" class="ai-btn primary" data-act="save_tags">💾 태그 저장</button></span>
    </div>
  </div>
</template>
<div id="aiToast" hidden></div>
<script>window.AI_CFG = <?= json_encode($__aiCfg, JSON_UNESCAPED_UNICODE) ?>;</script>

-- 감시마다 조사·판정에 쓸 모델을 고른다. API 비용을 사내 다른 프로그램과 나눠 쓰므로
-- 모든 감시가 가장 비싼 모델로 돌지 않게 한다. 기존 감시는 NULL 로 두어 지금처럼
-- 배치 기본값(ONWATCH_MODEL)으로 돈다 — 고르는 순간부터 그 모델을 쓴다.

ALTER TABLE `ow_watch` ADD COLUMN `model` VARCHAR(80) NULL COMMENT '조사·판정에 쓸 모델. NULL 이면 배치 기본값(ONWATCH_MODEL)' AFTER `monthly_budget_usd`;

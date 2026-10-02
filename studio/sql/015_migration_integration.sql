-- =====================================================================
-- 015. 외부 연동 자격 정보 (구글 드라이브 · 피그마)
--
--   mysql -u root --default-character-set=utf8mb4 iworks_local     < studio/sql/015_migration_integration.sql
--   mysql -u root --default-character-set=utf8mb4 blueassign_test  < studio/sql/015_migration_integration.sql
--
-- 출처 문서로 구글 드라이브·피그마 **링크**를 올리는 일이 많은데, 지금까지는
-- 내려받을 길이 없어 parse_status='skip' 으로 두고 사람이 따로 파일을 올려야
-- 했다. 자격 정보를 받아 서버가 직접 읽게 한다.
--
-- ┌──────────────────────────────────────────────────────────────────┐
-- │ 왜 bs_setting 에 넣지 않는가                                      │
-- │                                                                  │
-- │ bs_setting 은 사람이 보고 고치는 **값**(점유 상한 등)을 담는 표다. │
-- │ label·note 가 있고 관리 화면이 그대로 늘어놓는다. 거기에 비밀을   │
-- │ 섞으면 언젠가 화면에 찍힌다.                                      │
-- │                                                                  │
-- │ 비밀은 **암호화해서** 담는다. 포털의 enc_token()/dec_token()      │
-- │ (AES-256-GCM, 열쇠는 config.php 의 key)을 그대로 쓴다 —           │
-- │ portal_users.slack_token_enc 와 같은 방식이다.                    │
-- │                                                                  │
-- │ **DB 를 통째로 떠 가도 config.php 의 열쇠가 없으면 못 푼다.**      │
-- │ 바꿔 말하면 열쇠를 잃으면 토큰도 잃는다 — 다시 연결하면 된다.      │
-- └──────────────────────────────────────────────────────────────────┘
--
-- 행을 미리 만들어 두지 않는다. 연결한 적이 있는 연동만 줄이 생긴다.
-- =====================================================================

CREATE TABLE IF NOT EXISTS `bs_integration` (
  `provider`   VARCHAR(20)  NOT NULL
               COMMENT '연동 대상. google=구글 드라이브 | figma=피그마',

  -- 구글: OAuth 클라이언트 ID. 비밀이 아니라 그대로 둔다.
  -- 피그마: 쓰지 않는다.
  `client_id`  VARCHAR(255) NULL
               COMMENT '구글 OAuth 클라이언트 ID. 피그마는 쓰지 않는다',

  -- 구글: client_secret / 피그마: 개인 접근 토큰(PAT)
  `secret_enc` TEXT         NULL
               COMMENT '암호화된 비밀. 구글은 client_secret, 피그마는 개인 접근 토큰',

  -- 구글: refresh token. 피그마는 쓰지 않는다(PAT 하나로 끝).
  `token_enc`  TEXT         NULL
               COMMENT '암호화된 갱신 토큰. 구글 OAuth 동의로 받는다',

  `account`    VARCHAR(190) NULL
               COMMENT '연결된 계정. 누구 권한으로 읽는지 화면에 보여 주려고 둔다',

  -- 실패를 조용히 삼키지 않는다. 링크가 안 읽힐 때 사람이 볼 자리가 있어야 한다.
  `last_error` VARCHAR(300) NULL COMMENT '마지막 실패 사유. 성공하면 비운다',
  `last_ok_at` DATETIME     NULL COMMENT '마지막으로 실제 읽기에 성공한 시각',

  `updated_by`      VARCHAR(64) NULL COMMENT '마지막으로 바꾼 사람(이메일)',
  `updated_by_name` VARCHAR(80) NULL COMMENT '바꾼 시점 성명 스냅샷',
  `updated_at`      DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP
                                ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`provider`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='외부 연동 자격 정보. 비밀은 암호화해 담는다';


-- ---------------------------------------------------------------------
-- 확인
--
--   SELECT provider, client_id, account, last_ok_at, last_error FROM bs_integration;
--     secret_enc · token_enc 는 일부러 빼고 보라. 눈으로 볼 값이 아니다.
-- ---------------------------------------------------------------------

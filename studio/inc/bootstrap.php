<?php
/** BlueStudio 모든 진입점(화면·API·배치)이 최초로 include 하는 파일. 포털 연동과 권한 상수를 정의한다. */

declare(strict_types=1);

define('BS_ROOT', dirname(__DIR__));

/**
 * iworks 포털 루트.
 *
 * 이 모듈은 포털 저장소 안의 한 폴더(<포털>/studio)로 들어간다.
 * BlueCart 와 달리 단독 실행은 지원하지 않는다 — 포털이 없으면 그대로 멈춘다.
 * (단독 실행 지원 여부는 docs/conventions.md §9-6 에 미결로 남아 있었고,
 *  스캐폴딩 단계에서는 분기를 늘리지 않는 쪽을 택했다. 필요해지면 BlueCart 의
 *  bluecart/includes/bootstrap.php 가 BC_PORTAL_ROOT 를 비워 두는 방식을 그대로 옮기면 된다.)
 */
define('BS_PORTAL_ROOT', dirname(BS_ROOT));

if (!is_file(BS_PORTAL_ROOT . '/core/auth.php')) {
    http_response_code(500);
    exit('BlueStudio 는 iworks 포털 안(<포털>/studio)에서만 동작합니다.');
}

// ---------------------------------------------------------------------
// 포털 연동
//
// 순서가 중요하다. 포털 core/auth.php 가 세션 이름(BLUEIWORK_SESSID)과 저장
// 경로를 직접 정한 뒤 session_start() 를 부른다. 우리가 먼저 세션을 열면
// 기본 이름으로 열려 포털 세션을 보지 못한다.
// (docs/conventions.md §1.4)
//
// core/auth.php 가 date_default_timezone_set('Asia/Seoul') 과 core/db.php 를
// 함께 끌고 온다. 여기서 시간대를 다시 정하지 않는다.
// ---------------------------------------------------------------------
require_once BS_PORTAL_ROOT . '/core/auth.php';        // 세션 + current_portal_user() + portal_db()
require_once BS_PORTAL_ROOT . '/core/worksystems.php'; // 상단바 업무 시스템 메뉴
require_once BS_PORTAL_ROOT . '/core/board.php';       // board_is_admin() — 포털 관리자 판정

require_once BS_ROOT . '/inc/helpers.php';

/**
 * 환경별 설정값 — 이 컴퓨터/이 서버에만 해당하는 것.
 *
 * `inc/env.config.php` 가 있으면 그것이 돌려준 배열에서 꺼내고, 없으면
 * 아래 각 상수에 적힌 기본값을 쓴다. 그 파일은 .gitignore 로 막혀 있다
 * (inc/llm.config.php 와 같은 방식). 표본은 inc/env.config.sample.php.
 *
 * 로컬에서는 파일을 만들지 않아도 그대로 돌아간다. 기본값이 로컬 값이다.
 * **운영에서는 반드시 만들어야 한다** — 적어도 upload_dir 은 웹 루트
 * 바깥을 가리켜야 한다. docs/conventions.md §3.2, README.md '환경별 설정'.
 */
function bs_env(string $key, $default = null)
{
    static $cfg = null;
    if ($cfg === null) {
        $f   = BS_ROOT . '/inc/env.config.php';
        $cfg = is_file($f) ? require $f : [];
        if (!is_array($cfg)) {
            error_log('[BlueStudio] inc/env.config.php 가 배열을 돌려주지 않았습니다. 무시합니다.');
            $cfg = [];
        }
    }
    return array_key_exists($key, $cfg) && $cfg[$key] !== null ? $cfg[$key] : $default;
}

/**
 * worksystems.json 에 등록한 이 모듈의 key.
 * 상단바 현재 위치 표시와 미로그인 안내(?need_login=)에 쓰인다.
 */
const BS_MODULE_KEY = 'studio';

// =====================================================================
// 권한
// =====================================================================
//
// BlueCart 는 역할 배정 표(bc_role_assign)를 따로 두지만, BlueStudio 는
// 001_schema.sql 에 그런 표가 없다. 역할을 아래 세 곳에서 끌어낸다.
//
//   ADMIN  : 포털 관리자 명단(portal_admin + core/board.php 의 OWNER_ADMINS)
//            → board_is_admin($email) 로 판정. core 에 실제로 있는 함수다.
//   PM     : 그 프로젝트의 bs_project.owner_id 인 사람 (프로젝트별로 달라진다)
//            → ProjectRepo 조회가 필요해 지금은 TODO
//   MEMBER : 로그인한 나머지 전원
//
// 역할 표를 새로 만들지 말지는 P1 에서 정한다. 프로젝트마다 PM 이 다르므로
// 전역 역할 표보다 bs_project.owner_id 쪽이 맞아 보인다.
// =====================================================================

/** 역할 코드 */
const BS_ROLE_ADMIN  = 'ADMIN';   // 모듈 전체 관리자 = 포털 관리자
const BS_ROLE_PM     = 'PM';      // 특정 프로젝트의 담당 PM
const BS_ROLE_MEMBER = 'MEMBER';  // 일반 구성원

const BS_ROLES = [
    BS_ROLE_ADMIN  => '관리자',
    BS_ROLE_PM     => 'PM',
    BS_ROLE_MEMBER => '구성원',
];

/**
 * 기능 권한(capability) 코드.
 *
 * 화면·API 가 역할 이름을 직접 비교하지 않고 이 코드로 묻게 한다.
 * 나중에 역할 체계가 바뀌어도 bs_can() 한 곳만 고치면 된다.
 */
const BS_CAP_PROJECT_MANAGE     = 'project.manage';      // 프로젝트 등록·수정·삭제
const BS_CAP_WBS_CONFIRM        = 'wbs.confirm';         // WBS 초안 확정(confirmed=1)
const BS_CAP_ALLOCATION_PROPOSE = 'allocation.propose';  // 배정안 산출
const BS_CAP_ALLOCATION_CONFIRM = 'allocation.confirm';  // 배정안 확정
const BS_CAP_PROFILE_VIEW_ANY   = 'profile.view_any';    // 남의 프로파일 열람
const BS_CAP_EVAL_RUN           = 'eval.run';            // 역량 재판정 실행
const BS_CAP_OBJECTION_REVIEW   = 'objection.review';    // 이의 제기 처리
const BS_CAP_RND_APPROVE        = 'rnd.approve';         // R&D 과제 승인·반려 (P9)

/**
 * CLAUDE.md 가 강제하는 규칙을 코드에서 지키기 위한 상수.
 * 값을 바꾸기 전에 docs/bluestudio-spec.md 와 CLAUDE.md 를 먼저 보라.
 */
// 표본이 이보다 적은 구성원은 낮은 점수 대신 insufficient_data 플래그를 세운다.
const BS_MIN_SAMPLE = 20;
// 관리자 보정치 허용 범위.
const BS_ADJUST_MIN = -20.0;
const BS_ADJUST_MAX = 20.0;

// =====================================================================
// 도메인 상수
//
// 태스크·배정 상태까지 늘어나면 inc/workflow.php 로 옮긴다
// (BlueCart 가 includes/workflow.php 에 BC_STATUS 계열을 모아 둔 방식).
// P1 에서는 프로젝트 것만 있어 여기 둔다.
// =====================================================================

/** bs_project.status — 001_schema.sql 의 COMMENT 와 같은 목록이어야 한다. */
const BS_PROJECT_STATUS = [
    'draft'      => '작성 중',
    'scoping'    => '범위 정리',
    'allocating' => '배정 중',
    'confirmed'  => '배정 확정',
    'running'    => '진행 중',
    'done'       => '완료',
    'hold'       => '보류',
];

/**
 * R&D 과제의 상태 (bs_project.status, project_type='rnd').
 *
 * 프로젝트와 **다른 목록**이다. 같은 칸을 쓰지만 생명주기가 다르다.
 * 010_migration_rnd.sql 의 COMMENT 와 같아야 한다.
 */
const BS_RND_STATUS = [
    'draft'    => '작성 중',
    'proposed' => '발의됨',
    'approved' => '승인됨',
    'running'  => '진행 중',
    'done'     => '종료',
    'dropped'  => '중단',
];

/** bs_project.rnd_category — R&D 과제의 갈래 */
const BS_RND_CATEGORY = [
    'poc'        => 'PoC',
    'enhance'    => '고도화',
    'new_module' => '신규 모듈',
    'research'   => '리서치',
];

/**
 * bs_project.visibility — 공개 범위 (명세서 §8.6).
 *
 * **Repo 레벨에서 강제한다.** 화면에서만 숨기면 API 를 직접 불러 뚫린다.
 * 판정은 inc/presenter.php 의 bs_rnd_visible_sql() 한 곳에 모아 둔다.
 */
const BS_RND_VISIBILITY = [
    'private' => '비공개 — 발의자와 관리자만',
    'open'    => '공개 — 열람과 합류 가능',
    'public'  => '공개 — 열람만',
];

/** 합류 신청을 받는 공개 범위. private/public 과제에는 신청할 수 없다. */
const BS_RND_JOINABLE = ['open'];

/** bs_rnd_member.status — 합류 상태 */
const BS_RND_MEMBER_STATUS = [
    'requested' => '신청',
    'approved'  => '참여 중',
    'rejected'  => '반려',
    'left'      => '이탈',
    'done'      => '종료',
];

/** bs_rnd_member.role */
const BS_RND_MEMBER_ROLE = [
    'lead'   => '주도',
    'member' => '참여',
];

/** bs_rnd_output.kind — 산출물 갈래 */
const BS_RND_OUTPUT_KIND = [
    'doc'    => '문서',
    'repo'   => '저장소',
    'demo'   => '시연',
    'report' => '보고서',
    'module' => '모듈',
    'slide'  => '발표자료',
];

/** bs_project.track */
const BS_PROJECT_TRACK = [
    'lms_b2b'    => 'LMS (B2B)',
    'lxp'        => 'LXP',
    'lxp_hybrid' => 'LXP 하이브리드',
    '용역'        => '용역',
    '사내'        => '사내',
];

/**
 * bs_domain.domain_group — 분야 묶음.
 *
 * 분야 22개는 전부 코스모스(무들) 기능 기준이라, 사내 제품이나 AI 실험 같은
 * R&D 과제를 발의할 때 붙일 태그가 없었습니다. 묶음을 둘로 갈라 발의 화면이
 * 둘 다 보여 주게 합니다.
 *
 * 세부 분야는 코스모스 쪽이 맞고, 일반은 큰 분류만 있으면 된다는 판단입니다.
 * 그래서 기존 22개는 전부 cosmos 이고 general 은 6개뿐입니다.
 */
const BS_DOMAIN_GROUP = [
    'cosmos'  => ['label' => '코스모스 LXP', 'hint' => '코스모스(무들) 기능 영역'],
    'general' => ['label' => '일반',         'hint' => '사내 제품·도구·연구. 코스모스 밖의 과제'],
];

/**
 * bs_domain.category — 코스모스(무들) 컴포넌트 계열.
 *
 * **점수는 분야가 아니라 이 단위로 냅니다.** 분야 21개로는 (사람×분야) 칸의
 * 81%가 표본 부족으로 버려집니다(docs/scoring-design.md §1.3).
 *
 * 일반 묶음(BS_DOMAIN_GROUP) 의 분야는 계열이 비어 있을 수 있습니다.
 * 비면 점수 계산에서 빠집니다 — 태그로만 남고 남의 점수를 흔들지 않습니다.
 *
 * 코스모스는 UBION 무들 배포판 계열이라, 무들 컴포넌트 체계를 그대로 쓰면
 * "어느 플러그인 계열을 다뤘는가" 가 배정 근거가 됩니다.
 */
const BS_DOMAIN_CATEGORY = [
    'activity'       => ['label' => '학습활동',      'moodle' => 'mod_*'],
    'grading'        => ['label' => '평가·이수',     'moodle' => 'grade*, completion'],
    'enrolment'      => ['label' => '사용자·수강',   'moodle' => 'auth_*, enrol_*, user'],
    'integration'    => ['label' => '연동·알림',     'moodle' => 'webservice, message_*'],
    'presentation'   => ['label' => '화면·테마',     'moodle' => 'theme_*, block_*'],
    'administration' => ['label' => '운영·관리',     'moodle' => 'admin, tool_*, report_*'],
    'platform'       => ['label' => '플랫폼·인프라', 'moodle' => '서버·DB·배포'],
    'planning'       => ['label' => '기획·QA',       'moodle' => '—'],
];

/**
 * 점수를 내지 않는 계열.
 *
 * 기획·QA 는 6개월 47건뿐이고 20건을 넘긴 사람이 0명이었습니다. 기획 업무가
 * 슬랙 취합 시스템에 남지 않기 때문이며, 기획 담당자가 평가 제외
 * (is_evaluable=0)인 것과 같은 원인입니다.
 */
const BS_CATEGORY_NOT_SCORED = ['planning'];

// =====================================================================
// 가용도 산출 (명세서 §5)
// =====================================================================

/**
 * '아직 진행 중' 으로 볼 status_raw.
 *
 * 명세서 §11-6 에 "상태값 중 어디까지를 진행 중으로 볼지" 가 미결로 남아
 * 있었습니다. 슬랙 취합 시스템 자신의 판정을 따릅니다 —
 * `slack/lists.php` 의 overdueDays() 가 이렇게 적고 있습니다.
 *
 *   if (status.includes("확인요청")) return 0;
 *   // 확인요청 = 처리 끝나고 회신 대기 → 지연 아님
 *
 * 즉 `확인요청(...)` 은 개발자 손을 떠나 고객 회신을 기다리는 상태입니다.
 * `보류` 도 진행 중이 아닙니다.
 *
 * 실측(6개월, 자사 구성원): 미종료 252건 중
 *   진행 중 49건 / 회신 대기·보류 203건
 * 203건까지 점유로 세면 전원이 포화로 보입니다.
 */
const BS_WORKLOAD_ACTIVE_STATUS = ['진행중', '등록', '공수산정요청', '운영배포요청'];

/** 진행 중이 아닌 것으로 보는 접두/정확 일치 값. 위 목록의 보완재. */
const BS_WORKLOAD_IDLE_PREFIX = ['확인요청'];
const BS_WORKLOAD_IDLE_STATUS = ['보류', '완료', '처리불가'];

/**
 * 추정 점유 — 진행 중 건 하나가 먹는 가용량.
 *
 * 0.05 = 한 건이 그 사람 가용량의 5% 를 쓴다고 본다.
 * 난이도로 보정한다(난이도 3 이 기준) — 어려운 건이 더 많이 먹는다.
 *
 * **근거가 약한 값입니다.** 실제 소요를 잰 것이 아니라 어림입니다.
 * 그래서 화면에서 확정 점유와 **절대 합쳐 보여주지 않습니다**.
 * 실측이 쌓이면 여기만 고치면 됩니다.
 */
const BS_INFERRED_LOAD_PER_ITEM = 0.05;

/** 추정만으로 100% 를 채우지 못하게 하는 상한. 추정으로 사람을 배제하면 안 된다. */
const BS_INFERRED_LOAD_MAX = 0.60;

/**
 * 직접 등록한 점유의 출처 (bs_workload.source).
 *
 * 슬랙 취합 시스템에 안 잡히는 업무를 사람이 넣을 때, **어디서 알게 된
 * 일인지**를 같이 받는다. 이 한 줄이 그 사람의 가용도를 깎기 때문에
 * 근거가 남아야 한다.
 */
const BS_WORKLOAD_SOURCE = [
    'slack'   => '슬랙',
    'email'   => '메일',
    'meeting' => '회의·구두',
    'doc'     => '문서',
    'etc'     => '기타',
];

/** 추정 점유의 신뢰도. bs_workload.confidence 와 화면 표시에 쓴다. */
const BS_INFERRED_CONFIDENCE = 0.50;

// =====================================================================
// WBS 태스크 (명세서 §4)
// =====================================================================

/** bs_task.status. 001_schema.sql 의 컬럼 주석과 같은 목록이어야 한다. */
const BS_TASK_STATUS = [
    'todo'          => '대기',
    'doing'         => '진행 중',
    'review'        => '검토',
    'dev_deployed'  => '개발 배포',
    'prod_deployed' => '운영 배포',
    'done'          => '완료',
    'hold'          => '보류',
];

/**
 * 배정 대상에서 빠지는 상태.
 *
 * `done` 은 끝났고, `hold` 는 지금 붙일 일이 아니다. 나머지는 아직 손이
 * 가야 하는 일이라 배정 대상이다. `prod_deployed` 도 포함한다 — 운영에
 * 올린 뒤 확인·보정이 남는 경우가 흔하다.
 *
 * 이 목록은 TaskRepo::confirmedForAllocation() 만 사용한다.
 */
const BS_TASK_NOT_ASSIGNABLE_STATUS = ['done', 'hold'];

/**
 * 지연으로 보지 않을 상태 (명세서 §7.1 Step4).
 *
 * 명세서는 `plan_end < today AND status != done` 이라고 적었지만
 * `prod_deployed` 도 뺀다. 운영에 올라간 일을 계속 지연이라 부르면
 * 화면이 늘 빨갛고, 그러면 아무도 빨간색을 보지 않게 된다.
 * `hold` 도 뺀다 — 세워 둔 일은 늦은 것이 아니라 멈춘 것이다.
 */
const BS_TASK_OVERDUE_EXEMPT = ['done', 'prod_deployed', 'hold'];

/** 칸반에 세울 열. 명세서 §7.1 Step4 의 6개. `hold` 는 따로 모은다. */
const BS_KANBAN_COLUMNS = ['todo', 'doing', 'review', 'dev_deployed', 'prod_deployed', 'done'];

/**
 * 대시보드 카드에 올릴 프로젝트 상태.
 *
 * '진행 중' 은 배정이 끝난 것만이 아니다. 범위를 정리하고 있거나 배정을
 * 짜고 있는 것도 지금 굴러가는 일이다. 빼면 PM 이 자기 프로젝트를
 * 대시보드에서 못 찾는다.
 *
 * 빼는 것은 둘뿐 — `draft`(아직 만드는 중)와 `done`(끝남).
 */
const BS_DASH_PROJECT_STATUS = ['scoping', 'allocating', 'confirmed', 'running', 'hold'];

/** 진행상황 알림을 보낼 슬랙 채널 (명세서 §7.2). */
define('BS_PROGRESS_CHANNEL', bs_env('progress_channel', '#bluestudio-알림'));

/** 대/중/소 3계층. 스키마의 depth TINYINT 과 짝이다. */
const BS_TASK_MAX_DEPTH = 3;

const BS_TASK_DEPTH_LABEL = [1 => '대분류', 2 => '중분류', 3 => '소분류'];

/** 난이도 범위. 스키마에 CHECK 가 없으므로(MySQL 5.7 호환) 여기서 지킨다. */
const BS_TASK_DIFFICULTY_MIN = 1;
const BS_TASK_DIFFICULTY_MAX = 5;

/**
 * 한 프로젝트의 태스크 수 상한.
 *
 * 엑셀 붙여넣기 때문에 둡니다. 사람이 시트를 통째로 긁어 붙이면 수천 행이
 * 한 번에 들어오는데, 전체 트리 저장은 한 트랜잭션이라 그대로 받으면
 * 잠금이 오래 걸립니다. 실수인지 의도인지 물어보는 편이 낫습니다.
 */
const BS_TASK_MAX_PER_PROJECT = 1000;

/**
 * PDF 에서 글자를 뽑을 외부 도구(poppler 의 pdftotext) 경로.
 *
 * PDF 는 글자가 글꼴 안에 좌표로 흩어져 있어서 직접 읽으면 줄 순서와
 * 띄어쓰기가 원문과 달라지기 쉽습니다. 흐트러진 글로 WBS 를 뽑으면
 * 엉뚱한 태스크가 나오므로, 제대로 된 도구가 있을 때만 읽습니다.
 *
 * 비워 두면 PDF 는 parse_status='fail' 로 남고 그 이유를 화면에 적습니다.
 * xlsx·pptx·docx 는 이 설정과 무관하게 언제나 읽습니다.
 *
 * 설치 예 (윈도우): poppler 를 받아 풀고 bin/pdftotext.exe 경로를 적습니다.
 *   const BS_PDFTOTEXT = 'C:/tools/poppler/bin/pdftotext.exe';
 */
define('BS_PDFTOTEXT', bs_env('pdftotext', null));

/** 태스크 하나에 붙일 수 있는 분야 수. 다 고르면 분야 조건이 무의미해진다. */
const BS_TASK_MAX_DOMAINS = 5;

/** 추정 공수(M/D) 상한. 한 태스크가 이보다 크면 쪼개야 한다. */
const BS_TASK_MAX_EST_MD = 999.99;
// =====================================================================
// 배정 엔진 (명세서 §6)
// =====================================================================

/** 엔진 버전. 배정안마다 기록해 둔다 — 식이 바뀌면 옛 결과를 설명할 수 없다. */
const BS_ENGINE_VER = 'v1';

/**
 * 적합도 가중치 기본값 (명세서 §6.1 표).
 *
 * ┌──────────────────────────────────────────────────────────────────┐
 * │ 명세서와 다른 점: w_comm 이 없다                                  │
 * │                                                                  │
 * │ 명세서 식에는 `w_comm * comm_score` 가 있지만 comm_score 는       │
 * │ 산출하지 않기로 이미 결정했다(첫 응답 시간을 못 재고, 남은        │
 * │ cmt_count 는 왕복이 많을수록 어려운 건이라 해석이 안 된다 —       │
 * │ docs/scoring-design.md).                                          │
 * │                                                                  │
 * │ 없는 점수에 0 을 넣으면 **모두가 그만큼씩 깎인다.** 그래서        │
 * │ 합을 1 로 맞추지 않고, fit() 이 실제로 쓴 가중치의 합으로         │
 * │ 나눈다. comm_score 가 생기면 여기 한 줄만 넣으면 된다.            │
 * └──────────────────────────────────────────────────────────────────┘
 */
const BS_ALLOC_WEIGHTS = [
    'domain' => 0.35,   // 태스크 분야 ∩ 구성원 계열 역량
    'cap'    => 0.20,   // 종합 역량
    'avail'  => 0.25,   // 참여 가능도
    'career' => 0.10,   // 경력
    // 명세서에서 (옵션) 으로 표시된 항목. 기본은 0 — 켜면 결과가 달라지므로
    // 사람이 의도적으로 올려야 한다.
    'growth' => 0.00,   // 인접 분야 성장 기회
];

/** 가중치로 받을 수 있는 값의 범위. 화면 슬라이더도 이 범위를 쓴다. */
const BS_ALLOC_WEIGHT_MAX = 1.0;

/**
 * 제약 조건 기본값 (명세서 §6.2).
 *
 * capacity_ratio : 총 배정 공수 ≤ 가용 공수 × 이 값. 명세서는 1.0 이다.
 * max_support    : 태스크당 지원 인원 상한. owner 는 언제나 정확히 1명.
 * group_bonus    : 같은 대분류를 이미 맡은 사람에게 주는 가산점(0~100 척도).
 *                  컨텍스트 스위칭 비용을 줄이려는 것이지 강제가 아니다.
 * hard_difficulty: 이 난이도 이상은 해당 분야 상위자를 먼저 본다.
 * top_ratio      : '상위자' 를 어디까지로 볼지. 0.5 면 중앙값 이상.
 * concentration  : 한 사람이 전체 공수의 이 비율을 넘게 맡으면 감점이 붙는다.
 */
const BS_ALLOC_CONSTRAINTS = [
    'capacity_ratio'  => 1.0,
    'max_support'     => 2,
    'group_bonus'     => 8.0,
    'hard_difficulty' => 4,
    'top_ratio'       => 0.5,
    'concentration'   => 0.40,
];

/** 지역 탐색(swap) 최대 왕복 횟수. 결정론을 지키려면 상한이 있어야 한다. */
const BS_ALLOC_MAX_PASSES = 20;

/** bs_allocation.status */
const BS_ALLOC_STATUS = [
    'proposed'  => '산출됨',
    'adjusted'  => '조정됨',
    'confirmed' => '확정',
    'archived'  => '지난 안',
];

/** bs_allocation_item.role */
const BS_ALLOC_ROLE = [
    'owner'    => '담당',
    'support'  => '지원',
    'reviewer' => '검토',
];

/** 알림 종류/상태 (bs_notification) */
const BS_NOTIFY_CHANNEL = ['slack' => '슬랙 DM', 'email' => '메일'];
const BS_NOTIFY_STATUS  = [
    'queued' => '보낼 예정', 'sent' => '보냄', 'failed' => '실패', 'skipped' => '건너뜀',
];
/** bs_project_source.kind */
const BS_SOURCE_KIND = [
    'xlsx'  => '엑셀',
    'pptx'  => 'PPT',
    'docx'  => '워드',
    'pdf'   => 'PDF',
    'image' => '이미지',
    'figma' => '피그마',
    'url'   => '링크',
    'text'  => '직접 입력',
];

// =====================================================================
// 업로드 설정
//
// BlueCart 는 이 값들을 자체 config/config.php 에 두지만 이 모듈은 별도
// config 가 없다(포털 config 를 그대로 쓴다). 상수로 한곳에 모아 둔다.
// 값을 바꿀 일이 잦아지면 그때 config 파일로 뺀다.
// =====================================================================

/**
 * 업로드 파일이 실제로 저장되는 곳.
 *
 * studio/var/ 는 모듈 .htaccess 와 var/.htaccess 로 웹 직접 접근이 막혀 있다
 * (learn/var 와 같은 방식). BlueCart 는 웹 루트 바깥(/var/www/iworks-data)을
 * 쓰는데, 그쪽이 더 안전하므로 운영에서는 이 상수만 바꿔 옮기면 된다.
 *
 * docs/conventions.md §9-10 에 "운영 저장 경로 미확인" 으로 남아 있던 항목이며
 * 여기가 그 결정을 내린 곳이다.
 */
define('BS_UPLOAD_DIR', rtrim(bs_env('upload_dir', BS_ROOT . '/var/source'), '/\\'));

/** 한 파일 최대 크기. php.ini 의 upload_max_filesize 보다 작아야 의미가 있다. */
const BS_UPLOAD_MAX_BYTES = 20 * 1024 * 1024;   // 20MB

/** 한 번에 올릴 수 있는 파일 수. */
const BS_UPLOAD_MAX_FILES = 10;

/** 한 프로젝트가 가질 수 있는 출처 문서 수(파일·링크·텍스트 합계). */
const BS_SOURCE_MAX_PER_PROJECT = 50;

/**
 * 허용 확장자 → 그 확장자에서 나올 수 있는 MIME.
 *
 * 확장자만 믿지 않고 finfo 로 실제 내용을 확인한 뒤 이 표와 대조한다
 * (BlueCart Attachment::MIME_BY_EXT 와 같은 방식).
 *
 * xlsx/pptx/docx 는 전부 zip 컨테이너라 finfo 가 application/zip 으로 볼 때가
 * 많다. 그래서 zip 을 함께 허용한다 — 확장자 위장을 완전히 막지는 못하지만,
 * 저장 경로가 웹에서 실행되지 않으므로 실행 위험은 없다.
 */
const BS_UPLOAD_MIME = [
    'xlsx' => ['application/zip', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
    'pptx' => ['application/zip', 'application/vnd.openxmlformats-officedocument.presentationml.presentation'],
    'docx' => ['application/zip', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
    'pdf'  => ['application/pdf'],
    'jpg'  => ['image/jpeg'],
    'jpeg' => ['image/jpeg'],
    'png'  => ['image/png'],
    'gif'  => ['image/gif'],
    'webp' => ['image/webp'],
];

/** 확장자 → bs_project_source.kind */
const BS_EXT_KIND = [
    'xlsx' => 'xlsx',
    'pptx' => 'pptx',
    'docx' => 'docx',
    'pdf'  => 'pdf',
    'jpg'  => 'image',
    'jpeg' => 'image',
    'png'  => 'image',
    'gif'  => 'image',
    'webp' => 'image',
];

// ---------------------------------------------------------------------
// DB
// ---------------------------------------------------------------------

/**
 * 이 모듈이 쓰는 PDO.
 *
 * 포털과 같은 DB 를 쓰고 bs_ 접두사 표만 건드린다. 접속 정보를 두 군데 적지
 * 않으려고 포털 core/db.php 의 portal_db() 를 그대로 재사용한다.
 * (BlueCart 는 자체 config 에 DB 정보를 복사해 두지만, 그 파일 주석도
 *  "접속 정보가 두 군데 적혀 있으면 한쪽만 바뀌었을 때 원인을 찾기 어렵습니다"
 *  라고 적고 있다. 여기서는 아예 한 곳만 본다.)
 *
 * 주의: portal_db() 는 단순 커넥션이 아니라 portal_* 표를 만들고 구성원을
 * 시드하는 부수효과가 있다. 포털이 어차피 매 요청 부르는 함수라 문제는 없다.
 */
function bs_db(): PDO
{
    return portal_db();
}

// ---------------------------------------------------------------------
// 로그인 / 권한 판정
// ---------------------------------------------------------------------

/**
 * 현재 로그인 사용자. 비로그인이면 null.
 *
 * 식별자는 **이메일**이다. 001_schema.sql 의 owner_id/user_id 등이 모두
 * VARCHAR(64) 이메일이고, BlueCart 도 같은 방식이다.
 *
 * @return array{id:string,name:string,email:string,color:?string}|null
 */
function bs_current_user(): ?array
{
    static $cached = false;
    static $user = null;

    if ($cached) {
        return $user;
    }
    $cached = true;

    $row = current_portal_user();   // core/auth.php
    if ($row) {
        $user = [
            'id'    => (string)$row['email'],   // 식별자는 이메일
            'name'  => (string)$row['name'],
            'email' => (string)$row['email'],
            'color' => user_color($row),        // core/auth.php — 상단바 아바타 색
        ];
    }
    return $user;
}

/**
 * 화면 진입점용. 비로그인이면 포털로 돌려보낸다.
 * 포털이 ?need_login=<key> 를 받아 왜 튕겼는지 이름까지 알려 준다.
 */
function bs_require_login(): array
{
    $user = bs_current_user();
    if ($user === null) {
        header('Location: ../index.php?need_login=' . rawurlencode(BS_MODULE_KEY));
        exit;
    }
    return $user;
}

/**
 * API 진입점용. 비로그인이면 401 JSON.
 *
 * core/auth.php 의 require_portal_login() 도 401 JSON 을 내지만 응답 모양이
 * {"error":"..."} 라 이 모듈의 {ok,data,error} 형식과 다르다. 형식을 맞추려고
 * 여기서 따로 받는다.
 */
function bs_require_login_api(): array
{
    $user = bs_current_user();
    if ($user === null) {
        bs_json_error('LOGIN_REQUIRED', '로그인이 필요합니다.', 401);
    }
    return $user;
}

/** 포털 관리자인가. core/board.php 의 board_is_admin() 을 그대로 쓴다. */
function bs_is_admin(?string $email = null): bool
{
    $email ??= bs_current_user()['id'] ?? '';
    return $email !== '' && board_is_admin($email);
}

/**
 * 이 사람이 해당 프로젝트의 PM(bs_project.owner_id) 인가.
 *
 * 한 요청에서 같은 프로젝트를 여러 번 묻는다(화면 그리면서 버튼마다).
 * 매번 조회하지 않도록 프로젝트별로 owner_id 를 캐시한다.
 */
function bs_is_pm(int $projectId, ?string $email = null): bool
{
    static $ownerCache = [];

    $email ??= bs_current_user()['id'] ?? '';
    if ($email === '' || $projectId <= 0) {
        return false;
    }

    if (!array_key_exists($projectId, $ownerCache)) {
        require_once BS_ROOT . '/inc/repo/ProjectRepo.php';
        // 지워진 프로젝트도 본다 — 복구 권한을 PM 에게 주려면 여기서 걸러지면 안 된다.
        $row = (new ProjectRepo(bs_db()))->find($projectId, true);
        $ownerCache[$projectId] = $row['owner_id'] ?? null;
    }

    return $ownerCache[$projectId] !== null && $ownerCache[$projectId] === $email;
}

/**
 * 기능 권한 판정.
 *
 * $projectId 를 주면 그 프로젝트의 PM 인지까지 본다. 안 주면 관리자만 통과한다.
 *
 * TODO(P1): bs_is_pm() 이 채워지면 PM 경로가 실제로 열린다.
 */
function bs_can(string $capability, ?int $projectId = null): bool
{
    if (bs_current_user() === null) {
        return false;
    }
    if (bs_is_admin()) {
        return true;    // 관리자는 전부 통과
    }

    $isPm = $projectId !== null && bs_is_pm($projectId);

    return match ($capability) {
        BS_CAP_PROJECT_MANAGE,
        BS_CAP_WBS_CONFIRM,
        BS_CAP_ALLOCATION_PROPOSE,
        BS_CAP_ALLOCATION_CONFIRM => $isPm,

        // 남의 프로파일 열람은 PM/관리자만. 본인 것은 bs_can_view_profile() 로 따로 본다.
        BS_CAP_PROFILE_VIEW_ANY   => $isPm,

        // 재판정과 이의 처리는 관리자 전용 — 위에서 이미 걸러졌다.
        BS_CAP_EVAL_RUN,
        BS_CAP_OBJECTION_REVIEW   => false,

        // R&D 과제 승인도 지금은 관리자 전용 — 위에서 이미 걸러졌다.
        //
        // **승인 주체는 아직 미결이다** (명세서 11.3). 팀장이 자기 팀 과제를
        // 승인하게 할지, 관리자만 할지 정해지지 않았다. 승인은 가용도를
        // 깎는 행위라(§9.1) 느슨하게 열어 두면 배정 회피 통로가 된다.
        // 정해지기 전까지는 **좁은 쪽**으로 둔다.
        BS_CAP_RND_APPROVE        => false,

        default => false,
    };
}

/**
 * 직접 등록한 점유(bs_workload.kind='manual')를 넣고 고칠 수 있는가.
 *
 * 본인이거나, 어느 프로젝트든 PM 이거나, 관리자.
 *
 * 점유는 프로젝트에 매이지 않는다 — 그 사람의 달력이다. 그래서 특정
 * 프로젝트 권한으로 가를 수 없고 "배정을 짜는 사람인가" 로만 본다.
 * PM 에게 여는 이유는 "저 사람 다음 달 상주 나간다" 를 배정 짜는 사람이
 * 가장 먼저 알기 때문이고, **누가 넣었는지 반드시 남는 것**이 그 조건이다.
 *
 * api/candidate.php(단추를 그릴지)와 api/workload.php(실제 허용)가
 * 같이 쓴다. 두 벌로 두면 단추는 보이는데 누르면 403 이 나는 일이 생긴다.
 */
function bs_can_edit_workload(string $memberUserId): bool
{
    $me = bs_current_user();
    if ($me === null) {
        return false;
    }
    if ((string)$me['id'] === $memberUserId) {
        return true;    // 본인
    }
    if (bs_is_admin()) {
        return true;
    }
    // 어느 프로젝트든 담당 PM 이면 된다.
    static $isPm = null;
    if ($isPm === null) {
        // R&D 과제(project_type='rnd')의 owner 는 여기 들지 않는다.
        //
        // 이 판정은 BS_CAP_PROJECT_MANAGE · BS_CAP_ALLOCATION_PROPOSE 를
        // 열어 준다. 과제를 하나 발의했다는 이유로 **남의 프로젝트 배정안을
        // 만들 권한**까지 생기면 안 된다. R&D 과제 안에서의 권한은
        // bs_rnd_member.role='lead' 로 따로 본다 (P9).
        $st = bs_db()->prepare(
            "SELECT 1 FROM bs_project
              WHERE owner_id = ? AND deleted_at IS NULL
                AND project_type = 'project'
              LIMIT 1"
        );
        $st->execute([(string)$me['id']]);
        $isPm = (bool)$st->fetchColumn();
    }
    return $isPm;
}

/**
 * 프로파일을 볼 수 있는가.
 *
 * CLAUDE.md: "본인은 자기 프로파일을 항상 열람할 수 있다" + "프로파일 조회
 * 권한: 본인 또는 PM/관리자". 본인 여부가 먼저다.
 *
 * TODO(P3): $memberUserId 는 bs_member.user_id(이메일)다. 화면이 bs_member.id
 *           (정수)만 들고 있으면 MemberRepo 로 이메일을 먼저 가져와야 한다.
 */
function bs_can_view_profile(string $memberUserId, ?int $projectId = null): bool
{
    $me = bs_current_user();
    if ($me === null) {
        return false;
    }
    if ($me['id'] === $memberUserId) {
        return true;        // 본인은 항상
    }
    return bs_can(BS_CAP_PROFILE_VIEW_ANY, $projectId);
}

/** 권한이 없으면 403 JSON 으로 끊는다. API 전용. */
function bs_require_cap_api(string $capability, ?int $projectId = null): void
{
    if (!bs_can($capability, $projectId)) {
        bs_json_error('FORBIDDEN', '권한이 없습니다.', 403);
    }
}

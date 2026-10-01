<?php
/** 구성원 목록 — 배정 후보가 되는 구성원과 역할·가용도를 훑는 화면. */

declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/layout.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$user = bs_require_login();

// ┌──────────────────────────────────────────────────────────────────┐
// │ CLAUDE.md 가 금지한 것 — 이 화면을 만들 때 반드시 지킬 것           │
// │                                                                  │
// │ "구성원 간 종합점수 전체 랭킹 화면을 만들지 않는다."                │
// │                                                                  │
// │ 즉 이 목록에 cap_score 같은 종합점수를 열로 넣고 정렬시키면 안 된다.│
// │ 보여줄 수 있는 것은 역할·팀·가용도·주요 분야까지다. 점수는 특정     │
// │ 과업을 기준으로 한 적합도(api/candidate.php) 에서만 나온다.        │
// └──────────────────────────────────────────────────────────────────┘

// TODO(P4): MemberRepo::search() — is_assignable, role_label, team 으로 거른다.
$filter = [
    'role_label'    => bs_param_str('role'),
    'team'          => bs_param_str('team'),
    'keyword'       => bs_param_str('keyword'),
    'is_assignable' => bs_param_int('assignable', 1),
];
$rows = [];

bs_layout_head(
    $user,
    '구성원',
    '업무 배정',
    '배정 후보 구성원과 역할·가용도를 확인합니다.',
    'member'
);

bs_placeholder('구성원 목록', 'P3~P4', [
    '열: 이름 · 역할(role_label) · 팀 · 기본 가용 M/M · 주요 분야 · 배정 가능 여부',
    '종합점수 열과 전체 정렬은 넣지 않는다 (CLAUDE.md)',
    '표본 부족(insufficient_data=1)인 사람은 점수 자리에 "표본 부족" 으로 표시',
    '이름 클릭 → member_profile.php?member_id= (본인 또는 PM/관리자만 열람)',
]);

bs_layout_foot();

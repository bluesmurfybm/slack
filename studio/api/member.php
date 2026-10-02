<?php
/**
 * 구성원 표 관리 API.
 *
 * POST api/member.php?act=sync     포털 사용자 → 구성원 가져오기 (관리자)
 *
 * ┌──────────────────────────────────────────────────────────────────┐
 * │ 왜 따로 있나                                                      │
 * │                                                                  │
 * │ 구성원 표가 비어 있으면 **이 모듈이 통째로 멈춘다.** 후보도 배정도│
 * │ 역량도 전부 bs_member 를 기준으로 돈다. 그런데 그 표를 채우는     │
 * │ 길이 코드 안에만 있고 화면에는 없었다 — 화면은 "돌려야 합니다" 라 │
 * │ 적어 두고 돌릴 방법을 주지 않았다. 그 구멍을 메운다.              │
 * │                                                                  │
 * │ 조회는 member_list.php 가 서버에서 직접 그린다. 여기에는 쓰기만   │
 * │ 둔다 — 읽기 전용 act 를 더해 구성원 목록 API 를 만들면 점수를     │
 * │ 얹고 정렬하고 싶어진다(CLAUDE.md 가 금지한 전사 랭킹).            │
 * └──────────────────────────────────────────────────────────────────┘
 */

declare(strict_types=1);
require_once __DIR__ . '/_init.php';
require_once BS_ROOT . '/inc/repo/MemberRepo.php';

$members = new MemberRepo(bs_db());

bs_route(bs_param_str('act', ''), [

    /**
     * 포털 사용자(portal_users)를 구성원 표로 가져온다.
     *
     * **관리자만.** 구성원 표는 배정·역량의 기준이라, 아무나 돌리면
     * 배정 후보 전체가 바뀐다.
     *
     * 행을 지우지 않고 is_evaluable 도 건드리지 않는다. 자세한 것은
     * MemberRepo::syncFromPortalUsers() 주석에 있다.
     */
    'sync' => function () use ($members): void {
        bs_begin_write();
        if (!bs_is_admin()) {
            bs_json_error('FORBIDDEN', '관리자만 구성원을 가져올 수 있습니다.', 403);
        }

        $r = $members->syncFromPortalUsers();

        // 화면이 그대로 띄울 문장. 숫자만 주면 "그래서 뭘 하라는 건가" 가
        // 된다. 특히 '포털에 있는데 배정 제외' 는 **자동으로 되돌리지
        // 않는 값**이라 사람이 봐야 한다.
        $lines = [];
        $lines[] = $r['added'] > 0
            ? sprintf('%d명을 새로 가져왔습니다.', $r['added'])
            : '새로 들어온 사람은 없습니다.';
        if ($r['renamed'] > 0) {
            $lines[] = sprintf('%d명의 이름을 포털 값으로 맞췄습니다.', $r['renamed']);
        }
        if ($r['deactivated'] > 0) {
            $lines[] = sprintf(
                '%d명이 포털에서 사라져 배정 후보에서 내렸습니다. 행은 지우지 않았습니다.',
                $r['deactivated']
            );
        }
        if ($r['excluded_but_active'] > 0) {
            $lines[] = sprintf(
                '포털에 있는데 배정 제외인 사람이 %d명 있습니다. 휴직 처리일 수도 있어 '
                . '자동으로 되돌리지 않습니다.',
                $r['excluded_but_active']
            );
        }
        $lines[] = '역할·팀·경력·기본 가용은 포털에 없는 값이라 비어 있습니다. '
                 . '점수에는 영향이 없습니다(절대 기준).';

        bs_json_ok($r + ['message' => implode(' ', $lines)]);
    },
]);

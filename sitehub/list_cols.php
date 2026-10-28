<?php
/**
 * Slack 리스트 'BlueLab-유지보수처리목록' (thebluesoft.slack.com, F088VTXBD0R) 의 컬럼 매핑 — slack/boards.php 의 col 과 같은 역할.
 *
 *  키는 sync.php 가 쓰는 역할 이름, 값은 그 리스트의 Slack 컬럼 ID(Col…) 또는 컬럼 key(기본 열은 'name'·'priority' 같은 key 를 가진다 —
 *  sync.php 가 리스트 스키마를 읽어 key 를 ID 로 바꿔 쓴다). 없는 컬럼은 '' 로 두면 그 칸만 비운 채 동기화된다. title 하나는 꼭 있어야 한다.
 *  리스트 컬럼이 바뀌면 `php sitehub/sync.php schema` 로 스키마를 찍어 보고 여기만 고친다.
 *
 *  이 리스트는 제목 열이 곧 업체명(사이트)이라 title 과 site 가 같은 열이고, 요청자·담당자(user) 열과 완료일(date) 열은 없다.
 */
return [
    'title'    => 'name',            // 업체명 (text, 기본 열) — 이 리스트는 제목이 곧 사이트명
    'site'     => 'name',            // 사이트 매칭도 같은 열 (lab_sites.name · slack_names 와 대조)
    'priority' => 'priority',        // 우선순위 (select: 높음 / 보통 / 낮음)
    'date'     => 'Col0C7S1CSRC4',   // 요청일시 (text, 'YYYY-MM-DD HH:MM') — 2026-10-08 날짜 열에서 글자 열로 바꿈(tools/move_request_time.php)
    'date_old' => 'Col088H7J663C',   // 요청일(날짜) (date) — 옛 요청일시 열. 새 요청일시 칸이 비어 있으면(예: 메일 자동 등록) 여기서 날짜를 받는다
    'channel'  => 'Col088KNXB6RJ',   // 요청구분 (select: 이메일 / 전화 / 카카오톡 / 내부점검 / 문자 / 방문)
    'body'     => 'Col088VU8D4FK',   // 작업내용 (text) — 2026-10-08 '시간 - 작업내용'에서 이름 변경, 앞머리 시간은 요청일시로 옮겼다
    'status'   => 'Col088AK187FY',   // 처리상태 (select: 1_시작전 / 2_작업중 / 4_검토중 / 처리완료 / 보류중 / 취소)
    'paid'     => 'Col08F81H1KN2',   // 무/유상 (select: 무상 / 유상)
    'remark'   => 'Col08MDK4A60G',   // 완료시간- 비고 (text) — 앞머리가 날짜(YYYY-MM-DD)면 done 에도 넣는다
    'attach'   => 'Col0AK7Q0NYFJ',   // 참고 (attachment)
    'req'      => '',                // 요청자 (user) — 이 리스트에는 없다
    'asg'      => '',                // 담당자 (user, 여러 명) — 이 리스트에는 없다
    'done'     => '',                // 완료일 (date) — 이 리스트에는 없다 (remark 에서 파생)
];

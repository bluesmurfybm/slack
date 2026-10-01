<?php
/**
 * 환경별 설정 표본 — 이 컴퓨터/이 서버에만 해당하는 값.
 *
 *   cp studio/inc/env.config.sample.php studio/inc/env.config.php
 *
 * env.config.php 는 .gitignore 로 막혀 있습니다(서버마다 값이 다릅니다).
 * 파일이 아예 없어도 모듈은 돌아갑니다 — 아래 '기본값' 이 그대로 쓰입니다.
 * 로컬 개발에서는 만들 필요가 없습니다.
 *
 * ┌──────────────────────────────────────────────────────────────────┐
 * │ 운영 서버에서는 반드시 만드십시오.                                 │
 * │ 적어도 upload_dir 은 웹 루트 바깥을 가리켜야 합니다.               │
 * └──────────────────────────────────────────────────────────────────┘
 *
 * 값을 빼거나 null 로 두면 기본값이 쓰입니다.
 */

declare(strict_types=1);

return [

    /**
     * 업로드한 출처 문서가 실제로 저장되는 곳.
     *
     * 기본값: <studio>/var/source  — 웹 루트 **안**입니다.
     *
     * 기본값이 뚫리지 않는 것은 studio/.htaccess 와 var/.htaccess 가 막기
     * 때문입니다. Apache 의 AllowOverride 가 꺼져 있거나 nginx 로 받으면
     * .htaccess 를 아예 읽지 않으므로, 올려 둔 사업 문서가 그대로 열립니다.
     * 그래서 운영에서는 웹 루트 바깥으로 옮깁니다.
     *
     * BlueCart 가 쓰는 자리와 나란히 두는 것을 권합니다:
     *   /var/www/iworks-data/studio/source
     *
     * 웹 서버 실행 계정이 읽고 쓸 수 있어야 합니다.
     *   mkdir -p /var/www/iworks-data/studio/source
     *   chown -R www-data:www-data /var/www/iworks-data/studio
     *   chmod 750 /var/www/iworks-data/studio/source
     *
     * 옮긴 뒤 이미 올라와 있던 파일이 있으면 같이 옮겨야 합니다.
     * bs_project_source.file_path 에 절대경로가 들어 있으므로, 옮겼다면
     * 그 칸도 함께 고쳐야 합니다. 오픈 전이라면 올라온 파일이 없습니다.
     */
    'upload_dir' => null,

    /**
     * pdftotext(poppler) 실행 파일 경로.
     *
     * 기본값: null — PDF 는 parse_status='fail' 로 남고 이유를 화면에 적습니다.
     * xlsx·pptx·docx 는 이 값과 무관하게 언제나 읽습니다.
     *
     * 리눅스:  apt install poppler-utils   →  '/usr/bin/pdftotext'
     * 윈도우:  poppler 를 풀고             →  'C:/tools/poppler/bin/pdftotext.exe'
     */
    'pdftotext' => null,

    /**
     * 진행상황 알림을 적재할 슬랙 채널 (명세서 §7.2).
     *
     * 기본값: '#bluestudio-알림'
     *
     * 이 이름의 채널이 슬랙에 실제로 있어야 합니다. 없으면 지금은 티가 나지
     * 않습니다 — 적재만 하고 보내지 않기 때문입니다(명세서 11.2-B). 발송
     * 경로가 생기는 순간 전부 실패합니다. 오픈 전에 채널을 만드십시오.
     *
     * 시험용 서버라면 '#bluestudio-알림-test' 처럼 따로 두십시오.
     */
    'progress_channel' => null,

];

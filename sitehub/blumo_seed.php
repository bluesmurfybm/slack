<?php
/**
 * BLUMO 에 SiteHub 연결 전부터 있던 감시 대상 31곳 — 2026-10-08 의 blumo.sqlite sites 를 옮겨 적은 것(에이전트 키는 그때 비어 있었다).
 * 감시 설정 탭의 'BLUMO 기존 대상 연결'(pink 계정)이 이 목록을 사이트에 붙여 감시 주소로 만든다. blumo_id 가 BLUMO 쪽 id 라서
 * BLUMO 가 SiteHub 목록을 받을 때 같은 행(점검 기록 그대로)으로 이어진다. 다 연결하고 나면 지워도 된다.
 *  [BLUMO id, 이름, 주소, 정상 판정 문구, 메모]
 */
return [
    [ 1, '청주시 지속가능발전위원회', 'http://greencj21.org/', '청주시지속가능발전협의회', ''],
    [ 2, '청주복지재단 365두드림', 'https://365dodream.com/', '두드림', ''],
    [ 3, '청주국제에코콤플렉스', 'https://cj-eco.or.kr/', '청주국제에코콤플렉스', ''],
    [ 4, '청주직지문화의집', 'https://jikjimunhwa.org/', '직지문화', '카페24 / 도메인 만료 6.4'],
    [ 5, '충북장애인부모연대', 'http://cbbm.or.kr/', '충북장애인부모연대', '카페24 cbbumo / 만료 10.13 / PHP7.0'],
    [ 6, '충북피플퍼스트센터', 'http://cbpfc.or.kr/', '충북피플퍼스트센터', ''],
    [ 7, '청주시장애인가족지원센터', 'http://cjfamily.or.kr/', '청주시장애인가족지원센터', ''],
    [ 8, '옥천군장애인가족지원센터', 'http://okfamily.or.kr/', '옥천군장애인가족지원센터', ''],
    [ 9, '보은군장애인가족지원센터', 'http://boeunfamily.or.kr/', '보은군장애인가족지원센터', '카페24 bojanga / 만료 12.17 / PHP7.3'],
    [10, '세종시장애인가족지원센터', 'https://sjfamily.or.kr/', '세종특별자치시장애인가족지원센터', ''],
    [11, '세종시장애인부모회', 'http://sejangbumo.com/', '세종시장애인부모회', ''],
    [12, '충북대 산림치유학과', 'https://healing.cbnu.ac.kr/', '산림치유학협동과정', '카페24 / 만료 7.25 / PHP5.5'],
    [13, '충북대 교양교육본부', 'https://fole.chungbuk.ac.kr/', '교양교육본부', '학내 통합 SSL'],
    [14, '충북대 교육혁신본부', 'https://oira.chungbuk.ac.kr/', '교육혁신본부', ''],
    [15, '충북대 박물관', 'https://museum.chungbuk.ac.kr/', '박물관', ''],
    [16, '충북대 이차전지사업단', 'https://coss-battery.cbnu.ac.kr/', '이차전지혁신융합대학사업단', ''],
    [17, '한밭대 리터러시진단평가', 'https://www.hbliteracy.kr/', '한밭대학교', '네이버클라우드'],
    [18, '유네스코 GCED 온라인캠퍼스', 'https://gcedonlinecampus.org/', '', '첫 화면이 531바이트짜리 리다이렉트 안내 페이지 — 실제 캠퍼스 주소로 바꿔야 정확함'],
    [19, '유네스코 아태교육원', 'https://unescoapceiu.org/', 'APCEIU', '카페24'],
    [20, '유네스코 국제회의', 'https://icongced.unescoapceiu.org/', 'Global Citizenship Education', '카페24'],
    [21, '독도의용수비대기념관(국문)', 'https://dokdomemorial.or.kr/', '독도의용수비대기념관', '카페24'],
    [22, '독도의용수비대기념관(영문)', 'http://dokdomemorial.kr/', 'Dokdo Volunteer Forces', '카페24'],
    [23, '증평군 34플러스센터', 'https://34plus.kr/', '34플러스센터', '카페24'],
    [24, '증평군 지역활성화센터', 'https://jprvsc.kr/', '지역활성화지원센터', '카페24'],
    [25, '패스랩(목과슴의원)', 'https://www.pathlab.co.kr/', '목과슴사랑의원', ''],
    [26, '에이치에스엠', 'https://hsmfarm.co.kr/', '에이치에스엠', '카페24(타계정) / 만료 2026-10-29'],
    [27, '트리플코어스', 'https://triplecores.com/', 'Triple Cores', ''],
    [28, '한강전기', 'https://ihangang.net/', '한강전기', ''],
    [29, '티피와이(국문)', 'https://tpy21c.co.kr/', '티피와이', ''],
    [30, '티피와이(영문)', 'https://en.tpy21c.co.kr/', 'TPY', ''],
    [31, '바로텍', 'http://baro-tfc.co.kr/', '바로텍', '계약 / 도메인 만료 1.23'],
];

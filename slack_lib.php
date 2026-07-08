<?php
/**
 * Slack Lists API 공통 라이브러리
 *  - sync.php (DB 적재) 및 auth.php (로그인 검증)에서 공용으로 사용
 */

const SLACK_COL = [
    'title'    => 'Col084M59NZLH',
    'momo'     => 'Col08CPRJ4NTG',
    'req'      => 'Col08CPRLAPEJ',
    'asg'      => 'Col08CWBWRLR1',
    'status'   => 'Col08CPRG76J2',   // 진행상태 (등록/공수산정요청/진행중/확인요청.../완료 등)
    'priority' => 'Col08CFU29FLP',   // 우선순위 (일반/긴급)
    'team'     => 'Col08CWBVM0E7',   // 개발담당팀 (블루소프트/와이오즈/시스템개발/달빛소프트/프레임/미지정)
    'eta'      => 'Col08CPRK24VC',   // 예상처리완료일(고객안내용) - date
    'body'     => 'Col08D959GGF3',
    'lms'      => 'Col08D95DP1EV',
    'date'     => 'Col08D95FV00Z',
    'done'     => 'Col08M7L9963A',
    'attach'   => 'Col08CPRE07V4',   // 첨부파일 (파일ID 배열)
];

/**
 * 리스트 스키마에서 select 컬럼들의 [컬럼ID => [옵션ID => 라벨]] 맵을 만든다.
 * (옵션 라벨 하드코딩 대신 동적으로 읽어 변경에도 안전. 6시간 캐시)
 */
function slackSelectMaps($token, $listId, $sampleRecordId) {
    $cacheFile = sys_get_temp_dir() . '/slack_schema_' . md5($listId) . '.json';
    if (is_file($cacheFile) && (time() - filemtime($cacheFile) < 6 * 3600)) {
        $c = json_decode(file_get_contents($cacheFile), true);
        if (is_array($c)) return $c;
    }
    if (!$sampleRecordId) return [];
    $r = slackGet('slackLists.items.info', $token, ['list_id' => $listId, 'id' => $sampleRecordId]);
    $schema = $r['list']['list_metadata']['schema'] ?? [];
    $maps = [];
    foreach ($schema as $col) {
        if (($col['type'] ?? '') !== 'select') continue;
        $cid  = $col['id'] ?? ($col['key'] ?? '');
        $opts = $col['options']['choices'] ?? ($col['options'] ?? []);
        $m = [];
        foreach ($opts as $o) {
            $oid = $o['value'] ?? ($o['id'] ?? '');
            if ($oid !== '') $m[$oid] = $o['label'] ?? ($o['text'] ?? $oid);
        }
        if ($m) $maps[$cid] = $m;
    }
    if ($maps) @file_put_contents($cacheFile, json_encode($maps, JSON_UNESCAPED_UNICODE));
    return $maps;
}

function slackGet($method, $token, $params) {
    $url = "https://slack.com/api/$method?" . http_build_query($params);
    for ($try = 0; $try < 4; $try++) {
        $retryAfter = 0;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token],
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HEADERFUNCTION => function ($c, $h) use (&$retryAfter) {
                if (stripos($h, 'Retry-After:') === 0) $retryAfter = (int)trim(substr($h, 12));
                return strlen($h);
            },
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code == 429) { sleep($retryAfter > 0 ? $retryAfter : (1 << $try)); continue; }  // rate limit: 대기 후 재시도
        $res = json_decode($body, true);
        if (is_array($res)) return $res;          // 정상(ok=true) 또는 실제 에러(ok=false) 그대로 반환
        sleep(1 << $try);                          // 빈/비정상 응답 → 백오프 후 재시도
    }
    return ['ok' => false, 'error' => 'ratelimited'];
}

function slackPost($method, $token, $fields) {
    $ch = curl_init("https://slack.com/api/$method");
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token, 'Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_POSTFIELDS     => http_build_query($fields),
    ]);
    $res = json_decode(curl_exec($ch), true);
    curl_close($ch);
    return $res;
}

/** 레코드의 댓글 스레드 앵커 ts 찾기 (date_created 주변 시간창). 없으면 null. */
function slackFindRecordThread($token, $channel, $created, $rid) {
    // 앵커("A comment was added")는 레코드 생성 시점이 아니라 "첫 댓글이 달린 시각"에 생기므로
    // 생성보다 수 분~수 일 뒤일 수 있다(예: +232s). 창을 넓게(생성~+14일) 잡고, 창 안 메시지가
    // 많으면 페이지네이션으로 모두 훑는다. 댓글 없는 레코드에선 보통 1콜로 끝난다(저volume 채널).
    $cursor = null; $pg = 0;
    do {
        $p = ['channel' => $channel, 'oldest' => $created - 5, 'latest' => $created + 14 * 86400,
              'inclusive' => true, 'limit' => 200];
        if ($cursor) $p['cursor'] = $cursor;
        $h = slackGet('conversations.history', $token, $p);
        if (empty($h['ok'])) break;
        foreach (($h['messages'] ?? []) as $m) {
            if (strpos(json_encode($m), $rid) !== false) return $m['ts'];
        }
        $cursor = $h['response_metadata']['next_cursor'] ?? null;
    } while ($cursor && ++$pg < 12);
    return null;
}

/** 레코드 셀 1개 업데이트 (select/user 등). $value 는 배열. */
function slackUpdateCell($token, $listId, $rowId, $columnId, $valueKey, array $value) {
    $cells = json_encode([[ 'row_id' => $rowId, 'column_id' => $columnId, $valueKey => $value ]]);
    return slackPost('slackLists.items.update', $token, ['list_id' => $listId, 'cells' => $cells]);
}

function slackFetchItems($token, $listId) {
    $items = [];
    $cursor = null;
    do {
        // limit 을 크게 잡아 페이지(=HTTP 왕복) 수를 최소화 (100→1000: 약 4배 빠름)
        $params = ['list_id' => $listId, 'limit' => 1000];
        if ($cursor) $params['cursor'] = $cursor;
        $res = slackGet('slackLists.items.list', $token, $params);
        if (empty($res['ok'])) {
            return ['error' => isset($res['error']) ? $res['error'] : 'unknown'];
        }
        $items = array_merge($items, isset($res['items']) ? $res['items'] : []);
        $cursor = isset($res['response_metadata']['next_cursor']) ? $res['response_metadata']['next_cursor'] : null;
    } while ($cursor);
    return ['items' => $items];
}

function slackResolveUsers($token, $userIds) {
    $cacheFile = sys_get_temp_dir() . '/slack_users_cache.json';
    $cache = [];
    if (is_file($cacheFile) && (time() - filemtime($cacheFile) < 6 * 3600)) {
        $cache = json_decode(file_get_contents($cacheFile), true);
        if (!is_array($cache)) $cache = [];
    }
    $missing = array_diff(array_unique($userIds), array_keys($cache));
    foreach ($missing as $uid) {
        if (!$uid) continue;
        $res = slackGet('users.info', $token, ['user' => $uid]);
        if (!empty($res['ok'])) {
            $p = isset($res['user']['profile']) ? $res['user']['profile'] : [];
            if (isset($p['real_name'])) {
                $cache[$uid] = $p['real_name'];
            } elseif (isset($res['user']['real_name'])) {
                $cache[$uid] = $res['user']['real_name'];
            } else {
                $cache[$uid] = $uid;
            }
        } else {
            $cache[$uid] = $uid;
        }
    }
    if ($missing) {
        @file_put_contents($cacheFile, json_encode($cache, JSON_UNESCAPED_UNICODE));
    }
    return $cache;
}

function slackIndexFields($fields) {
    $map = [];
    foreach ($fields as $f) {
        $cid = isset($f['column_id']) ? $f['column_id'] : '';
        $map[$cid] = $f;
    }
    return $map;
}

function slackFieldText($f)   { return isset($f['text']) ? $f['text'] : (isset($f['value']) ? $f['value'] : ''); }
function slackFieldUser($f)   { return isset($f['user'][0])   ? $f['user'][0]   : null; }
function slackFieldSelect($f) { return isset($f['select'][0]) ? $f['select'][0] : null; }
function slackFieldDate($f)   { return isset($f['date'][0])   ? $f['date'][0]   : null; }
function slackFieldAttach($f) { return isset($f['attachment']) && is_array($f['attachment']) ? $f['attachment'] : []; }

/**
 * 파일 ID 목록을 files.info 로 해석해 [fileId => {name,mime,size,is_image,url,download,thumb,permalink}] 반환.
 * 파일 메타/URL 은 사실상 불변이므로 영구 캐시(파일 삭제 시에만 stale, 무시 가능).
 */
function slackResolveFiles($token, $fileIds) {
    // v2: pdf/office/동영상 미리보기 필드(thumb_pdf/thumb_video/mp4) 추가 → 캐시 버전업으로 재해석 유도
    $cacheFile = sys_get_temp_dir() . '/slack_files_cache_v2.json';
    $cache = [];
    if (is_file($cacheFile)) { $j = json_decode(file_get_contents($cacheFile), true); if (is_array($j)) $cache = $j; }
    $missing = array_diff(array_unique(array_filter($fileIds)), array_keys($cache));
    $changed = false; $sinceSave = 0;
    foreach ($missing as $fid) {
        $r = slackGet('files.info', $token, ['file' => $fid]);
        if (!empty($r['ok']) && isset($r['file'])) {
            $fl = $r['file'];
            $mime = $fl['mimetype'] ?? '';
            $cache[$fid] = [
                'name'      => $fl['name'] ?? ($fl['title'] ?? $fid),
                'mime'      => $mime,
                'size'      => (int)($fl['size'] ?? 0),
                'is_image'  => (strpos($mime, 'image/') === 0),
                'url'       => $fl['url_private'] ?? '',
                'download'  => $fl['url_private_download'] ?? ($fl['url_private'] ?? ''),
                'thumb'     => $fl['thumb_360'] ?? ($fl['thumb_160'] ?? ($fl['url_private'] ?? '')),
                'thumb_pdf'   => $fl['thumb_pdf'] ?? '',     // pdf/xlsx/office 첫 페이지 이미지
                'thumb_video' => $fl['thumb_video'] ?? '',   // 동영상 포스터
                'mp4'         => $fl['mp4'] ?? '',            // 브라우저 재생용 변환본
                'permalink' => $fl['permalink'] ?? '',
            ];
        } else {
            // 확실히 사라진 파일만 gone 으로 캐시(재조회 방지).
            // rate limit/일시 오류를 gone 으로 영구 캐시하면 멀쩡한 첨부가 계속 안 보이는 버그가 됨
            // → 일시 오류는 캐시하지 않고 다음 동기화 때 재시도.
            $err = $r['error'] ?? '';
            if (in_array($err, ['file_not_found', 'file_deleted'], true)) {
                $cache[$fid] = ['name' => $fid, 'mime' => '', 'size' => 0, 'is_image' => false,
                                'url' => '', 'download' => '', 'thumb' => '', 'thumb_pdf' => '',
                                'thumb_video' => '', 'mp4' => '', 'permalink' => '', 'gone' => true];
            } else {
                continue;   // 캐시 안 함 (다음 기회에 재해석)
            }
        }
        $changed = true;
        if (++$sinceSave >= 25) { @file_put_contents($cacheFile, json_encode($cache, JSON_UNESCAPED_UNICODE)); $sinceSave = 0; }  // 증분 저장(중단 대비)
    }
    if ($changed) @file_put_contents($cacheFile, json_encode($cache, JSON_UNESCAPED_UNICODE));
    return $cache;
}

/* =========================================================================
 * rich_text → Slack mrkdwn 복원 (링크 URL 보존)
 * Lists 필드의 flat 'text' 는 하이퍼링크 URL 을 잃는다. 필드의 'rich_text'(블록 구조)를
 * 파싱해 링크를 <url|표시텍스트> 형태로 복원한다. 다만 평문 재구성이 원본 'text' 와
 * 정확히 일치할 때만 사용(불일치 시 안전하게 flat 'text' 폴백) → 절대 본문을 훼손하지 않음.
 * ========================================================================= */
function slackRtRoman($n){ $m=['i','ii','iii','iv','v','vi','vii','viii','ix','x','xi','xii']; return $m[$n-1] ?? (string)$n; }
function slackRtStyle($x, $st){
    if (!is_array($st)) return $x;
    if (empty($st['code']) && empty($st['strike']) && empty($st['italic']) && empty($st['bold'])) return $x;
    preg_match('/^(\s*)([\s\S]*?)(\s*)$/u', $x, $mm);   // 스타일 마커 밖으로 앞뒤 공백 이동
    $lead = $mm[1] ?? ''; $core = $mm[2] ?? $x; $trail = $mm[3] ?? '';
    if ($core === '') return $x;
    if (!empty($st['code'])) return $lead.'`'.$core.'`'.$trail;
    if (!empty($st['strike'])) $core = '~'.$core.'~';
    if (!empty($st['italic'])) $core = '_'.$core.'_';
    if (!empty($st['bold']))   $core = '*'.$core.'*';
    return $lead.$core.$trail;
}
function slackRtEls($els, &$md, &$pl){
    foreach ($els as $e) {
        $t = $e['type'] ?? '';
        if ($t === 'link') {
            $u = $e['url'] ?? '';
            $lbl = (isset($e['text']) && $e['text'] !== '') ? $e['text'] : $u;
            $isHttp = (stripos($u, 'http://') === 0 || stripos($u, 'https://') === 0);
            $md .= ($isHttp && empty($e['style']['unlink']))
                 ? ('<' . $u . ($lbl !== $u ? '|' . $lbl : '') . '>')   // 클릭 가능한 링크
                 : $lbl;                                                 // mailto/unlink → 평문
            $pl .= $lbl;
        } elseif ($t === 'emoji') { $x = ':'.($e['name'] ?? '').':'; $md .= $x; $pl .= $x; }
        elseif ($t === 'user')  { $x = $e['text'] ?? ('<@'.($e['user_id'] ?? '').'>'); $md .= $x; $pl .= $x; }
        else { $x = $e['text'] ?? ''; $s = slackRtStyle($x, $e['style'] ?? null); $md .= $s; $pl .= $s; }
    }
}
function slackRtBlock($b, &$md, &$pl, &$st){
    $t = $b['type'] ?? '';
    if ($t === 'rich_text_section') { $st['ord'] = 0; slackRtEls($b['elements'] ?? [], $md, $pl); }
    elseif ($t === 'rich_text_list') {
        $style = $b['style'] ?? 'bullet'; $indent = (int)($b['indent'] ?? 0);
        $q = ((int)($b['border'] ?? 0) >= 1) ? '> ' : '';          // border → 인용 리스트
        $bul = $indent >= 2 ? '▪︎' : ($indent === 1 ? '◦' : '•');
        $items = $b['elements'] ?? []; $n = count($items);
        foreach ($items as $k => $sec) {
            if ($style === 'ordered') {
                if ($indent === 0) { $st['ord'] = ($st['ord'] ?? 0) + 1; $mark = $st['ord']; }   // 1. 2. 3.
                elseif ($indent === 1) { $mark = chr(97 + ($k % 26)); }                          // a. b. c.
                else { $mark = slackRtRoman($k + 1); }                                           // i. ii.
                $pre = $q . str_repeat('    ', $indent) . $mark . '. ';
            } else { $pre = $q . str_repeat('    ', $indent) . $bul . ' '; }
            $sm = ''; $sp = ''; slackRtEls($sec['elements'] ?? [], $sm, $sp);
            $md .= $pre . $sm; $pl .= $pre . $sp;
            if ($k < $n - 1) { $md .= "\n"; $pl .= "\n"; }
        }
    }
    elseif ($t === 'rich_text_preformatted') { $st['ord']=0; $sm=''; $sp=''; slackRtEls($b['elements'] ?? [], $sm, $sp); $md .= '```'.$sm.'```'; $pl .= '```'.$sm.'```'; }
    elseif ($t === 'rich_text_quote') { $st['ord']=0; $sm=''; $sp=''; slackRtEls($b['elements'] ?? [], $sm, $sp); $md .= preg_replace('/^/m','> ',$sm); $pl .= preg_replace('/^/m','> ',$sp); }
    else { slackRtEls($b['elements'] ?? [], $md, $pl); }
}
function slackRtParse($blocks){
    $real = [];   // 최상위 type=rich_text 래퍼를 펼쳐 실제 블록만 모음
    foreach ($blocks as $b) {
        if (($b['type'] ?? '') === 'rich_text') { foreach ($b['elements'] ?? [] as $c) $real[] = $c; }
        else $real[] = $b;
    }
    $md = ''; $pl = ''; $st = ['ord' => 0];
    foreach ($real as $b) {
        $bm = ''; $bp = ''; slackRtBlock($b, $bm, $bp, $st);
        if ($pl !== '') {   // 블록 사이 개행 하나 보장
            $prevNL = substr($pl, -1) === "\n";
            $nextNL = ($bp !== '' && $bp[0] === "\n");
            if (!$prevNL && !$nextNL) { $md .= "\n"; $pl .= "\n"; }
        }
        $md .= $bm; $pl .= $bp;
    }
    return [$md, $pl];
}
/** body 필드: 링크 보존 mrkdwn (평문 재구성이 원본 text 와 일치할 때만, 아니면 text 폴백) */
function slackFieldBody($f){
    $text = slackFieldText($f);
    if (empty($f['rich_text']) || !is_array($f['rich_text'])) return $text;
    list($md, $pl) = slackRtParse($f['rich_text']);
    return (rtrim($pl) === rtrim($text)) ? $md : $text;
}

/**
 * Slack Lists 항목을 정규화된 행 배열로 변환.
 *  - 사용자 ID는 실명으로 변환, 상태 ID는 라벨로 변환
 *  - 원본 ID(req_id/asg_id/status_id)도 함께 보존
 * @param int $sinceUpdated 0이면 전체, >0이면 updated_timestamp 가 이 값 이상인 항목만 처리(증분)
 * @return array ['rows'=>[...], 'scanned'=>int, 'maxUpdated'=>int] 또는 ['error'=>'...']
 */
function slackFetchRows($token, $listId, $sinceUpdated = 0, $colMap = null) {
    $COL = $colMap ?: SLACK_COL;   // 리스트별 컬럼 매핑(미지정 시 기본=블루소프트)
    $data = slackFetchItems($token, $listId);
    if (isset($data['error'])) return $data;

    $rows = [];
    $userIds = [];
    $allFileIds = [];
    $allIds = [];        // 리스트의 전체 라이브 항목 id (삭제 감지용, since 필터와 무관)
    $scanned = 0;
    $maxUpdated = 0;
    foreach ($data['items'] as $item) {
        $scanned++;
        if (isset($item['id']) && $item['id'] !== '') $allIds[] = $item['id'];
        $upd = isset($item['updated_timestamp']) ? (int)$item['updated_timestamp'] : 0;
        if ($upd > $maxUpdated) $maxUpdated = $upd;
        // 증분: 마지막 동기화 이후 변경된 항목만 처리 (이름변환 비용 절감)
        if ($sinceUpdated > 0 && $upd < $sinceUpdated) continue;

        $m = slackIndexFields(isset($item['fields']) ? $item['fields'] : []);

        $reqId = isset($m[$COL['req']]) ? slackFieldUser($m[$COL['req']]) : null;
        $asgId = isset($m[$COL['asg']]) ? slackFieldUser($m[$COL['asg']]) : null;
        $userIds[] = $reqId;
        $userIds[] = $asgId;

        $fileIds = isset($m[$COL['attach']]) ? slackFieldAttach($m[$COL['attach']]) : [];
        foreach ($fileIds as $fid) $allFileIds[] = $fid;

        $rows[] = [
            '_fileIds'    => $fileIds,
            'id'          => isset($item['id']) ? $item['id'] : '',
            'title'       => isset($m[$COL['title']]) ? slackFieldText($m[$COL['title']]) : '(제목 없음)',
            'body'        => isset($m[$COL['body']])  ? slackFieldBody($m[$COL['body']])  : '',
            'momo'        => isset($m[$COL['momo']])  ? slackFieldText($m[$COL['momo']])  : '',
            'lms'         => isset($m[$COL['lms']])   ? slackFieldText($m[$COL['lms']])   : '',
            'req_id'      => $reqId,
            'asg_id'      => $asgId,
            'status_id'   => isset($m[$COL['status']])   ? slackFieldSelect($m[$COL['status']])   : null,
            'priority_id' => isset($m[$COL['priority']]) ? slackFieldSelect($m[$COL['priority']]) : null,
            'team_id'     => isset($m[$COL['team']])     ? slackFieldSelect($m[$COL['team']])     : null,
            'date'        => isset($m[$COL['date']]) ? slackFieldDate($m[$COL['date']]) : null,
            'done'        => isset($m[$COL['done']]) ? slackFieldDate($m[$COL['done']]) : null,
            'eta'         => isset($m[$COL['eta']])  ? slackFieldDate($m[$COL['eta']])  : null,
            'created'     => isset($item['date_created']) ? (int)$item['date_created'] : 0,
            'updated'     => isset($item['updated_timestamp']) ? (int)$item['updated_timestamp'] : 0,
        ];
    }

    // select 옵션 라벨 맵 (스키마에서 동적). 샘플 레코드 = 첫 아이템
    $sampleId = isset($data['items'][0]['id']) ? $data['items'][0]['id'] : null;
    $sel = slackSelectMaps($token, $listId, $sampleId);
    $statusMap   = $sel[$COL['status']]   ?? [];
    $priorityMap = $sel[$COL['priority']] ?? [];
    $teamMap     = $sel[$COL['team']]     ?? [];

    $names = slackResolveUsers($token, $userIds);
    $files = slackResolveFiles($token, $allFileIds);   // 파일ID → 메타(영구 캐시)
    foreach ($rows as $i => $r) {
        $rows[$i]['req']      = $r['req_id'] ? (isset($names[$r['req_id']]) ? $names[$r['req_id']] : $r['req_id']) : '—';
        $rows[$i]['asg']      = $r['asg_id'] ? (isset($names[$r['asg_id']]) ? $names[$r['asg_id']] : $r['asg_id']) : '—';
        $rows[$i]['status']   = $r['status_id']   ? ($statusMap[$r['status_id']]     ?? $r['status_id'])   : '';
        $rows[$i]['priority'] = $r['priority_id'] ? ($priorityMap[$r['priority_id']] ?? $r['priority_id']) : '';
        $rows[$i]['team']     = $r['team_id']     ? ($teamMap[$r['team_id']]         ?? $r['team_id'])     : '';
        // 첨부: 파일ID → 메타 배열(존재하는 것만), JSON 저장용
        $atts = [];
        foreach ($r['_fileIds'] as $fid) { if (isset($files[$fid]) && empty($files[$fid]['gone'])) $atts[] = $files[$fid]; }
        $rows[$i]['attachments'] = $atts ? json_encode($atts, JSON_UNESCAPED_UNICODE) : null;
        unset($rows[$i]['_fileIds']);
    }

    usort($rows, function($a, $b) { return $b['created'] - $a['created']; });
    return ['rows' => array_values($rows), 'scanned' => $scanned, 'maxUpdated' => $maxUpdated, 'allIds' => $allIds];
}

/**
 * 백엔드 채널 전체를 훑어 [recId => 댓글수(reply_count)] 맵 생성.
 *  - 앵커 메시지의 reply_count = 그 레코드 댓글 수
 *  - 비용이 크므로 (수십초) 자주 호출 금지 (주기적 스캔 용)
 * @return array ['counts'=>[recId=>int]] 또는 ['error'=>'...']
 */
function slackCommentCounts($token, $channel) {
    $map = []; $cursor = null; $guard = 0;
    do {
        $p = ['channel' => $channel, 'limit' => 1000];
        if ($cursor) $p['cursor'] = $cursor;
        $r = slackGet('conversations.history', $token, $p);
        if (empty($r['ok'])) return ['error' => $r['error'] ?? 'unknown'];
        foreach ($r['messages'] as $m) {
            if (preg_match('/Rec[0-9A-Z]{8,}/', json_encode($m), $mm)) {
                $map[$mm[0]] = (int)($m['reply_count'] ?? 0);
            }
        }
        $cursor = $r['response_metadata']['next_cursor'] ?? null;
    } while ($cursor && ++$guard < 30);
    return ['counts' => $map];
}

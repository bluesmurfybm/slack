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
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token],
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $res = json_decode(curl_exec($ch), true);
    curl_close($ch);
    return $res;
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
    $h = slackGet('conversations.history', $token, [
        'channel' => $channel, 'oldest' => $created - 2, 'latest' => $created + 5,
        'inclusive' => true, 'limit' => 50,
    ]);
    foreach (($h['messages'] ?? []) as $m) {
        if (strpos(json_encode($m), $rid) !== false) return $m['ts'];
    }
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

/**
 * Slack Lists 항목을 정규화된 행 배열로 변환.
 *  - 사용자 ID는 실명으로 변환, 상태 ID는 라벨로 변환
 *  - 원본 ID(req_id/asg_id/status_id)도 함께 보존
 * @param int $sinceUpdated 0이면 전체, >0이면 updated_timestamp 가 이 값 이상인 항목만 처리(증분)
 * @return array ['rows'=>[...], 'scanned'=>int, 'maxUpdated'=>int] 또는 ['error'=>'...']
 */
function slackFetchRows($token, $listId, $sinceUpdated = 0) {
    $data = slackFetchItems($token, $listId);
    if (isset($data['error'])) return $data;

    $rows = [];
    $userIds = [];
    $scanned = 0;
    $maxUpdated = 0;
    foreach ($data['items'] as $item) {
        $scanned++;
        $upd = isset($item['updated_timestamp']) ? (int)$item['updated_timestamp'] : 0;
        if ($upd > $maxUpdated) $maxUpdated = $upd;
        // 증분: 마지막 동기화 이후 변경된 항목만 처리 (이름변환 비용 절감)
        if ($sinceUpdated > 0 && $upd < $sinceUpdated) continue;

        $m = slackIndexFields(isset($item['fields']) ? $item['fields'] : []);

        $reqId = isset($m[SLACK_COL['req']]) ? slackFieldUser($m[SLACK_COL['req']]) : null;
        $asgId = isset($m[SLACK_COL['asg']]) ? slackFieldUser($m[SLACK_COL['asg']]) : null;
        $userIds[] = $reqId;
        $userIds[] = $asgId;

        $rows[] = [
            'id'          => isset($item['id']) ? $item['id'] : '',
            'title'       => isset($m[SLACK_COL['title']]) ? slackFieldText($m[SLACK_COL['title']]) : '(제목 없음)',
            'body'        => isset($m[SLACK_COL['body']])  ? slackFieldText($m[SLACK_COL['body']])  : '',
            'momo'        => isset($m[SLACK_COL['momo']])  ? slackFieldText($m[SLACK_COL['momo']])  : '',
            'lms'         => isset($m[SLACK_COL['lms']])   ? slackFieldText($m[SLACK_COL['lms']])   : '',
            'req_id'      => $reqId,
            'asg_id'      => $asgId,
            'status_id'   => isset($m[SLACK_COL['status']])   ? slackFieldSelect($m[SLACK_COL['status']])   : null,
            'priority_id' => isset($m[SLACK_COL['priority']]) ? slackFieldSelect($m[SLACK_COL['priority']]) : null,
            'team_id'     => isset($m[SLACK_COL['team']])     ? slackFieldSelect($m[SLACK_COL['team']])     : null,
            'date'        => isset($m[SLACK_COL['date']]) ? slackFieldDate($m[SLACK_COL['date']]) : null,
            'done'        => isset($m[SLACK_COL['done']]) ? slackFieldDate($m[SLACK_COL['done']]) : null,
            'eta'         => isset($m[SLACK_COL['eta']])  ? slackFieldDate($m[SLACK_COL['eta']])  : null,
            'created'     => isset($item['date_created']) ? (int)$item['date_created'] : 0,
            'updated'     => isset($item['updated_timestamp']) ? (int)$item['updated_timestamp'] : 0,
        ];
    }

    // select 옵션 라벨 맵 (스키마에서 동적). 샘플 레코드 = 첫 아이템
    $sampleId = isset($data['items'][0]['id']) ? $data['items'][0]['id'] : null;
    $sel = slackSelectMaps($token, $listId, $sampleId);
    $statusMap   = $sel[SLACK_COL['status']]   ?? [];
    $priorityMap = $sel[SLACK_COL['priority']] ?? [];
    $teamMap     = $sel[SLACK_COL['team']]     ?? [];

    $names = slackResolveUsers($token, $userIds);
    foreach ($rows as $i => $r) {
        $rows[$i]['req']      = $r['req_id'] ? (isset($names[$r['req_id']]) ? $names[$r['req_id']] : $r['req_id']) : '—';
        $rows[$i]['asg']      = $r['asg_id'] ? (isset($names[$r['asg_id']]) ? $names[$r['asg_id']] : $r['asg_id']) : '—';
        $rows[$i]['status']   = $r['status_id']   ? ($statusMap[$r['status_id']]     ?? $r['status_id'])   : '';
        $rows[$i]['priority'] = $r['priority_id'] ? ($priorityMap[$r['priority_id']] ?? $r['priority_id']) : '';
        $rows[$i]['team']     = $r['team_id']     ? ($teamMap[$r['team_id']]         ?? $r['team_id'])     : '';
    }

    usort($rows, function($a, $b) { return $b['created'] - $a['created']; });
    return ['rows' => array_values($rows), 'scanned' => $scanned, 'maxUpdated' => $maxUpdated];
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

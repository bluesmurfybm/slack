<?php
/** P1 ProjectRepo 통합 테스트 — 실제 MySQL 5.7 에 붙어 쿼리를 돌린다. */
declare(strict_types=1);

// 저장소 어디에 두어도 돌도록 이 파일 위치에서 포털 루트를 찾는다
define('ROOT', dirname(__DIR__, 2));
// 운영/개발 DB 를 건드리지 않도록 **전용 시험 DB** 를 쓴다.
// 없으면 아래 안내대로 만들면 된다.
$TESTDB = getenv('BA_TEST_DB') ?: 'blueassign_test';
require ROOT . '/studio/inc/bootstrap.php';
require ROOT . '/studio/inc/repo/ProjectRepo.php';

try {
$pdo = new PDO("mysql:host=127.0.0.1;dbname={$TESTDB};charset=utf8mb4", 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
} catch (PDOException $e) {
    fwrite(STDERR, "
시험 DB '{$TESTDB}' 에 붙지 못했습니다.
"
        . "먼저 만드세요:
"
        . "  mysql -u root -e \"CREATE DATABASE {$TESTDB} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci\"
"
        . "  mysql -u root {$TESTDB} < assign/sql/001_schema.sql
"
        . "  mysql -u root {$TESTDB} < assign/sql/002_seed_domain.sql

");
    exit(2);
}
$pdo->exec("SET time_zone = '+09:00'");
$pdo->exec('DELETE FROM ba_project_source');
$pdo->exec('DELETE FROM ba_project');

$repo  = new ProjectRepo($pdo);
$actor = ['id' => 'kimhy@bluesoft.co.kr', 'name' => '김호영'];
$other = ['id' => 'jian@bluesoft.co.kr',  'name' => '김지안'];

$pass = 0; $fail = 0;
function ok(string $what, bool $cond, string $extra = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  OK   $what\n"; }
    else       { $fail++; echo "  FAIL $what" . ($extra ? " — $extra" : '') . "\n"; }
}
function throws(string $what, callable $fn, string $expectClass = InvalidArgumentException::class): void {
    global $pass, $fail;
    try { $fn(); $fail++; echo "  FAIL $what — 예외가 안 났다\n"; }
    catch (Throwable $e) {
        if ($e instanceof $expectClass) { $pass++; echo "  OK   $what → " . $e->getMessage() . "\n"; }
        else { $fail++; echo "  FAIL $what — 다른 예외: " . get_class($e) . ' ' . $e->getMessage() . "\n"; }
    }
}

echo "\n[1] 채번\n";
ok('첫 코드 PRJ-2026-001', $repo->nextCode(2026) === 'PRJ-2026-001', $repo->nextCode(2026));

echo "\n[2] 생성\n";
$id1 = $repo->create([
    'name' => 'OO대 LXP 고도화', 'client' => 'OO대학교', 'track' => 'lxp',
    'summary' => '학습경험 플랫폼 고도화',
    'dev_start' => '2026-03-02', 'dev_end' => '2026-05-29',
    'test_start' => '2026-05-11', 'test_end' => '2026-06-12',
    'deploy_date' => '2026-06-22', 'notes' => '특이점 없음',
], $actor);
ok('id 반환', $id1 > 0);
$p1 = $repo->find($id1);
ok('code 자동 채번', $p1['code'] === 'PRJ-2026-001', (string)$p1['code']);
ok('owner 스냅샷', $p1['owner_id'] === $actor['id'] && $p1['owner_name'] === '김호영');
ok('기본 상태 draft', $p1['status'] === 'draft');

$id2 = $repo->create(['name' => '사내 포털 개선', 'track' => '사내',
                      'dev_start' => '2026-07-01', 'dev_end' => '2026-08-31'], $other);
ok('두 번째 코드 -002', $repo->find($id2)['code'] === 'PRJ-2026-002');
$id3 = $repo->create(['name' => '지난해 유지보수', 'track' => 'lms_b2b',
                      'dev_start' => '2025-01-06', 'dev_end' => '2025-12-20'], $actor);
ok('연도별 채번', $repo->find($id3)['code'] === 'PRJ-2025-001', $repo->find($id3)['code']);

echo "\n[3] 빈 날짜는 NULL 로\n";
$id4 = $repo->create(['name' => '기간 미정 건', 'dev_start' => '', 'deploy_date' => ''], $actor);
ok('빈 문자열 → NULL', $repo->find($id4)['dev_start'] === null);

echo "\n[4] 기간 역전 검증 (5개 규칙)\n";
throws('개발 시작>종료',   fn() => $repo->create(['name'=>'x','dev_start'=>'2026-05-01','dev_end'=>'2026-04-01'], $actor));
throws('테스트 시작>종료', fn() => $repo->create(['name'=>'x','test_start'=>'2026-05-01','test_end'=>'2026-04-01'], $actor));
throws('테스트<개발시작',  fn() => $repo->create(['name'=>'x','dev_start'=>'2026-05-01','test_start'=>'2026-04-01'], $actor));
throws('배포<개발시작',    fn() => $repo->create(['name'=>'x','dev_start'=>'2026-05-01','deploy_date'=>'2026-04-01'], $actor));
throws('배포<테스트시작',  fn() => $repo->create(['name'=>'x','test_start'=>'2026-05-01','deploy_date'=>'2026-04-01'], $actor));
$idOverlap = $repo->create(['name'=>'겹침 허용','dev_start'=>'2026-03-01','dev_end'=>'2026-05-31',
                            'test_start'=>'2026-05-01','test_end'=>'2026-06-30'], $actor);
ok('개발·테스트 겹침은 허용', $idOverlap > 0);

echo "\n[5] 수정\n";
$repo->update($id1, ['name' => 'OO대 LXP 고도화 (2차)', 'client' => 'OO대학교 정보전산원']);
$p1 = $repo->find($id1);
ok('이름 변경', $p1['name'] === 'OO대 LXP 고도화 (2차)');
ok('안 보낸 칸은 유지', $p1['track'] === 'lxp');
throws('수정 시에도 기간 검증', fn() => $repo->update($id1, ['dev_end' => '2026-01-01']));
ok('실패한 수정은 반영 안 됨', $repo->find($id1)['dev_end'] === '2026-05-29');

echo "\n[6] 상태\n";
$repo->updateStatus($id1, 'scoping', $actor);
ok('상태 변경', $repo->find($id1)['status'] === 'scoping');
throws('모르는 상태 거부', fn() => $repo->updateStatus($id1, 'bogus', $actor));

echo "\n[7] 검색\n";
$r = $repo->search([]);
ok('전체 조회', $r['total'] === 5, '총 ' . $r['total']);
ok('source_count 포함', array_key_exists('source_count', $r['rows'][0]));
$r = $repo->search(['status' => 'scoping']);
ok('상태 필터', $r['total'] === 1);
$r = $repo->search(['track' => 'lxp']);
ok('트랙 필터', $r['total'] === 1);
$r = $repo->search(['owner_id' => $other['id']]);
ok('담당자 필터', $r['total'] === 1);
$r = $repo->search(['keyword' => '정보전산원']);
ok('키워드(고객)', $r['total'] === 1);
$r = $repo->search(['keyword' => 'PRJ-2025']);
ok('키워드(코드)', $r['total'] === 1);

echo "\n[8] LIKE 특수문자 / 주입 시도\n";
$r = $repo->search(['keyword' => '%']);
ok('% 는 전건 조회가 되면 안 된다', $r['total'] === 0, '총 ' . $r['total']);
$r = $repo->search(['keyword' => "' OR 1=1 -- "]);
ok('주입 문자열은 그냥 검색어', $r['total'] === 0);
$r = $repo->search(['sort' => 'nonsense; DROP TABLE ba_project']);
ok('정렬 화이트리스트', $r['total'] === 5 && $repo->find($id1) !== null);

echo "\n[9] 기간 겹침 필터\n";
$r = $repo->search(['from' => '2026-07-01', 'to' => '2026-07-31']);
$names = array_column($r['rows'], 'name');
ok('7월과 겹치는 건만', in_array('사내 포털 개선', $names, true), implode(',', $names));
ok('2025년 건은 빠짐', !in_array('지난해 유지보수', $names, true));
$r = $repo->search(['from' => '2026-04-01', 'to' => '2026-04-30']);
ok('장기 프로젝트도 중간 기간에 잡힘',
   in_array('OO대 LXP 고도화 (2차)', array_column($r['rows'], 'name'), true));

echo "\n[10] 페이징\n";
$r = $repo->search(['size' => 2, 'page' => 1]);
ok('한 쪽 2건', count($r['rows']) === 2 && $r['pages'] === 3, "pages={$r['pages']}");
$r = $repo->search(['size' => 2, 'page' => 99]);
ok('넘친 page 는 마지막으로', $r['page'] === 3);
$r = $repo->search(['size' => 9999]);
ok('size 상한 100', $r['size'] === 100);

echo "\n[11] 상태 건수\n";
$c = $repo->statusCounts([]);
ok('전체 합', $c[''] === 5, (string)$c['']);
ok('scoping 1건', $c['scoping'] === 1);
ok('상태 필터가 집계에 영향 없음', $repo->statusCounts(['status' => 'draft'])[''] === 5);

echo "\n[12] 소프트 삭제\n";
$repo->softDelete($id2, $actor, '중복 등록');
ok('기본 조회에서 사라짐', $repo->find($id2) === null);
ok('withDeleted 로는 보임', $repo->find($id2, true) !== null);
ok('삭제자 기록', $repo->find($id2, true)['deleted_by_name'] === '김호영');
ok('사유 기록', $repo->find($id2, true)['delete_reason'] === '중복 등록');
ok('목록에서 빠짐', $repo->search([])['total'] === 4);
ok('with_deleted 목록', $repo->search(['with_deleted' => true])['total'] === 5);
ok('code 는 계속 점유', $repo->nextCode(2026) === 'PRJ-2026-005', $repo->nextCode(2026));
throws('지워진 건 재삭제 거부', fn() => $repo->softDelete($id2, $actor), DomainException::class);
$repo->restore($id2);
ok('복구', $repo->find($id2) !== null);

echo "\n[13] 출처 문서\n";
$s1 = $repo->addSource($id1, ['kind'=>'xlsx','title'=>'범위정의서.xlsx','file_path'=>'/tmp/x.xlsx',
                              'file_size'=>12345,'mime'=>'application/zip','parse_status'=>'pending'], $actor);
$s2 = $repo->addSource($id1, ['kind'=>'figma','title'=>'시안','url'=>'https://figma.com/file/abc',
                              'parse_status'=>'skip'], $actor);
$s3 = $repo->addSource($id1, ['kind'=>'text','title'=>'회의록','parsed_text'=>"첫 줄\n둘째 줄",
                              'parse_status'=>'ok'], $actor);
ok('3건 등록', $repo->countSources($id1) === 3);
$list = $repo->sources($id1);
ok('parsed_text 대신 길이만', !array_key_exists('parsed_text', $list[0]) && array_key_exists('parsed_len', $list[0]));
ok('텍스트 길이 계산', (int)$list[2]['parsed_len'] === 8, (string)$list[2]['parsed_len']);
ok('업로더 스냅샷', $list[0]['uploaded_by_name'] === '김호영');
$pending = $repo->pendingSources();
ok('대기 건만 집어감', count($pending) === 1 && (int)$pending[0]['id'] === $s1);
$repo->updateSourceParse($s1, 'ok', '추출된 본문', null);
ok('파싱 반영 후 대기 0건', count($repo->pendingSources()) === 0);
$repo->deleteSource($s2);
ok('삭제', $repo->countSources($id1) === 2);
throws('없는 문서 삭제 거부', fn() => $repo->deleteSource(999999), DomainException::class);

echo "\n[14] 출처는 프로젝트를 지워도 따라 지워지지 않는다(소프트 삭제라서)\n";
$repo->softDelete($id1, $actor, 'x');
ok('출처 유지', $repo->countSources($id1) === 2);
$repo->restore($id1);

echo "\n=============================\n";
echo "통과 $pass / 실패 $fail\n";
exit($fail > 0 ? 1 : 0);

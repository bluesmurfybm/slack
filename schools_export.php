<?php
/**
 * 현재 schools 테이블 → schools_seed.json 내보내기 (CLI)
 *   - 테이블을 절대 수정하지 않음(읽기 전용). 현재 데이터(삭제·미사용 상태 포함) 그대로 반영.
 *   - 관리 페이지에서 편집한 뒤 이 스크립트를 실행하고 schools_seed.json 을 git 커밋하면
 *     clone 받은 사용자가 동일한 데이터로 자동 시딩됨.
 *   사용:  php schools_export.php
 */
require_once __DIR__ . '/db.php';

$rows = db()->query("SELECT name, ver, dev, ops, active FROM schools ORDER BY ver+0, name")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as &$r) { $r['active'] = (int)$r['active']; }
unset($r);

file_put_contents(__DIR__ . '/schools_seed.json', json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
echo count($rows) . "건을 schools_seed.json 으로 내보냈습니다 (현재 테이블 그대로).\n";

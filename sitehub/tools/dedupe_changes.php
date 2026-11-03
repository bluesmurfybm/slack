<?php
/**
 * 1회용 정리 — lab_site_changes 에서 이전 값과 새 값이 같은(빈 값끼리 포함) 칸 기록을 지운다.
 *  CLI : php sitehub/tools/dedupe_changes.php [apply]   (apply 없으면 지울 건수만 출력)
 *  비밀번호 기록(new_value = '(변경됨)')과 칸 없는 기록(만들기·삭제 등)은 건드리지 않는다.
 */
date_default_timezone_set('Asia/Seoul');
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../db.php';

$pdo   = sitehub_db();
$apply = in_array('apply', array_slice($argv, 1), true);
$norm  = "TRIM(CASE WHEN TRIM(COALESCE(%s, '')) = '—' THEN '' ELSE COALESCE(%s, '') END)";
$o = sprintf($norm, 'old_value', 'old_value');
$n = sprintf($norm, 'new_value', 'new_value');
$where = "field IS NOT NULL AND new_value <=> '(변경됨)' IS NOT TRUE AND BINARY $o = BINARY $n";

$cnt = (int)$pdo->query("SELECT COUNT(*) FROM lab_site_changes WHERE $where")->fetchColumn();
echo "지울 대상: {$cnt}건\n";
if ($apply && $cnt > 0) {
  $del = $pdo->exec("DELETE FROM lab_site_changes WHERE $where");
  echo "삭제: {$del}건\n";
} elseif (!$apply) {
  echo "적용하려면: php sitehub/tools/dedupe_changes.php apply\n";
}

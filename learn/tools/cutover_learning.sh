#!/usr/bin/env bash
#
# learning(FastAPI/SQLite) → learn(PHP/MySQL) 운영 전환. 서버에서 한 번만 돌린다.
#
#   ./learn/tools/cutover_learning.sh             점검만 한다 (아무것도 쓰지 않는다)
#   ./learn/tools/cutover_learning.sh --migrate   백업 → 이관 → 대조
#   ./learn/tools/cutover_learning.sh --remove    대조를 통과했으면 learning/ 을 지운다
#
# 순서가 중요하다. 이관보다 폴더 삭제가 먼저면 원본 SQLite 가 사라진다. 그래서 --remove 는
# 대조를 다시 돌려 통과할 때만 지운다.
#
# systemctl·nginx 는 root 권한이 필요해 여기서 건드리지 않는다. 할 일을 마지막에 찍어 준다.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT"

SQLITE="$ROOT/learning/var/learning.db"
UPLOADS="$ROOT/learning/var/uploads"
BACKUP_DIR="$ROOT/learn/var/cutover-backup"
MODE="check"

for a in "$@"; do
  case "$a" in
    --migrate) MODE="migrate" ;;
    --remove)  MODE="remove" ;;
    -h|--help) sed -n '2,14p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
    *) echo "모르는 인자: $a" >&2; exit 2 ;;
  esac
done

say()  { printf '%s\n' "$*"; }
head_() { printf '\n== %s ==\n' "$*"; }
die()  { printf '\n[중단] %s\n' "$*" >&2; exit 1; }

PHP="$(command -v php || true)"
[ -n "$PHP" ] || die "php 가 PATH 에 없다"

# ---------------------------------------------------------------- 점검

head_ "점검"

say "저장소   : $ROOT"
say "실행 계정: $(id -un)"

OWNER="$(stat -c '%U' "$ROOT/.git" 2>/dev/null || echo '?')"
say "저장소 소유: $OWNER"
if [ "$OWNER" != "$(id -un)" ]; then
  die "저장소 소유자($OWNER)와 실행 계정($(id -un))이 다르다.
     'sudo -iu $OWNER' 로 전환한 뒤 다시 실행한다.
     safe.directory 로 넘기면 .git 에 쓰지 못해 곧 막히고, 통과하더라도
     새로 만들어진 파일이 서비스 계정 소유가 아니게 된다."
fi

[ -f "$SQLITE" ] || die "learning.db 가 없다: $SQLITE
     git pull 은 추적 파일만 지우므로 learning/var/ 는 남아 있어야 한다.
     이미 지웠다면 백업에서 되살린 뒤 --db= 경로로 지정해 이관한다."
say "원본 DB  : $SQLITE ($(du -h "$SQLITE" | cut -f1))"
[ -d "$UPLOADS" ] && say "원본 이수증: $UPLOADS ($(find "$UPLOADS" -type f | wc -l)개)" \
                  || say "원본 이수증: (폴더 없음)"

# learn 쪽 현재 상태 — 이관은 신청·이수증·이력을 통째로 갈아끼운다.
# learn 에서 새로 만들어진 신청이 있으면 그게 날아가므로 먼저 세어 본다.
STATE="$("$PHP" -r '
require_once "learn/guard.php"; require_once "learn/db.php";
$d = learn_db();
$n   = (int)$d->query("SELECT COUNT(*) FROM learn_requests")->fetchColumn();
$cat = (int)$d->query("SELECT COUNT(*) FROM learn_requests WHERE catalog_id>0")->fetchColumn();
$cal = (int)$d->query("SELECT COUNT(*) FROM learn_catalog")->fetchColumn();
echo "$n $cat $cal";
')" || die "learn DB 에 붙지 못했다. config.php 의 db 설정을 확인한다."

read -r LEARN_N LEARN_CAT CATALOG_N <<< "$STATE"
say "learn 현재: 신청 ${LEARN_N}건 (그중 추천·필수에서 온 것 ${LEARN_CAT}건), 카탈로그 ${CATALOG_N}건"

SRC_N="$("$PHP" -r '
$p = new PDO("sqlite:" . $argv[1]);
echo (int)$p->query("SELECT COUNT(*) FROM learning_requests")->fetchColumn();
' "$SQLITE")"
say "learning  : 신청 ${SRC_N}건"

if [ "$LEARN_CAT" -gt 0 ]; then
  cat <<MSG

[!] learn 에서 추천·필수 강의로 만들어진 신청이 ${LEARN_CAT}건 있다.
    이관은 learn_requests 를 비우고 learning 것으로 다시 채우므로 이 ${LEARN_CAT}건이 사라진다.
    learning 을 쓰던 시점의 데이터만 옮기는 게 맞다면, 먼저 이 신청들을 어떻게 할지
    정하고 나서 진행한다. (아래 --migrate 는 백업을 먼저 뜨지만, 되살리는 건 수작업이다.)
MSG
  [ "$MODE" = "check" ] || die "추천·필수 신청 ${LEARN_CAT}건이 사라진다. 확인 후 진행한다."
fi

head_ "이관 미리보기 (--dry-run, 쓰지 않는다)"
# --force 를 같이 주는 건 "이미 데이터가 있다" 는 안전장치를 넘기기 위해서다.
# 도구는 --dry-run 이면 건수만 찍고 트랜잭션에 들어가기 전에 멈춘다 — 여기서는 쓰지 않는다.
"$PHP" learn/tools/migrate_from_learning.php --db="$SQLITE" --uploads="$UPLOADS" --dry-run --force

if [ "$MODE" = "check" ]; then
  cat <<MSG

점검만 했고 아무것도 바꾸지 않았다.
이어서 진행하려면:  $0 --migrate
MSG
  exit 0
fi

# ---------------------------------------------------------------- 이관

if [ "$MODE" = "migrate" ]; then
  head_ "백업"
  mkdir -p "$BACKUP_DIR"
  STAMP="$(date +%Y%m%d-%H%M%S)"
  DUMP="$BACKUP_DIR/learn-$STAMP.sql"

  "$PHP" -r '
  require_once "learn/guard.php"; require_once "learn/db.php";
  $d = learn_db();
  $out = fopen($argv[1], "w");
  fwrite($out, "-- learn_* 백업 " . date("c") . "\nSET FOREIGN_KEY_CHECKS=0;\n");
  foreach (["learn_requests","learn_certs","learn_histories","learn_sites",
            "learn_categories","learn_admins","learn_policy","learn_catalog"] as $t) {
      $ddl = $d->query("SHOW CREATE TABLE `$t`")->fetch(PDO::FETCH_NUM)[1];
      fwrite($out, "\nDROP TABLE IF EXISTS `$t`;\n$ddl;\n");
      foreach ($d->query("SELECT * FROM `$t`") as $r) {
          $vals = array_map(fn($v) => $v === null ? "NULL" : $d->quote((string)$v), array_values($r));
          $cols = "`" . implode("`,`", array_keys($r)) . "`";
          fwrite($out, "INSERT INTO `$t` ($cols) VALUES (" . implode(",", $vals) . ");\n");
      }
  }
  fwrite($out, "\nSET FOREIGN_KEY_CHECKS=1;\n");
  fclose($out);
  ' "$DUMP"

  say "MySQL learn_* → $DUMP ($(du -h "$DUMP" | cut -f1))"

  cp -a "$SQLITE" "$BACKUP_DIR/learning-$STAMP.db"
  say "SQLite 원본 → $BACKUP_DIR/learning-$STAMP.db"

  head_ "이관"
  "$PHP" learn/tools/migrate_from_learning.php --db="$SQLITE" --uploads="$UPLOADS" --force

  head_ "대조"
  if "$PHP" learn/tools/verify_migration.php --db="$SQLITE" --uploads="$UPLOADS"; then
    cat <<MSG

이관과 대조가 끝났다. 화면에서 한 번 확인한 뒤 원본을 지운다:
  $0 --remove
MSG
  else
    die "대조에서 불일치가 나왔다. learning/ 을 지우지 마라.
     되돌리려면: mysql <DB> < $DUMP"
  fi
  exit 0
fi

# ---------------------------------------------------------------- 원본 삭제

if [ "$MODE" = "remove" ]; then
  head_ "대조 재확인"
  "$PHP" learn/tools/verify_migration.php --db="$SQLITE" --uploads="$UPLOADS" \
    || die "대조를 통과하지 못했다. 지우지 않는다."

  head_ "삭제"
  KEEP="$BACKUP_DIR/learning-final-$(date +%Y%m%d-%H%M%S).tar.gz"
  tar czf "$KEEP" learning
  say "지우기 전 통째로 보관: $KEEP ($(du -h "$KEEP" | cut -f1))"
  rm -rf "$ROOT/learning"
  say "learning/ 삭제 완료"

  cat <<'MSG'

== 남은 일 (root 권한이 필요하다) ==

  exit                      # blueapp_core 에서 빠져나온다

  sudo systemctl stop learning
  sudo systemctl disable learning

  # /etc/nginx/sites-available/slack 에서
  #   location /learning/    { ... }
  #   location /learningapi/ { ... }
  # 두 블록을 지우고, 아래 두 가지를 넣는다.
  #
  #   location ^~ /learn/var/ { deny all; }     ← 이수증 원본이 URL 로 열리면 안 된다
  #   client_max_body_size 52m;                 ← server 블록에. 없으면 업로드가 413
  #
  # learn/var/.htaccess 는 nginx 가 읽지 않으므로 위 deny 블록이 유일한 차단이다.

  sudo nginx -t && sudo systemctl reload nginx

  # 서버 config.php 의 links 에서 'learning' 항목을 지운다 (gitignore 라 pull 로 안 온다)

끝나면 이 스크립트와 migrate_from_learning.php, verify_migration.php 를 지운다 — 일회성이다.
MSG
  exit 0
fi

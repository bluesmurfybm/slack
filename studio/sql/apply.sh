#!/bin/sh
# =====================================================================
# BlueStudio 스키마 적용 — 운영/신규 환경용
#
#   sh sql/apply.sh <db이름> [-y] [-u <user>] [-p] [--host <h>] [--port <n>]
#
# 예)
#   sh sql/apply.sh iworks -u iworks -p
#   sh sql/apply.sh iworks_local -y
#
# 왜 이 스크립트가 필요한가 — 두 가지 때문이다.
#
#  1) 번호 순서대로 다 돌리면 003 에서 멈춘다.
#     003·004 가 추가하는 컬럼은 001_schema.sql 에 이미 들어 있다.
#     아무것도 없는 DB 에 001 부터 넣으면 003 은 Duplicate column 으로 죽고,
#     mysql 은 그 파일을 중단한다. 뒤의 004~012 가 통째로 빠진다.
#     → 신규 설치에서는 003·004 를 건너뛴다. 아래 SKIP 참고.
#
#  2) --default-character-set=utf8mb4 를 빠뜨리면 한글 COMMENT 가 깨진다.
#     표와 컬럼은 멀쩡히 생기고 주석만 ???? 가 되므로 알아채기 어렵다.
#     → 여기서 항상 붙인다.
#
# 되돌리기는 docs 가 아니라 dump.sh 가 맡는다. 적용 전에 반드시 먼저 떠라.
# (010·011·012 만 되돌리기 스크립트가 있고 001~009 는 없다.)
# =====================================================================

set -e

DIR=$(dirname "$0")
DB=""
YES=0
MYSQL_ARGS=""
ASK_PW=0

while [ $# -gt 0 ]; do
  case "$1" in
    -y|--yes)    YES=1 ;;
    -u|--user)   shift; MYSQL_ARGS="$MYSQL_ARGS -u $1" ;;
    -p|--password) ASK_PW=1 ;;
    --host)      shift; MYSQL_ARGS="$MYSQL_ARGS --host=$1" ;;
    --port)      shift; MYSQL_ARGS="$MYSQL_ARGS --port=$1" ;;
    -*)          echo "모르는 옵션: $1" >&2; exit 2 ;;
    *)           DB="$1" ;;
  esac
  shift
done

if [ -z "$DB" ]; then
  echo "사용법: sh sql/apply.sh <db이름> [-y] [-u <user>] [-p] [--host <h>] [--port <n>]" >&2
  exit 2
fi

# ---------------------------------------------------------------------
# 비밀번호
#
# -p 를 그대로 넘기면 mysql/mysqldump 가 **부를 때마다** 묻는다. apply.sh 는
# 파일마다 한 번씩 부르므로 열 번 넘게 물어보게 된다.
#
# 한 번만 받아서 임시 설정 파일에 넣고 --defaults-extra-file 로 넘긴다.
# 명령줄에 비밀번호를 적으면(-p비번) ps 에 그대로 보이므로 그 방법은 쓰지 않는다.
# 파일은 600 으로 만들고 끝날 때 지운다 (중간에 죽어도 trap 이 지운다).
#
# --defaults-extra-file 은 **첫 번째 인자**여야 하므로 앞에 붙인다.
# ---------------------------------------------------------------------
CNF=""
cleanup() { [ -n "$CNF" ] && rm -f "$CNF"; }
trap cleanup EXIT INT TERM HUP

if [ "$ASK_PW" -eq 1 ]; then
  printf 'Enter password: ' >&2
  # tty 가 아니면 stty 가 실패하고, read 는 EOF 에서 1 을 돌려준다.
  # set -e 가 둘 다 잡아 스크립트를 죽이므로 받아 넘긴다
  # (2>/dev/null 은 메시지만 가릴 뿐 종료코드는 그대로다).
  stty -echo 2>/dev/null || true
  read PW || PW=""
  stty echo 2>/dev/null || true
  echo >&2
  CNF=$(mktemp) || { echo "임시 파일을 못 만들었다." >&2; exit 1; }
  chmod 600 "$CNF"
  printf '[client]\npassword=%s\n' "$PW" > "$CNF"
  PW=""
  MYSQL_ARGS="--defaults-extra-file=$CNF $MYSQL_ARGS"
fi

# ---------------------------------------------------------------------
# 적용 순서. 번호순이되 003·004 는 뺀다 (위 1번 사유).
#
# 이미 001 로 설치해 돌아가던 환경을 올리는 경우라면 — 그런 환경은 지금
# 로컬뿐이다 — 003·004 가 필요할 수 있다. 그때는 손으로 하나씩 돌려라.
# 이 스크립트는 "아무것도 없는 DB 에 처음 올리는" 경로만 책임진다.
# ---------------------------------------------------------------------
FILES="
001_schema.sql
002_seed_domain.sql
005_migration_domain_category.sql
006_migration_category_score.sql
007_migration_notify_outbox.sql
008_migration_workload_source.sql
009_migration_metric_comment.sql
010_migration_rnd.sql
011_migration_rnd_cap.sql
012_migration_rnd_domain.sql
013_migration_domain_group.sql
014_migration_project_track.sql
015_migration_integration.sql
016_migration_analysis.sql
017_migration_api_budget.sql
"
SKIP="003_migration_soft_delete.sql 004_migration_eval_exclusion.sql"

echo "데이터베이스 : $DB"
echo "건너뜀       : $SKIP"
echo "              (001_schema.sql 에 같은 컬럼이 이미 있다)"
echo "적용할 파일  :"
for f in $FILES; do
  [ -f "$DIR/$f" ] || { echo "  파일이 없다: $DIR/$f" >&2; exit 1; }
  echo "  $f"
done
echo

if [ "$YES" -ne 1 ]; then
  printf "이대로 적용할까? 적용 전에 dump.sh 로 백업을 떴는지 확인하라. [y/N] "
  read answer
  case "$answer" in
    y|Y) ;;
    *) echo "그만둔다."; exit 1 ;;
  esac
fi

for f in $FILES; do
  printf '%-40s' "$f"
  # shellcheck disable=SC2086
  mysql $MYSQL_ARGS --default-character-set=utf8mb4 "$DB" < "$DIR/$f"
  echo "ok"
done

echo
echo "끝났다. 다음을 확인하라 —"
echo "  1) 표 개수   : mysql $DB -e \"SHOW TABLES LIKE 'bs\_%'\""
echo "  2) 한글 주석 : mysql $DB -e \"SHOW FULL COLUMNS FROM bs_project\" | head"
echo "     주석이 ???? 로 보이면 charset 을 놓친 것이다. 다시 떠서 다시 넣어라."
echo "  3) 설정값    : mysql $DB -e \"SELECT * FROM bs_setting\"  (4행이어야 한다)"

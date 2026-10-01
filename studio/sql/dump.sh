#!/bin/sh
# =====================================================================
# BlueStudio 배포 전 백업 — 되돌리기 수단
#
#   sh sql/dump.sh <db이름> [-o <저장폴더>] [-u <user>] [-p] [--host <h>] [--port <n>]
#
# 되돌리기 스크립트는 010·011·012 에만 있다. 001~009 에는 없다.
# 그 구간을 되돌리는 방법은 "적용 전에 떠 둔 덤프를 되돌린다" 하나뿐이다.
# 그래서 apply.sh 를 돌리기 전에 이 스크립트를 반드시 먼저 돌려야 한다.
#
# 두 벌을 뜬다 —
#
#   <db>_full_<시각>.sql.gz    DB 전체. 최후의 보루.
#   <db>_bs_<시각>.sql.gz      bs_* 표만. 실제로 쓸 쪽.
#
# 왜 두 벌인가: 운영 iworks DB 에는 다른 모듈의 표도 함께 들어 있다.
# 전체를 되돌리면 그 사이 쌓인 다른 모듈의 데이터까지 같이 날아간다.
# BlueStudio 만 되돌릴 때는 bs_ 쪽을 써라.
#
# 되돌리는 법 (BlueStudio 만):
#   mysql <db> -e "$(sh sql/dump.sh --drop-sql <db>)"      ← bs_* 전부 DROP
#   gunzip -c <db>_bs_<시각>.sql.gz | mysql --default-character-set=utf8mb4 <db>
#
# 되돌리는 법 (전체):
#   gunzip -c <db>_full_<시각>.sql.gz | mysql --default-character-set=utf8mb4 <db>
# =====================================================================

set -e

DB=""
OUT="."
MYSQL_ARGS=""
ASK_PW=0
DROP_ONLY=0

while [ $# -gt 0 ]; do
  case "$1" in
    -o|--out)    shift; OUT="$1" ;;
    -u|--user)   shift; MYSQL_ARGS="$MYSQL_ARGS -u $1" ;;
    -p|--password) ASK_PW=1 ;;
    --host)      shift; MYSQL_ARGS="$MYSQL_ARGS --host=$1" ;;
    --port)      shift; MYSQL_ARGS="$MYSQL_ARGS --port=$1" ;;
    --drop-sql)  DROP_ONLY=1 ;;
    -*)          echo "모르는 옵션: $1" >&2; exit 2 ;;
    *)           DB="$1" ;;
  esac
  shift
done

if [ -z "$DB" ]; then
  echo "사용법: sh sql/dump.sh <db이름> [-o <저장폴더>] [-u <user>] [-p]" >&2
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

# bs_ 로 시작하는 표 이름을 DB 에서 직접 읽는다.
# 목록을 손으로 적어 두면 표가 늘 때마다 백업에서 조용히 빠진다.
# tr 로 CR 을 떼는 것은 윈도우 mysql.exe 가 CRLF 로 뱉기 때문이다.
# 떼지 않으면 mysqldump 가 "Couldn't find table" 로 죽는다.
# shellcheck disable=SC2086
TABLES=$(mysql $MYSQL_ARGS -N -B "$DB" \
  -e "SELECT table_name FROM information_schema.tables
       WHERE table_schema = DATABASE() AND table_name LIKE 'bs\_%'
       ORDER BY table_name" | tr -d '\r')

# bs_ 표가 하나도 없는 것은 **처음 올리는 경우에 정상이다.**
# 그래도 전체 덤프는 떠야 한다 — 운영 DB 에는 다른 모듈의 표가 들어 있고,
# 적용이 잘못돼 되돌릴 일이 생기면 그쪽이 유일한 수단이다.
FIRST_RUN=0
if [ -z "$TABLES" ]; then
  FIRST_RUN=1
  echo "bs_ 로 시작하는 표가 없다 — BlueStudio 를 이 DB 에 처음 올리는 것으로 본다."
  echo "  DB 이름이 틀린 것은 아닌지 한 번만 더 확인하라: $DB"
  echo "  전체 덤프만 뜨고 bs_* 덤프는 건너뛴다."
  echo
fi

# --drop-sql: 되돌릴 때 쓸 DROP 문만 뽑아 준다. 덤프는 뜨지 않는다.
if [ "$DROP_ONLY" -eq 1 ]; then
  if [ "$FIRST_RUN" -eq 1 ]; then
    echo "-- 지울 bs_ 표가 없다." >&2
    exit 0
  fi
  echo "SET FOREIGN_KEY_CHECKS=0;"
  for t in $TABLES; do echo "DROP TABLE IF EXISTS \`$t\`;"; done
  echo "SET FOREIGN_KEY_CHECKS=1;"
  exit 0
fi

mkdir -p "$OUT"
TS=$(date +%Y%m%d_%H%M%S)
FULL="$OUT/${DB}_full_${TS}.sql.gz"
ONLY="$OUT/${DB}_bs_${TS}.sql.gz"

COMMON="--default-character-set=utf8mb4 --single-transaction --quick --routines --triggers"

# --set-gtid-purged 는 MySQL 전용이다. MariaDB 의 mysqldump 는 모르는 옵션으로
# 보고 그대로 죽는다. 있는지 물어보고 있을 때만 붙인다.
if mysqldump --help 2>/dev/null | grep -q -- '--set-gtid-purged'; then
  COMMON="$COMMON --set-gtid-purged=OFF"
fi

if [ "$FIRST_RUN" -eq 0 ]; then
  echo "bs_ 표 $(echo "$TABLES" | wc -l) 개를 받았다."
fi

printf '%-30s' "DB 전체"
# shellcheck disable=SC2086
mysqldump $MYSQL_ARGS $COMMON "$DB" | gzip > "$FULL"
echo "$FULL"

if [ "$FIRST_RUN" -eq 0 ]; then
  printf '%-30s' "bs_* 만"
  # shellcheck disable=SC2086
  mysqldump $MYSQL_ARGS $COMMON "$DB" $TABLES | gzip > "$ONLY"
  echo "$ONLY"
fi

# mysqldump 가 죽어도 파이프 뒤의 gzip 이 성공하면 셸은 0 을 받는다.
# set -e 가 못 잡는 자리라 크기로 직접 확인한다.
MADE="$FULL"
[ "$FIRST_RUN" -eq 0 ] && MADE="$FULL $ONLY"

# mysqldump 가 죽어도 파이프 뒤의 gzip 이 성공하면 셸은 0 을 받는다.
# set -e 가 못 잡는 자리다. 크기로 재면 **빈 DB 의 정상 덤프**(470바이트쯤)를
# 실패로 몰기 때문에, mysqldump 가 마지막에 찍는 완료 표시를 본다.
for f in $MADE; do
  if ! gzip -t "$f" 2>/dev/null; then
    echo "gzip 이 깨졌다: $f" >&2
    exit 1
  fi
  if ! gunzip -c "$f" | tail -5 | grep -q 'Dump completed'; then
    echo "덤프가 끝까지 가지 못했다: $f" >&2
    echo "mysqldump 가 중간에 죽었다는 뜻이다. 이 상태로 배포하지 마라." >&2
    echo "마지막 줄:" >&2
    gunzip -c "$f" | tail -3 >&2
    exit 1
  fi
done

echo
# shellcheck disable=SC2086
ls -l $MADE
echo "배포하는 서버 밖으로 한 벌 더 복사해 두라."

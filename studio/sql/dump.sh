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
DROP_ONLY=0

while [ $# -gt 0 ]; do
  case "$1" in
    -o|--out)    shift; OUT="$1" ;;
    -u|--user)   shift; MYSQL_ARGS="$MYSQL_ARGS -u $1" ;;
    -p|--password) MYSQL_ARGS="$MYSQL_ARGS -p" ;;
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

# bs_ 로 시작하는 표 이름을 DB 에서 직접 읽는다.
# 목록을 손으로 적어 두면 표가 늘 때마다 백업에서 조용히 빠진다.
# tr 로 CR 을 떼는 것은 윈도우 mysql.exe 가 CRLF 로 뱉기 때문이다.
# 떼지 않으면 mysqldump 가 "Couldn't find table" 로 죽는다.
# shellcheck disable=SC2086
TABLES=$(mysql $MYSQL_ARGS -N -B "$DB" \
  -e "SELECT table_name FROM information_schema.tables
       WHERE table_schema = DATABASE() AND table_name LIKE 'bs\_%'
       ORDER BY table_name" | tr -d '\r')

if [ -z "$TABLES" ]; then
  echo "bs_ 로 시작하는 표가 하나도 없다. DB 이름이 맞는지 보라: $DB" >&2
  exit 1
fi

# --drop-sql: 되돌릴 때 쓸 DROP 문만 뽑아 준다. 덤프는 뜨지 않는다.
if [ "$DROP_ONLY" -eq 1 ]; then
  echo "SET FOREIGN_KEY_CHECKS=0;"
  for t in $TABLES; do echo "DROP TABLE IF EXISTS \`$t\`;"; done
  echo "SET FOREIGN_KEY_CHECKS=1;"
  exit 0
fi

mkdir -p "$OUT"
TS=$(date +%Y%m%d_%H%M%S)
FULL="$OUT/${DB}_full_${TS}.sql.gz"
ONLY="$OUT/${DB}_bs_${TS}.sql.gz"

COMMON="--default-character-set=utf8mb4 --single-transaction --quick --routines --triggers --set-gtid-purged=OFF"

echo "표 $(echo "$TABLES" | wc -l) 개를 받았다."
printf '%-30s' "DB 전체"
# shellcheck disable=SC2086
mysqldump $MYSQL_ARGS $COMMON "$DB" | gzip > "$FULL"
echo "$FULL"

printf '%-30s' "bs_* 만"
# shellcheck disable=SC2086
mysqldump $MYSQL_ARGS $COMMON "$DB" $TABLES | gzip > "$ONLY"
echo "$ONLY"

# mysqldump 가 죽어도 파이프 뒤의 gzip 이 성공하면 셸은 0 을 받는다.
# set -e 가 못 잡는 자리라 크기로 직접 확인한다.
for f in "$FULL" "$ONLY"; do
  sz=$(wc -c < "$f")
  if [ "$sz" -lt 1024 ]; then
    echo "덤프가 비었다 ($sz 바이트): $f" >&2
    echo "mysqldump 가 실패했다는 뜻이다. 이 상태로 배포하지 마라." >&2
    exit 1
  fi
done

echo
ls -l "$FULL" "$ONLY"
echo "두 파일을 배포하는 서버 밖으로 한 벌 더 복사해 두라."

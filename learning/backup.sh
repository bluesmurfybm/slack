#!/bin/sh
set -e
DB="${1:-${DB_PATH:-$(cd "$(dirname "$0")" && pwd)/var/learning.db}}"
KEEP=7

if [ ! -s "$DB" ]; then
    echo "백업할 DB 가 없습니다: $DB" >&2
    exit 1
fi

BAK="$DB.bak-$(date +%Y%m%d-%H%M%S)"
cp "$DB" "$BAK"
ls -1t "$DB".bak-* | tail -n +$((KEEP + 1)) | xargs -r rm
echo "$BAK"

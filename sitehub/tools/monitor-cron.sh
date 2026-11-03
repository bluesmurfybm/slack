#!/bin/sh
# SiteHub BLUMO 자동 점검 — cron 에 5분 간격으로 등록한다 (리눅스 운영 서버용, 윈도우는 monitor-task.bat).
#   sudo -u <웹서버 계정> sh sitehub/tools/monitor-cron.sh [php 경로]
# 웹서버 계정(www-data/apache/nginx)으로 등록해야 한다 — root 로 돌리면 var/sitehub 의 잠금·로그 파일이
# root 소유가 되어 화면의 '전체 지금 점검'이 막힌다. 여러 번 실행해도 한 줄만 남는다.
set -e
DIR=$(cd "$(dirname "$0")/.." && pwd)
PHP=${1:-$(command -v php || true)}
TAG='# sitehub-blumo'

if [ -z "$PHP" ] || [ ! -x "$PHP" ]; then
  echo "[BLUMO] PHP 를 찾을 수 없습니다. sh monitor-cron.sh /usr/bin/php8.2 처럼 경로를 주세요."
  exit 1
fi
if [ "$(id -u)" = "0" ]; then
  echo "[BLUMO] root 로 등록하려 합니다. 웹서버 계정으로 다시 실행하세요: sudo -u www-data sh $0 $PHP"
  exit 1
fi

# CLI PHP 는 웹(php-fpm/mod_php)과 다른 php.ini 를 읽어 pdo_mysql·curl 이 빠져 있는 경우가 있다
echo "[BLUMO] 진단: $PHP $DIR/monitor_run.php --doctor"
if ! "$PHP" "$DIR/monitor_run.php" --doctor; then
  echo "[BLUMO] 진단 실패 — 위 오류를 먼저 고치세요. 필요한 확장: pdo_mysql curl openssl mbstring"
  "$PHP" --ini | head -2
  exit 1
fi

LINE="*/5 * * * * $PHP $DIR/monitor_run.php >/dev/null 2>&1 $TAG"
( crontab -l 2>/dev/null | grep -v "$TAG" || true; echo "$LINE" ) | crontab -

echo
echo "[BLUMO] $(id -un) 계정의 crontab 에 등록했습니다:"
crontab -l | grep "$TAG"
echo "        오류 기록: $(cd "$DIR/.." && pwd)/var/sitehub/monitor_run.log"
echo "        해제: crontab -l | grep -v '$TAG' | crontab -"

#!/bin/bash
# ============================================================================
#  EnvLog 사무실 중계 (리눅스) — office_relay.bat 의 리눅스판.
#  대학 테스트서버는 블루소프트 사무실 IP 에서만 접속을 받으므로 SSH 엔진(envlogd)을 사무실 리눅스에서 돌리고,
#  포털 서버가 그 엔진을 역방향 SSH 터널로 쓴다.
#
#    브라우저 → 포털 api.php → 127.0.0.1:8766 ──(역방향 터널 ssh -R)──▶ 이 서버의 envlogd → 대학 테스트서버
#
#  준비(처음 한 번):
#    1) 포털 서버 키를 OpenSSH 형식으로: gcp_key.ppk 는 PuTTY 형식이라 그대로는 못 쓴다
#         sudo apt install putty-tools   (또는 dnf install putty)
#         puttygen gcp_key.ppk -O private-openssh -o ~/.ssh/gcp_key && chmod 600 ~/.ssh/gcp_key
#    2) 호스트키 저장:  ssh -i ~/.ssh/gcp_key amitoa@35.184.155.46 exit   (yes 입력)
#    3) 포털 서버 config.php 에 'envlog_autostart' => false
#  실행:  ./office_relay.sh            (앞에서 돌며 로그를 찍는다. systemd 유닛은 README 참고)
#  환경변수로 바꿀 수 있다: PORTAL_HOST PORTAL_USER PORTAL_KEY PORT
# ============================================================================
set -u
cd "$(dirname "$(readlink -f "$0")")"
PORTAL_HOST=${PORTAL_HOST:-35.184.155.46}
PORTAL_USER=${PORTAL_USER:-amitoa}
PORTAL_KEY=${PORTAL_KEY:-$HOME/.ssh/gcp_key}
PORT=${PORT:-8766}

[ -f "$PORTAL_KEY" ] || { echo "[알림] 키 파일이 없습니다: $PORTAL_KEY (머리말의 준비 1 참고)"; exit 1; }
command -v python3 >/dev/null || { echo "[알림] python3 가 없습니다"; exit 1; }
if [ ! -x venv/bin/python ]; then
  echo "[준비] 파이썬 가상환경을 만들고 패키지를 설치합니다 (처음 한 번)..."
  python3 -m venv venv && venv/bin/pip install -q -r requirements.txt || { echo "[오류] 준비 실패"; exit 1; }
fi
mkdir -p var

cleanup() { [ -n "${ENGINE_PID:-}" ] && kill "$ENGINE_PID" 2>/dev/null; exit 0; }
trap cleanup INT TERM

# ① 엔진 — 유휴 종료 끔(중계 서버는 계속 떠 있어야 한다). 출력은 var/relay.log
ENVLOG_EXIT_IDLE=0 ENVLOG_PORT=$PORT venv/bin/python envlogd.py --port "$PORT" >> var/relay.log 2>&1 &
ENGINE_PID=$!
echo "[envlog-relay] 엔진 pid $ENGINE_PID (127.0.0.1:$PORT, 로그 var/relay.log)"

# ② 역방향 터널 — 포워딩이 거부되거나 끊기면 ssh 가 바로 끝나고 10초 뒤 다시 건다
while true; do
  echo "[envlog-relay] $(date '+%F %T') 터널 연결: $PORTAL_USER@$PORTAL_HOST 의 127.0.0.1:$PORT ← 이 서버"
  ssh -N -i "$PORTAL_KEY" \
      -o ExitOnForwardFailure=yes -o ServerAliveInterval=30 -o ServerAliveCountMax=3 \
      -o StrictHostKeyChecking=accept-new -o BatchMode=yes \
      -R "127.0.0.1:$PORT:127.0.0.1:$PORT" "$PORTAL_USER@$PORTAL_HOST"
  echo "[envlog-relay] $(date '+%F %T') 터널이 끊겼습니다(rc=$?). 10초 뒤 다시 연결합니다."
  kill -0 "$ENGINE_PID" 2>/dev/null || { echo "[envlog-relay] 엔진이 죽어 있어 다시 띄웁니다"; ENVLOG_EXIT_IDLE=0 venv/bin/python envlogd.py --port "$PORT" >> var/relay.log 2>&1 & ENGINE_PID=$!; }
  sleep 10
done

#!/bin/sh
# 포털 auth.php 의 sso_secret() 과 같은 동작 — 시크릿 파일이 없으면 만든다.
set -e
mkdir -p "$(dirname "$SSO_SECRET_PATH")"
if [ ! -s "$SSO_SECRET_PATH" ]; then
    python -c "import secrets,os; open(os.environ['SSO_SECRET_PATH'],'w').write(secrets.token_hex(32))"
    echo "[entrypoint] sso_secret.key 생성"
fi
exec "$@"

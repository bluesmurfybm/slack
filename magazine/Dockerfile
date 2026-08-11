FROM python:3.12-slim

WORKDIR /app

COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

COPY . .

# 런타임 산출물(DB·시크릿·업로드)은 전부 /app/var 볼륨에 둔다
ENV DB_PATH=/app/var/magazine.db \
    SSO_SECRET_PATH=/app/var/sso_secret.key \
    UPLOAD_DIR=/app/var/uploads

EXPOSE 8001
ENTRYPOINT ["/app/entrypoint.sh"]
CMD ["uvicorn", "app:app", "--host", "0.0.0.0", "--port", "8001"]

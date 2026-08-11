FROM python:3.12-slim

WORKDIR /app

COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

COPY . .

# 데이터와 시크릿은 볼륨에 둔다
ENV DB_PATH=/app/data/magazine.db \
    SSO_SECRET_PATH=/app/data/sso_secret.key \
    UPLOAD_DIR=/app/data/uploads

EXPOSE 8001
ENTRYPOINT ["/app/entrypoint.sh"]
CMD ["uvicorn", "app:app", "--host", "0.0.0.0", "--port", "8001"]

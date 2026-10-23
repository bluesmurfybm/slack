@echo off
rem Gmail 실시간 감시 데몬을 백그라운드(최소화 창)로 실행
rem 사용: start_gmail_watch.bat 포털로그인이메일(또는 Gmail 주소)   (그 사람이 마이페이지에 등록한 Gmail 을 감시)
if "%~1"=="" (
  echo usage: start_gmail_watch.bat ^<portal-login-email or gmail-address^>
  exit /b 1
)
start "gmail_watch" /min "c:\wamp64\bin\php\php8.1.0\php.exe" "%~dp0gmail_watch.php" --account=%~1

@echo off
rem Slack 리스트 감시 데몬을 백그라운드(최소화 창)로 실행
rem 사용: start_slack_watch.bat 포털로그인이메일   (그 사람이 마이페이지에 저장한 슬랙 토큰을 씀)
if "%~1"=="" (
  echo usage: start_slack_watch.bat ^<portal-login-email^>
  exit /b 1
)
start "slack_watch" /min "c:\wamp64\bin\php\php8.1.0\php.exe" "%~dp0slack_watch.php" --account=%~1

@echo off
rem Slack 리스트 감시 데몬을 백그라운드(최소화 창)로 실행
start "slack_watch" /min "c:\wamp64\bin\php\php8.1.0\php.exe" "%~dp0slack_watch.php"

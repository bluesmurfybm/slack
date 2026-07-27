@echo off
rem Gmail 실시간 감시 데몬을 백그라운드(최소화 창)로 실행
start "gmail_watch" /min "c:\wamp64\bin\php\php8.1.0\php.exe" "%~dp0gmail_watch.php"

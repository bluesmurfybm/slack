@echo off
chcp 65001 >nul
rem blue-iWorks 포털 전체를 이 PC 에서 시험으로 띄운다 (MySQL 없이 SQLite, 데이터는 var\localdb)
setlocal
set PHP=D:\php82\php.exe
if not exist "%PHP%" (
  echo [알림] %PHP% 가 없습니다. PHP 8.2 를 D:\php82 에 풀어 주세요.
  pause
  exit /b 1
)
cd /d "%~dp0"
"%PHP%" dev\local_setup.php
if errorlevel 1 (
  pause
  exit /b 1
)
echo ========================================================
echo   blue-iWorks 로컬 시험 실행 중 - http://127.0.0.1:8780/
echo   로그인: 회사 이메일 + 처음 비밀번호 blue$123
echo   이 창을 닫으면 꺼집니다.
echo ========================================================
start "" http://127.0.0.1:8780/
"%PHP%" -S 127.0.0.1:8780 -t . dev\local_router.php
pause

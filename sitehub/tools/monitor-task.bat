@echo off
chcp 65001 >nul
rem SiteHub BLUMO 자동 점검 — 작업 스케줄러에 5분 간격으로 등록한다 (관리자 권한 필요 없음).
rem 예전 BLUMO(004_blumo) 의 "BLUMO" 작업이 남아 있으면 같은 사이트를 두 번 점검하고 알림도 두 번 가므로 지운다.
cd /d "%~dp0.."
set PHPEXE=
for /f "delims=" %%P in ('where php 2^>nul') do if not defined PHPEXE set "PHPEXE=%%P"
if not defined PHPEXE if exist "C:\wamp64\bin\php" for /f "delims=" %%D in ('dir /b /ad /o-n "C:\wamp64\bin\php\php8*" 2^>nul') do if not defined PHPEXE if exist "C:\wamp64\bin\php\%%D\php.exe" set "PHPEXE=C:\wamp64\bin\php\%%D\php.exe"
if not defined PHPEXE (
  echo [BLUMO] PHP 를 찾을 수 없습니다. PHP 설치 폴더를 PATH 에 추가하거나 이 파일의 PHPEXE 를 직접 적어 주세요.
  pause
  exit /b 1
)
rem 콘솔 창이 5분마다 뜨지 않도록 php-win.exe 가 있으면 그걸 쓴다
set "PHPW=%PHPEXE:php.exe=php-win.exe%"
if not exist "%PHPW%" set "PHPW=%PHPEXE%"
schtasks /Query /TN "BLUMO" >nul 2>&1 && (
  schtasks /Delete /TN "BLUMO" /F >nul 2>&1 && echo [BLUMO] 예전 BLUMO 작업을 지웠습니다.
)
schtasks /Create /TN "SiteHub-BLUMO" /SC MINUTE /MO 5 /TR "\"%PHPW%\" \"%CD%\monitor_run.php\"" /F
echo.
if errorlevel 1 (
  echo [BLUMO] 등록 실패. 관리자 권한으로 만든 같은 이름의 작업이 있으면
  echo         관리자 명령창에서 schtasks /Delete /TN SiteHub-BLUMO /F 후 다시 실행하세요.
) else (
  echo [BLUMO] 5분마다 자동 점검하도록 등록했습니다 — PHP: %PHPW%
  echo         상태는 SiteHub ^> 감시 ^> 설정 에서 봅니다. 해제: schtasks /Delete /TN SiteHub-BLUMO /F
)
echo.
pause

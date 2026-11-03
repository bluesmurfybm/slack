@echo off
chcp 65001 >nul
rem SiteHub BLUMO 자동 점검 — 작업 스케줄러에 5분 간격으로 등록한다.
rem 관리자 명령창에서 실행하면 SYSTEM 계정으로 등록해 로그오프 상태에서도 돈다(서버는 이쪽으로).
rem 일반 권한이면 현재 사용자로 등록되어 그 사용자가 로그인해 있을 때만 돈다.
rem PHP 경로를 직접 주려면: monitor-task.bat "D:\wamp64\bin\php\php8.3.28\php.exe"
rem 예전 BLUMO(004_blumo) 의 "BLUMO" 작업이 남아 있으면 같은 사이트를 두 번 점검하고 알림도 두 번 가므로 지운다.
cd /d "%~dp0.."
set "PHPEXE=%~1"
if defined PHPEXE if not exist "%PHPEXE%" (
  echo [BLUMO] 지정한 PHP 가 없습니다: %PHPEXE%
  pause
  exit /b 1
)
if not defined PHPEXE for /f "delims=" %%P in ('where php 2^>nul') do if not defined PHPEXE set "PHPEXE=%%P"
for %%R in (C D E F) do if not defined PHPEXE if exist "%%R:\wamp64\bin\php" for /f "delims=" %%D in ('dir /b /ad /o-n "%%R:\wamp64\bin\php\php8*" 2^>nul') do if not defined PHPEXE if exist "%%R:\wamp64\bin\php\%%D\php.exe" set "PHPEXE=%%R:\wamp64\bin\php\%%D\php.exe"
if not defined PHPEXE (
  echo [BLUMO] PHP 를 찾을 수 없습니다. monitor-task.bat "php.exe 전체 경로" 로 다시 실행하세요.
  pause
  exit /b 1
)
rem 등록 전에 CLI PHP 로 한 번 진단한다 — CLI 는 Apache 와 다른 php.ini 를 읽어 pdo_mysql·curl 이 꺼져 있는 경우가 많다
echo [BLUMO] 진단: "%PHPEXE%" monitor_run.php --doctor
"%PHPEXE%" monitor_run.php --doctor
if errorlevel 1 (
  echo.
  echo [BLUMO] 진단 실패 — 위 오류를 먼저 고치세요. 이 PHP 의 php.ini 에서 extension=pdo_mysql, curl, openssl, mbstring 을 켜야 합니다.
  echo         php.ini 위치: & "%PHPEXE%" --ini
  pause
  exit /b 1
)
echo.
rem 콘솔 창이 5분마다 뜨지 않도록 php-win.exe 가 있으면 그걸 쓴다
set "PHPW=%PHPEXE:php.exe=php-win.exe%"
if not exist "%PHPW%" set "PHPW=%PHPEXE%"
set "RUNAS="
net session >nul 2>&1 && set "RUNAS=/RU SYSTEM /RL HIGHEST"
schtasks /Query /TN "BLUMO" >nul 2>&1 && (
  schtasks /Delete /TN "BLUMO" /F >nul 2>&1 && echo [BLUMO] 예전 BLUMO 작업을 지웠습니다.
)
schtasks /Create /TN "SiteHub-BLUMO" /SC MINUTE /MO 5 /TR "\"%PHPW%\" \"%CD%\monitor_run.php\"" %RUNAS% /F
if errorlevel 1 (
  echo.
  echo [BLUMO] 등록 실패. 관리자 권한으로 만든 같은 이름의 작업이 있으면
  echo         관리자 명령창에서 schtasks /Delete /TN SiteHub-BLUMO /F 후 다시 실행하세요.
) else (
  echo.
  echo [BLUMO] 5분마다 자동 점검하도록 등록했습니다 — PHP: %PHPW%
  if defined RUNAS (echo         SYSTEM 계정 — 로그오프 상태에서도 돕니다.) else (echo         주의: 현재 사용자로 등록 — 로그인해 있을 때만 돕니다. 서버는 관리자 명령창에서 다시 실행하세요.)
  echo         오류 기록: %CD%\..\var\sitehub\monitor_run.log
  echo         상태는 SiteHub ^> 감시 ^> 설정 에서 봅니다. 해제: schtasks /Delete /TN SiteHub-BLUMO /F
)
echo.
pause

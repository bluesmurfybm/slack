@echo off
REM =====================================================================
REM  BlueCart 로컬 서버 실행
REM  127.0.0.1 에만 바인딩하므로 다른 PC 에서는 보이지 않습니다.
REM
REM  사용법
REM    dev\serve.bat         쓸 수 있는 포트를 찾아 띄웁니다
REM    dev\serve.bat 9123    포트를 직접 지정합니다
REM
REM  이 파일은 CP949 로 저장되어 있습니다. UTF-8 로 다시 저장하지 마세요.
REM =====================================================================
setlocal enabledelayedexpansion
cd /d "%~dp0.."

REM --- 쓸 PHP 정하기 ----------------------------------------------------
set PHPEXE=php
if exist "dev\php-path.txt" (
  set /p PHPEXE=<"dev\php-path.txt"
  call :trimvar PHPEXE
)

"!PHPEXE!" -v >nul 2>&1
if errorlevel 1 goto :nophp

"!PHPEXE!" -r "exit(version_compare(PHP_VERSION,'8.0.0','>=') ? 0 : 1);"
if not errorlevel 1 goto :phpok

REM --- PHP 가 낮다. 지정 파일이 없으면 한 번 찾아본다 -------------------
call :getver
echo.
echo [알림] 지금 잡히는 PHP 는 !PHPVER! 입니다. 8.0 이상이 필요합니다.
echo        경로: !PHPEXE!
echo.
if exist "dev\php-path.txt" goto :phpfail

echo        PC 에 설치된 PHP 8 을 찾아보겠습니다.
echo.
call "dev\find-tools.bat" /auto
if not exist "dev\php-path.txt" goto :phpfail

set /p PHPEXE=<"dev\php-path.txt"
call :trimvar PHPEXE
"!PHPEXE!" -r "exit(version_compare(PHP_VERSION,'8.0.0','>=') ? 0 : 1);" 2>nul
if errorlevel 1 goto :phpfail
call :getver
echo.
echo [확인] PHP !PHPVER! 로 진행합니다.
echo        !PHPEXE!
goto :phpok

:nophp
echo [오류] PHP 를 실행할 수 없습니다: !PHPEXE!
echo        dev\find-tools.bat 을 먼저 실행하세요.
echo.
pause
exit /b 1

:phpfail
echo [오류] 쓸 수 있는 PHP 8 을 찾지 못했습니다.
if exist "dev\php-path.txt" (
  echo        dev\php-path.txt 가 가리키는 PHP 가 8 이 아닙니다. 경로를 고치세요.
) else (
  echo        PHP 8 설치 방법은 README 1.1 을 보세요.
)
echo.
pause
exit /b 1

:phpok
if not exist config\config.php (
  echo [오류] config\config.php 가 없습니다. 먼저 dev\setup.bat 을 실행하세요.
  echo.
  pause
  exit /b 1
)

REM --- 포트 정하기 ------------------------------------------------------
set PORT=%1
if not "%PORT%"=="" goto :haveport

"!PHPEXE!" dev\find-port.php >"%TEMP%\bc_port.txt" 2>nul
if errorlevel 1 goto :noport
set /p PORT=<"%TEMP%\bc_port.txt"
call :trimvar PORT
if "!PORT!"=="" goto :noport
goto :haveport

:noport
echo.
echo [오류] 쓸 수 있는 포트를 찾지 못했습니다.
echo.
"!PHPEXE!" dev\find-port.php --verbose
echo.
pause
exit /b 1

:haveport
REM --- 포털 안인지 확인 -------------------------------------------------
REM  포털(iworks) 저장소 안에 들어와 있으면 포털 루트를 문서 루트로 삼아야
REM  ../styles/topbar.css, ../api/logout.php 같은 경로가 열린다.
set INPORTAL=
if exist "..\auth.php" if exist "..\worksystems.php" if exist "..\config.php" set INPORTAL=1

echo.
if defined INPORTAL (
  for %%m in ("%CD%") do set MODNAME=%%~nxm
  echo  iworks 포털 모드
  echo  포털      http://127.0.0.1:!PORT!/
  echo  BlueCart  http://127.0.0.1:!PORT!/!MODNAME!/
  echo  포털 계정으로 로그인한 뒤 BlueCart 로 들어가세요.
) else (
  echo  단독 실행 모드
  echo  BlueCart  http://127.0.0.1:!PORT!/
  echo  개발용 로그인 화면이 먼저 뜹니다. 계정 변경은 /dev/logout
)
echo  종료하려면 Ctrl+C
echo.
if defined INPORTAL (
  "!PHPEXE!" -S 127.0.0.1:!PORT! -t .. dev\router.php
) else (
  "!PHPEXE!" -S 127.0.0.1:!PORT! -t . dev\router.php
)
if errorlevel 1 (
  echo.
  echo  서버가 뜨지 않았습니다. 다른 포트로 시도해 보세요.
  echo    dev\serve.bat 9123
  echo.
  echo  포트를 누가 쓰는지 확인:
  echo    netstat -ano ^| findstr :!PORT!
  echo  Windows 가 예약한 대역인지 확인:
  echo    netsh interface ipv4 show excludedportrange protocol=tcp
  echo.
  pause
)
endlocal
exit /b 0

REM ---------------------------------------------------------------------
REM  PHP 버전을 임시 파일로 받는다.
REM  for /f 안에서 따옴표 붙은 경로를 쓰면 cmd 가 바깥 따옴표를 떼어내
REM  명령이 망가진다. 그래서 파일로 주고받는다.
:getver
set PHPVER=
"!PHPEXE!" -r "echo PHP_VERSION;" >"%TEMP%\bc_ver.txt" 2>nul
if exist "%TEMP%\bc_ver.txt" set /p PHPVER=<"%TEMP%\bc_ver.txt"
if "!PHPVER!"=="" set PHPVER=(확인 불가)
goto :eof

REM ---------------------------------------------------------------------
REM  변수 끝의 공백과 따옴표를 제거한다.
REM  `echo 경로 > 파일` 로 만든 파일은 경로 끝에 공백이 붙는다.
:trimvar
call set "_T=%%%1%%"
for /l %%i in (1,1,8) do if "!_T:~-1!"==" " set "_T=!_T:~0,-1!"
set _T=!_T:"=!
set "%1=!_T!"
set "_T="
goto :eof

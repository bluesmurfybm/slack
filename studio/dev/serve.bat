@echo off
REM ==============================================================
REM  BlueAssign 로컬 개발 서버
REM  127.0.0.1 에만 바인딩합니다. 다른 PC 에서는 접속할 수 없습니다.
REM  운영 서버에서 실행하지 마세요.
REM ==============================================================
setlocal

set PORT=8099
if not "%~1"=="" set PORT=%~1

REM 포털 루트 = 이 파일의 두 단계 위 (assign\dev -> assign -> 포털)
set PORTAL=%~dp0..\..

REM php.exe 찾기: 이 폴더의 경로 파일 -> bluecart 의 것 -> PATH
set PHP=
if exist "%~dp0php-path.txt" set /p PHP=<"%~dp0php-path.txt"
if not defined PHP if exist "%PORTAL%\bluecart\dev\php-path.txt" set /p PHP=<"%PORTAL%\bluecart\dev\php-path.txt"
if not defined PHP set PHP=php

if not exist "%PORTAL%\config.php" (
  echo.
  echo  [준비 안 됨] 포털 config.php 가 없습니다.
  echo  먼저 실행하세요:
  echo      "%PHP%" assign\dev\setup_local.php
  echo.
  pause
  exit /b 1
)

echo.
echo  BlueAssign 개발 서버
echo  ----------------------------------------------------------
echo   주소    http://127.0.0.1:%PORT%/assign/
echo   포털    http://127.0.0.1:%PORT%/
echo.
echo   계정    kimhy@bluesoft.co.kr   (관리자 / OWNER_ADMINS)
echo           jian@bluesoft.co.kr    (일반 사용자)
echo   비번    blue$123  - 초기값입니다.
echo           한 번 로그인하면 포털이 변경을 요구하니, 바꾼 뒤에는
echo           바꾼 값으로 들어오세요.
echo.
echo   API 시험   "%PHP%" assign\dev\api_test.php
echo              (사람 계정과 무관한 batest-* 계정을 스스로 만들어 씁니다)
echo   수집기     python assign\collector\collect_slack.py --dry-run
echo   멈추기     Ctrl+C
echo  ----------------------------------------------------------
echo.

pushd "%PORTAL%"
"%PHP%" -S 127.0.0.1:%PORT% -t . studio\dev\router.php
popd
endlocal

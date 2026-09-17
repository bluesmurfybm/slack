@echo off
REM =====================================================================
REM  PC 에 설치된 PHP 와 MySQL 을 찾아 이 프로젝트가 쓸 것을 정합니다.
REM
REM  XAMPP(PHP7) 과 WampServer(PHP8) 처럼 여러 개가 깔려 있을 때,
REM  PATH 를 건드리지 않고 이 프로젝트만 PHP 8 을 쓰게 하려는 용도입니다.
REM
REM  결과는 dev\php-path.txt / dev\mysql-path.txt 에 저장됩니다.
REM =====================================================================
setlocal enabledelayedexpansion
cd /d "%~dp0.."

REM  /auto : 묻지 않고 찾은 것을 바로 저장한다. 다른 배치에서 부를 때 쓴다.
set AUTO=
if /i "%~1"=="/auto" set AUTO=1

echo.
echo === 설치된 PHP 찾기 ===
echo.

set FOUND8=
set COUNT=0
set SEENPHP=
set SEENSQL=

REM 흔한 설치 경로들을 훑는다
for %%D in (
  "C:\wamp64\bin\php"
  "C:\wamp\bin\php"
  "C:\laragon\bin\php"
) do (
  if exist %%~D (
    for /d %%P in ("%%~D\*") do call :checkphp "%%~P\php.exe"
  )
)

for %%P in (
  "C:\xampp\php\php.exe"
  "C:\php\php.exe"
  "C:\php8\php.exe"
  "C:\php83\php.exe"
  "C:\php82\php.exe"
  "C:\php81\php.exe"
  "C:\Program Files\php\php.exe"
) do call :checkphp %%P

REM PATH 에 있는 것도
for /f "delims=" %%P in ('where php 2^>nul') do call :checkphp "%%P"

echo.
if %COUNT%==0 (
  echo   PHP 를 하나도 찾지 못했습니다.
  echo   설치 경로가 특이하다면 dev\php-path.txt 에 직접 적어 주세요.
  goto :mysqlpart
)

if not defined FOUND8 (
  echo   PHP 8 이 없습니다. 찾은 것은 모두 7.x 이하입니다.
  echo   README 1.1 을 보고 PHP 8 을 설치하세요.
  goto :mysqlpart
)

echo === 선택 ===
echo   이 프로젝트는 다음 PHP 를 씁니다:
echo     !FOUND8!
echo.
if not defined AUTO (
  set /p YN=dev\php-path.txt 에 저장할까요? [Y/n]: 
  if /i "!YN!"=="n" goto :mysqlpart
)
> "dev\php-path.txt" echo !FOUND8!
echo   [저장] dev\php-path.txt

:mysqlpart
echo.
echo === 설치된 MySQL 클라이언트 찾기 ===
echo.

set FOUNDSQL=

for %%D in (
  "C:\wamp64\bin\mysql"
  "C:\wamp\bin\mysql"
  "C:\wamp64\bin\mariadb"
  "C:\laragon\bin\mysql"
) do (
  if exist %%~D (
    for /d %%P in ("%%~D\*") do call :checksql "%%~P\bin\mysql.exe"
  )
)

for %%P in (
  "C:\xampp\mysql\bin\mysql.exe"
  "C:\Program Files\MySQL\MySQL Server 8.0\bin\mysql.exe"
) do call :checksql %%P

for /f "delims=" %%P in ('where mysql 2^>nul') do call :checksql "%%P"

echo.
if not defined FOUNDSQL (
  echo   mysql 클라이언트를 찾지 못했습니다.
  echo   dev\mysql-path.txt 에 직접 적어 주세요.
  goto :done
)

echo === 선택 ===
echo   이 프로젝트는 다음 mysql 을 씁니다:
echo     !FOUNDSQL!
echo.
if not defined AUTO (
  set /p YN2=dev\mysql-path.txt 에 저장할까요? [Y/n]: 
  if /i "!YN2!"=="n" goto :done
)
> "dev\mysql-path.txt" echo !FOUNDSQL!
echo   [저장] dev\mysql-path.txt

:done
if defined AUTO (
  endlocal
  exit /b 0
)
echo.
echo === 다음 단계 ===
echo   dev\setup.bat 을 실행하세요.
echo.
endlocal
exit /b 0

REM ---------------------------------------------------------------------
:checkphp
if not exist %1 exit /b
set "P=%~1"
REM 같은 경로를 두 번 검사하지 않는다 (PATH 에도 걸리는 경우가 있음)
echo !SEENPHP! | find /i "[%P%]" >nul && exit /b
set "SEENPHP=!SEENPHP![%P%]"
"%P%" -r "echo PHP_VERSION;" >%TEMP%\bc_v.txt 2>%TEMP%\bc_e.txt
if errorlevel 1 (
  echo   [실행 불가] %P%
  set /p ERRMSG=<%TEMP%\bc_e.txt
  if defined ERRMSG echo                !ERRMSG!
  exit /b
)
set /p V=<%TEMP%\bc_v.txt
set /a COUNT+=1
"%P%" -r "exit(version_compare(PHP_VERSION,'8.0.0','>=') ? 0 : 1);"
if errorlevel 1 (
  echo   PHP !V!   %P%
) else (
  echo   PHP !V!   %P%   ^<-- 사용 가능
  if not defined FOUND8 set "FOUND8=%P%"
)
exit /b

REM ---------------------------------------------------------------------
:checksql
if not exist %1 exit /b
set "S=%~1"
echo !SEENSQL! | find /i "[%S%]" >nul && exit /b
set "SEENSQL=!SEENSQL![%S%]"
"%S%" --version >%TEMP%\bc_sv.txt 2>nul
if errorlevel 1 (
  echo   [실행 불가] %S%
  exit /b
)
set SV=
set /p SV=<%TEMP%\bc_sv.txt
if not defined SV exit /b
echo   !SV!
echo       %S%
if not defined FOUNDSQL set "FOUNDSQL=%S%"
exit /b

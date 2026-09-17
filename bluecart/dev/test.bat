@echo off
REM =====================================================================
REM  워크플로 자동 점검
REM  별도 DB(iworks_test)를 쓰므로 개발/운영 데이터와 config 를 건드리지 않습니다.
REM =====================================================================
setlocal enabledelayedexpansion
cd /d "%~dp0.."

set PHPEXE=php
if exist "dev\php-path.txt" (
  set /p PHPEXE=<"dev\php-path.txt"
  call :trimvar PHPEXE
)
set MYSQLEXE=mysql
if exist "dev\mysql-path.txt" (
  set /p MYSQLEXE=<"dev\mysql-path.txt"
  call :trimvar MYSQLEXE
)

"!PHPEXE!" -r "exit(version_compare(PHP_VERSION,'8.0.0','>=') ? 0 : 1);" 2>nul
if errorlevel 1 ( echo [오류] PHP 8.0 이상이 필요합니다. & pause & exit /b 1 )

echo  테스트용 DB 접속 정보를 입력하세요.
set /p DBHOST=MySQL 호스트 [127.0.0.1]: 
if "%DBHOST%"=="" set DBHOST=127.0.0.1
set /p DBPORT=포트 [3306]: 
if "%DBPORT%"=="" set DBPORT=3306
set /p DBUSER=사용자 [root]: 
if "%DBUSER%"=="" set DBUSER=root
set /p DBPASS=비밀번호 (없으면 엔터): 

if "%DBPASS%"=="" (
  set MYSQLCMD="!MYSQLEXE!" -h %DBHOST% -P %DBPORT% -u %DBUSER% --default-character-set=utf8mb4
) else (
  set MYSQLCMD="!MYSQLEXE!" -h %DBHOST% -P %DBPORT% -u %DBUSER% -p%DBPASS% --default-character-set=utf8mb4
)

echo [작업] 테스트 DB 초기화
!MYSQLCMD! -e "DROP DATABASE IF EXISTS iworks_test; CREATE DATABASE iworks_test DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
if errorlevel 1 ( echo [오류] DB 접속 실패 & pause & exit /b 1 )
!MYSQLCMD! iworks_test < sql\01_schema.sql
!MYSQLCMD! iworks_test < sql\02_seed.sql
!MYSQLCMD! iworks_test < tests\fixture.sql

set BCTEST_DB_HOST=%DBHOST%
set BCTEST_DB_PORT=%DBPORT%
set BCTEST_DB_USER=%DBUSER%
set BCTEST_DB_PASS=%DBPASS%
set BCTEST_DB_NAME=iworks_test

echo.
"!PHPEXE!" tests\workflow_test.php
echo.
pause
endlocal

goto :eof

REM ---------------------------------------------------------------------
REM  변수 끝의 공백과 따옴표를 제거한다. 경로에 따옴표는 쓰지 않는다.
REM  `echo 경로 > 파일` 로 만든 파일은 경로 끝에 공백이 붙는다.
:trimvar
call set "_T=%%%1%%"
for /l %%i in (1,1,8) do if "!_T:~-1!"==" " set "_T=!_T:~0,-1!"
set _T=!_T:"=!
set "%1=!_T!"
set "_T="
goto :eof

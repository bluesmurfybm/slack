@echo off
REM =====================================================================
REM  BlueCart 로컬 개발 환경 준비 (Windows)
REM
REM  두 가지 경우를 모두 다룹니다.
REM    - iworks 포털 저장소 안(<포털>\bluecart) : 포털 모듈로 설치
REM    - 단독 폴더                              : 자체 DB 로 설치
REM
REM  PATH 를 건드리고 싶지 않다면 경로 파일을 쓰세요.
REM    dev\php-path.txt    : php.exe 전체 경로 한 줄
REM    dev\mysql-path.txt  : mysql.exe 전체 경로 한 줄
REM  dev\find-tools.bat 이 이 파일들을 자동으로 만들어 줍니다.
REM
REM  이 파일은 CP949 로 저장되어 있습니다. UTF-8 로 다시 저장하지 마세요.
REM =====================================================================
setlocal enabledelayedexpansion
cd /d "%~dp0.."

echo.
echo === BlueCart 로컬 준비 ===
echo.

REM --- 1. 쓸 PHP 정하기 -------------------------------------------------
set PHPEXE=php
if exist "dev\php-path.txt" (
  set /p PHPEXE=<"dev\php-path.txt"
  call :trimvar PHPEXE
  echo [설정] dev\php-path.txt 의 PHP 를 씁니다: !PHPEXE!
)

"!PHPEXE!" -v >nul 2>&1
if errorlevel 1 goto :nophp

"!PHPEXE!" -r "exit(version_compare(PHP_VERSION,'8.0.0','>=') ? 0 : 1);"
if not errorlevel 1 goto :phpok

call :getver
echo [알림] PHP !PHPVER! 입니다. 8.0 이상이 필요합니다.
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
goto :phpok

:nophp
echo [오류] PHP 를 실행할 수 없습니다: !PHPEXE!
echo.
echo        방법 1^) dev\find-tools.bat 을 실행해 자동으로 찾게 하세요.
echo        방법 2^) dev\php-path.txt 에 php.exe 전체 경로를 한 줄로 적으세요.
echo                 예^) C:\wamp64\bin\php\php8.3.27\php.exe
goto :fail

:phpfail
echo [오류] 쓸 수 있는 PHP 8 을 찾지 못했습니다.
echo.
echo        이 프로그램은 PHP 8 문법을 씁니다. 7.x 에서는 실행되지 않습니다.
if exist "dev\php-path.txt" (
  echo        dev\php-path.txt 가 가리키는 PHP 가 8 이 아닙니다. 경로를 고치세요.
) else (
  echo        설치 방법은 README 1.1 을 보세요.
)
goto :fail

:phpok
call :getver
echo [확인] PHP !PHPVER!

REM --- 2. 확장 모듈 -----------------------------------------------------
"!PHPEXE!" -r "$n=['pdo_mysql','mbstring','curl','fileinfo','zlib']; $m=array_diff($n,get_loaded_extensions()); if($m){fwrite(STDERR,implode(', ',$m)); exit(1);}" 2>"%TEMP%\bc_ext.txt"
if not errorlevel 1 goto :extok
set MISSING=
set /p MISSING=<"%TEMP%\bc_ext.txt"
echo [오류] 확장 모듈 없음: !MISSING!
echo.
echo        php.ini 에서 해당 extension= 줄 앞의 세미콜론을 지우고 저장하세요.
"!PHPEXE!" -r "echo '       php.ini 위치: ' . (php_ini_loaded_file() ?: '(없음 - php.ini-development 를 php.ini 로 복사하세요)') . PHP_EOL;"
goto :fail

:extok
echo [확인] 확장 모듈 모두 있음

REM --- 3. iworks 포털 안인지 확인 ---------------------------------------
set INPORTAL=
if exist "..\auth.php" if exist "..\worksystems.php" if exist "..\config.php" set INPORTAL=1
if defined INPORTAL goto :portalsetup

REM =====================================================================
REM  단독 설치 경로
REM =====================================================================

REM --- 4. MySQL 클라이언트 ----------------------------------------------
set MYSQLEXE=mysql
if exist "dev\mysql-path.txt" (
  set /p MYSQLEXE=<"dev\mysql-path.txt"
  call :trimvar MYSQLEXE
  echo [설정] dev\mysql-path.txt 의 mysql 을 씁니다: !MYSQLEXE!
)

"!MYSQLEXE!" --version >nul 2>&1
if not errorlevel 1 goto :sqlok
echo [오류] mysql 클라이언트를 실행할 수 없습니다: !MYSQLEXE!
echo.
echo        dev\find-tools.bat 을 실행하거나,
echo        dev\mysql-path.txt 에 아래 중 있는 경로를 한 줄로 적으세요.
echo          XAMPP       C:\xampp\mysql\bin\mysql.exe
echo          WampServer  C:\wamp64\bin\mysql\mysql8.x.x\bin\mysql.exe
echo.
echo        찾기: dir C:\wamp64\bin\mysql /b
goto :fail

:sqlok
echo [확인] mysql 클라이언트

REM --- 5. 접속 정보 -----------------------------------------------------
echo.
echo  DB 접속 정보를 입력하세요. 원격 서버를 쓰셔도 됩니다.
set /p DBHOST=MySQL 호스트 [127.0.0.1]: 
if "%DBHOST%"=="" set DBHOST=127.0.0.1
set /p DBPORT=포트 [3306]: 
if "%DBPORT%"=="" set DBPORT=3306
set /p DBNAME=데이터베이스 이름 [bluecart_dev]: 
if "%DBNAME%"=="" set DBNAME=bluecart_dev
set /p DBUSER=사용자 [root]: 
if "%DBUSER%"=="" set DBUSER=root
set /p DBPASS=비밀번호 (없으면 엔터): 

if "%DBPASS%"=="" (
  set MYSQLCMD="!MYSQLEXE!" -h %DBHOST% -P %DBPORT% -u %DBUSER% --default-character-set=utf8mb4
) else (
  set MYSQLCMD="!MYSQLEXE!" -h %DBHOST% -P %DBPORT% -u %DBUSER% -p%DBPASS% --default-character-set=utf8mb4
)

echo.
echo [작업] 접속 확인
!MYSQLCMD! -e "SELECT VERSION() AS mysql_version;"
if errorlevel 1 (
  echo [오류] 접속 실패. 주소, 계정, 비밀번호, 방화벽을 확인하세요.
  echo        원격 서버라면 그 계정이 이 PC 에서 접속 가능한지도 봐야 합니다.
  goto :fail
)

REM --- 6. 스키마 --------------------------------------------------------
echo [작업] 데이터베이스 %DBNAME% 생성
!MYSQLCMD! -e "CREATE DATABASE IF NOT EXISTS %DBNAME% DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
if errorlevel 1 ( echo [오류] DB 생성 실패. CREATE 권한을 확인하세요. & goto :fail )

echo [작업] 스키마 생성
!MYSQLCMD! %DBNAME% < sql\01_schema.sql
if errorlevel 1 ( echo [오류] 스키마 생성 실패 & goto :fail )

echo [작업] 기본 데이터 입력
!MYSQLCMD! %DBNAME% < sql\02_seed.sql
if errorlevel 1 ( echo [오류] 기본 데이터 입력 실패 & goto :fail )

echo [작업] 개발용 샘플 데이터 입력
!MYSQLCMD! %DBNAME% < dev\seed_dev.sql
if errorlevel 1 ( echo [오류] 샘플 데이터 입력 실패 & goto :fail )

REM --- 7. 설정 파일 -----------------------------------------------------
if exist config\config.php (
  echo [건너뜀] config\config.php 가 이미 있습니다. 접속 정보를 직접 확인하세요.
) else (
  "!PHPEXE!" dev\make_config.php "%DBHOST%" "%DBPORT%" "%DBNAME%" "%DBUSER%" "%DBPASS%"
  if errorlevel 1 ( echo [오류] 설정 파일 생성 실패 & goto :fail )
)

REM --- 8. 첨부 저장소 ---------------------------------------------------
for %%d in ("..") do set PARENT=%%~fd
set UPDIR=!PARENT!\bluecart-data
if not exist "!UPDIR!" (
  mkdir "!UPDIR!"
  echo [작업] 첨부 저장소 생성: !UPDIR!
) else (
  echo [확인] 첨부 저장소 있음: !UPDIR!
)

REM --- 9. 최종 점검 -----------------------------------------------------
echo.
"!PHPEXE!" dev\check.php

echo.
echo === 준비 완료 ===
echo  dev\serve.bat 을 실행한 뒤 화면에 찍히는 주소로 접속하세요.
echo.
endlocal
exit /b 0

REM =====================================================================
REM  포털 모듈 설치 경로
REM  DB 접속 정보는 포털 config.php 에서 물려받으므로 묻지 않는다.
REM  스키마도 mysql 클라이언트 없이 PDO 로 적용한다.
REM =====================================================================
:portalsetup
echo [확인] iworks 포털 모듈로 설치합니다

if exist config\config.php (
  echo [건너뜀] config\config.php 가 이미 있습니다.
) else (
  "!PHPEXE!" dev\make_config.php --portal
  if errorlevel 1 ( echo [오류] 설정 파일 생성 실패 & goto :fail )
)

echo [작업] 스키마와 기본 데이터 적용
"!PHPEXE!" dev\apply_sql.php sql\01_schema.sql sql\02_seed.sql
if errorlevel 1 (
  echo [오류] 적용 실패. 포털 config.php 의 DB 접속 정보를 확인하세요.
  goto :fail
)

echo [알림] 개발용 샘플 데이터는 실행하지 않습니다.
echo        포털 DB 의 실제 요청을 지우기 때문입니다.
echo        구성원 명단은 포털의 portal_users 를 그대로 씁니다.

for %%d in ("..") do set PARENT=%%~fd
set UPDIR=!PARENT!\bluecart-data
if not exist "!UPDIR!" (
  mkdir "!UPDIR!"
  echo [작업] 첨부 저장소 생성: !UPDIR!
) else (
  echo [확인] 첨부 저장소 있음: !UPDIR!
)

echo.
"!PHPEXE!" dev\check.php

echo.
echo === 준비 완료 ===
echo  dev\serve.bat 을 실행하면 포털 전체가 뜹니다.
echo  포털 계정으로 로그인한 뒤 BlueCart 로 들어가세요.
echo.
echo  상단바 메뉴에 넣으려면 포털 worksystems.json 에 bluecart 항목을 추가하세요.
echo  README 의 "포털에 등록" 항목을 보세요.
echo.
endlocal
exit /b 0

:fail
echo.
echo === 중단되었습니다 ===
echo  문제를 고친 뒤 dev\setup.bat 을 다시 실행하세요.
echo  자세한 진단:  dev\check.php
echo.
pause
endlocal
exit /b 1

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
REM  변수 끝의 공백과 따옴표를 제거한다. 경로에 따옴표는 쓰지 않는다.
:trimvar
call set "_T=%%%1%%"
for /l %%i in (1,1,8) do if "!_T:~-1!"==" " set "_T=!_T:~0,-1!"
set _T=!_T:"=!
set "%1=!_T!"
set "_T="
goto :eof

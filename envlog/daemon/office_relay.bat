@echo off
chcp 65001 >nul
rem ============================================================================
rem  EnvLog 사무실 중계 — 대학 테스트서버는 블루소프트 사무실 IP 에서만 접속을 받으므로
rem  SSH 엔진(envlogd)을 사무실 PC 에서 돌리고, 포털 서버가 그 엔진을 역방향 SSH 터널로 쓴다.
rem
rem    브라우저 → 포털 api.php → 127.0.0.1:8766 ──(역방향 터널)──▶ 이 PC 의 envlogd → 대학 테스트서버
rem
rem  이 창 하나가 ① envlogd(127.0.0.1:8766, 유휴 종료 없음) ② plink 역방향 터널(끊기면 다시 연결) 을 돌린다.
rem  포털 서버 config.php 에는 'envlog_autostart' => false 를 둔다(서버에서 자기 데몬을 띄우지 않도록).
rem  서버 sshd 는 기본값(AllowTcpForwarding yes)이면 된다. 터널은 서버의 127.0.0.1 에만 묶인다.
rem ============================================================================
setlocal
set PORTAL_HOST=35.184.155.46
set PORTAL_USER=amitoa
set PORTAL_KEY=G:\01_Bluesoft\0000_iWorks\02_산출물\gcp_key.ppk
set PORT=8766
set PLINK=plink

cd /d "%~dp0"
where %PLINK% >nul 2>&1 || (
  echo [알림] plink 를 찾지 못했습니다. PuTTY 를 설치하거나 위 PLINK 변수에 plink.exe 경로를 적어 주세요.
  pause & exit /b 1
)
if not exist "%PORTAL_KEY%" (
  echo [알림] 키 파일이 없습니다: %PORTAL_KEY%
  pause & exit /b 1
)
if not exist venv\Scripts\python.exe (
  echo [준비] 파이썬 가상환경을 만들고 패키지를 설치합니다 ^(처음 한 번^)...
  python -m venv venv && venv\Scripts\python.exe -m pip install -q -r requirements.txt || (echo [오류] 준비 실패 & pause & exit /b 1)
)

title EnvLog 사무실 중계 (envlogd + 터널 → %PORTAL_HOST%)
echo ========================================================
echo   EnvLog 사무실 중계 실행 중 - 이 창을 닫으면 포털의 EnvLog 가 멈춥니다.
echo   엔진: 127.0.0.1:%PORT%   터널: %PORTAL_USER%@%PORTAL_HOST% 의 127.0.0.1:%PORT% ← 이 PC
echo ========================================================

rem ① 엔진 — 별도 창 없이 백그라운드. 유휴 종료 끔(중계 PC 는 계속 떠 있어야 한다)
set ENVLOG_EXIT_IDLE=0
set ENVLOG_PORT=%PORT%
start "EnvLog 엔진 (envlogd)" /min venv\Scripts\python.exe envlogd.py --port %PORT%

rem ② 역방향 터널 — 끊기면 10초 뒤 다시 연결
:loop
echo [%date% %time%] 터널 연결...
%PLINK% -batch -N -i "%PORTAL_KEY%" -R 127.0.0.1:%PORT%:127.0.0.1:%PORT% %PORTAL_USER%@%PORTAL_HOST%
echo [%date% %time%] 터널이 끊겼습니다. 10초 뒤 다시 연결합니다. (Ctrl+C 로 종료)
timeout /t 10 /nobreak >nul
goto loop

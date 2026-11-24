@echo off
REM csmspwdlog bot launcher (dev PC). Run the bot in ONE place only.
REM   start_bot.bat              -> starts the bot in a new minimized window (keep it open!)
REM   start_bot.bat --selftest   -> runs a check in this window
REM NOTE: keep this file ASCII + CRLF. cmd.exe mis-parses LF-only or UTF-8 Korean batch files.
setlocal
cd /d "%~dp0"
set PYTHONUTF8=1
set PYTHONIOENCODING=utf-8
if not exist "venv\Scripts\python.exe" (
  echo [csmspwdlog] venv not found. Run: python -m venv venv ^&^& venv\Scripts\python -m pip install -r requirements.txt
  exit /b 1
)
if "%~1"=="" (
  start "csmspwdlog_bot" /min cmd /k "venv\Scripts\python.exe bot.py"
  echo [csmspwdlog] bot started in a minimized window "csmspwdlog_bot". Closing that window stops the bot.
) else (
  "venv\Scripts\python.exe" bot.py %*
)
endlocal

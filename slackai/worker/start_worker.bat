@echo off
REM slackai worker launcher. Run as the logged-in user so the claude CLI reuses your OAuth login.
REM   start_worker.bat              -> starts --serve in a new minimized window (keep it open!)
REM   start_worker.bat --selftest   -> runs a check in this window
REM   start_worker.bat --once ...   -> any other run_worker.py args in this window
REM NOTE: keep this file ASCII + CRLF. cmd.exe mis-parses LF-only or UTF-8 Korean batch files.
setlocal
cd /d "%~dp0"
set PYTHONUTF8=1
set PYTHONIOENCODING=utf-8
set NO_COLOR=1
if not exist ".venv\Scripts\python.exe" (
  echo [slackai] .venv not found. Run: python -m venv .venv ^&^& .venv\Scripts\python -m pip install -r requirements.txt
  exit /b 1
)
if "%~1"=="" (
  start "slackai_worker" /min cmd /k ".venv\Scripts\python.exe run_worker.py --serve"
  echo [slackai] worker started in a minimized window "slackai_worker". Closing that window stops the worker.
) else (
  ".venv\Scripts\python.exe" run_worker.py %*
)
endlocal

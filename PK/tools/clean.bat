@echo off
setlocal

REM Run from tools folder
cd /d "%~dp0"

REM Prefer py launcher
where py >nul 2>nul
if %errorlevel%==0 (
  py -3 "%~dp0clean_gui.py"
  goto :eof
)

REM Fallback to python
where python >nul 2>nul
if %errorlevel%==0 (
  python "%~dp0clean_gui.py"
  goto :eof
)

echo.
echo ERROR: Python not found (need py or python in PATH)
echo Install Python 3.x from python.org and retry.
echo.
pause
endlocal

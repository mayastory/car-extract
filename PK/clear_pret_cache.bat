@echo off
setlocal

set "ROOT=%~dp0"
rem ROOT already ends with backslash

echo [PRET] Clearing generated cache folders...

rmdir /s /q "%ROOT%public\pret\maps" 2>nul
rmdir /s /q "%ROOT%public\pret\tilesets" 2>nul

mkdir "%ROOT%public\pret\maps" 2>nul
mkdir "%ROOT%public\pret\tilesets" 2>nul

echo Done. Now refresh browser with Ctrl+F5.
pause

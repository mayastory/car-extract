@echo off
setlocal
REM Clear pret cache (maps/tilesets) so occlusion + tileset regen happens cleanly.
set ROOT=%~dp0..
cd /d "%ROOT%"
echo [pret] clearing cache...
if exist "public\pret\maps" rmdir /s /q "public\pret\maps"
if exist "public\pret\tilesets" rmdir /s /q "public\pret\tilesets"
mkdir "public\pret\maps" >nul 2>nul
mkdir "public\pret\tilesets" >nul 2>nul
echo [pret] done. Refresh browser with Ctrl+F5.
endlocal

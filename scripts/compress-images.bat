@echo off
setlocal
cd /d "%~dp0\.."
python scripts\compress-images.py %*
pause

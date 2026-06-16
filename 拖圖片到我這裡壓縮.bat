@echo off
setlocal
cd /d "%~dp0"

if "%~1"=="" (
  echo Drag image files onto this bat file.
  echo.
  echo Compressed images will be saved in the image-output folder.
  pause
  exit /b 1
)

python scripts\compress-images.py %*

echo.
echo Done. Opening image-output folder...
start "" "%cd%\image-output"
pause

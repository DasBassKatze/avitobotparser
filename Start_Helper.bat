@echo off
chcp 65001 >nul
setlocal
cd /d "%~dp0"
if not exist ".venv\Scripts\python.exe" (
  py -3 -m venv .venv
  if errorlevel 1 goto error
)
.venv\Scripts\python.exe -m pip install -r helper\requirements.txt
if errorlevel 1 goto error
.venv\Scripts\python.exe -m playwright install chromium
if errorlevel 1 goto error
.venv\Scripts\python.exe helper\app.py
if errorlevel 1 goto error
exit /b 0
:error
echo Не удалось запустить помощник. Проверьте Python 3.11+ и доступ к загрузке зависимостей.
pause
exit /b 1

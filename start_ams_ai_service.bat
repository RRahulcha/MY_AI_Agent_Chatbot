@echo off
title SETTribe AMS - AI Assistant Microservice (Uvicorn)
color 0a
echo ========================================================
echo   SETTribe AMS - AI Assistant Microservice (FastAPI)
echo ========================================================
echo.
cd /d "%~dp0ams-ai"
echo Starting Uvicorn backend on http://127.0.0.1:8000 ...
echo API Documentation available at: http://127.0.0.1:8000/docs
echo.
".venv\Scripts\python.exe" -m uvicorn app.main:app --host 127.0.0.1 --port 8000 --reload
pause

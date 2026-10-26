@echo off
title SETTribe AMS - Live Face Attendance AI Service
color 0b
echo ========================================================
echo   SETTribe AMS - Live Face Attendance AI Service
echo ========================================================
echo.
cd /d "%~dp0ai_service"
echo Starting AI Service on http://localhost:5001 ...
echo.
python -u app.py
pause

@echo off
title SETTribe AMS - Live Camera Face Attendance Kiosk
color 0a
echo ========================================================
echo   SETTribe AMS - Desktop Camera Face Attendance Kiosk
echo ========================================================
echo.
cd /d "%~dp0ai_service"
echo Starting Desktop Camera Kiosk with OpenCV...
echo (Press 'q' or ESC in camera window to exit)
echo.
python live_kiosk.py
pause

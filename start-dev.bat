@echo off
echo.
echo  Starting Brilliant Mind Moods dev proxy...
echo  The Flutter app will connect via 10.0.2.2:8080 (no adb reverse needed).
echo.
echo  Keep this window open while the app is running.
echo  Press Ctrl+C to stop.
echo.
node "%~dp0dev-proxy.cjs"

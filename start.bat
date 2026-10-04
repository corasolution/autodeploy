@echo off
title AutoPilot Deploy - Dev Servers
echo Starting AutoPilot Deploy...
echo.

cd /d "d:\My Apps\autodeploy"

start "Laravel Server" cmd /k "php artisan serve"
timeout /t 2 >nul
start "Vite Dev" cmd /k "npm run dev"
timeout /t 2 >nul
start "Queue Worker" cmd /k "php artisan queue:work --queue=deployments,default --tries=1 --timeout=300"

echo.
echo All servers are starting!
echo Laravel:       http://localhost:8000
echo Vite:          Check the Vite terminal for the port
echo Queue Worker:  Processing deployments
echo.
pause

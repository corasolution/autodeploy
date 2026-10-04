@echo off
cd /d "d:\My Apps\autodeploy"
:loop
echo [%date% %time%] Starting queue worker...
php artisan queue:work --queue=deployments,default --tries=1 --timeout=300
echo [%date% %time%] Worker exited, restarting in 5 seconds...
timeout /t 5 /nobreak >nul
goto loop

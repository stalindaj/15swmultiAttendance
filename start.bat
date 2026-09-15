@echo off
title 15SW Attendance
cd /d "%~dp0"

call php artisan migrate --force --no-interaction
call php artisan attendance:cert
if not exist public\build\manifest.json call npm run build

start "" http://localhost:8080
node tools\gateway.mjs
pause

@echo off
rem ========================================================
rem CampusIQ Automated Offline Backup Runner
rem Executes silent local database snapshot via PHP CLI
rem ========================================================

set PHP_BIN=C:\xampp\php\php.exe
set SCRIPT=C:\xampp\htdocs\campusIQ\cron\auto_backup.php

if not exist "%PHP_BIN%" (
    echo [ERROR] PHP CLI not found at %PHP_BIN%
    exit /b 1
)

if not exist "%SCRIPT%" (
    echo [ERROR] Backup script not found at %SCRIPT%
    exit /b 1
)

echo [START] Running CampusIQ automated backup...
"%PHP_BIN%" "%SCRIPT%"
echo [DONE] Process completed.

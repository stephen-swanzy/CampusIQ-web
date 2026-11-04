@echo off
rem ========================================================
rem CampusIQ Windows Scheduled Task Registrar
rem Registers daily offline backup job in Windows Task Scheduler
rem ========================================================

echo ========================================================
echo CampusIQ: Setting up Daily Automated Offline Backup Task
echo ========================================================
echo.

set TASK_NAME=CampusIQ_Daily_Offline_Backup
set BAT_PATH=C:\xampp\htdocs\campusIQ\scripts\run_offline_backup.bat

rem Create daily task scheduled at 23:00 (11:00 PM)
schtasks /create /tn %TASK_NAME% /tr %BAT_PATH% /sc daily /st 23:00 /f

if %ERRORLEVEL% EQU 0 (
    echo.
    echo ========================================================
    echo [SUCCESS] Windows Task '%TASK_NAME%' registered!
    echo Frequency: Daily at 11:00 PM
    echo Runner: %BAT_PATH%
    echo Target: C:\xampp\htdocs\campusIQ\storage\backups\
    echo ========================================================
) else (
    echo.
    echo [ERROR] Failed to register task. Please right-click this .bat file and select 'Run as administrator'.
)

echo.
pause

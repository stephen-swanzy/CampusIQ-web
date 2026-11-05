@echo off
title CampusIQ - Deploy to Vercel
cd /d "%~dp0"
echo ========================================================
echo          CampusIQ Auto-Deploy to Vercel (GitHub)        
echo ========================================================
echo.

:: Check if git is installed
where git >nul 2>nul
if %errorlevel% neq 0 (
    echo [ERROR] Git is not installed or not in PATH!
    pause
    exit /b 1
)

:: Prompt for commit message or use default
set /p msg="Enter description of your changes (Press Enter for default): "
if "%msg%"=="" set msg=Update CampusIQ website features and design

echo.
echo [1/3] Staging all changed files...
git add -A

echo [2/3] Committing changes...
git commit -m "%msg%"

echo [3/3] Pushing to GitHub (triggers instant Vercel build)...
git push origin main

echo.
if %errorlevel% equ 0 (
    echo ========================================================
    echo    SUCCESS! Changes pushed to GitHub!                  
    echo    Vercel is now building and deploying your website:  
    echo    https://campusiq-web.vercel.app                     
    echo ========================================================
) else (
    echo [ERROR] Push failed. Please check your internet connection or git credentials.
)

echo.
pause

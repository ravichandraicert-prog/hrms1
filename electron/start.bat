@echo off
REM HRMS Desktop Application Launcher
REM This script ensures XAMPP services are running before launching Electron

echo Starting HRMS Desktop Application...
echo.

REM Check if XAMPP is installed
if not exist "C:\xampp" (
    echo ERROR: XAMPP not found in C:\xampp
    echo Please install XAMPP or modify this script to point to your XAMPP installation.
    pause
    exit /b 1
)

REM Start Apache
echo Starting Apache...
"C:\xampp\apache_start.bat"

REM Start MySQL
echo Starting MySQL...
"C:\xampp\mysql_start.bat"

REM Wait for services to start
timeout /t 3 /nobreak >nul

REM Check if services are running
echo Checking services...
tasklist | findstr /i "httpd.exe" >nul
if errorlevel 1 (
    echo WARNING: Apache may not be running properly
) else (
    echo Apache is running
)

tasklist | findstr /i "mysqld.exe" >nul
if errorlevel 1 (
    echo WARNING: MySQL may not be running properly
) else (
    echo MySQL is running
)

echo.
echo Launching HRMS Desktop Application...
echo.

REM Install dependencies if not already installed
if not exist "node_modules" (
    echo Installing Electron dependencies...
    call npm install
)

REM Start Electron
call npm start

pause

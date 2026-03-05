@echo off
:: Batch file to run MediVerse License Cron Job
:: This should be scheduled in Windows Task Scheduler to run daily at midnight
:: Path: c:\xampp\htdocs\mediverse\license_cron.bat

echo Running MediVerse License Cron Job...
"C:\xampp\php\php.exe" "C:\xampp\htdocs\mediverse\license_cron.php"

if %ERRORLEVEL% NEQ 0 (
    echo Error running cron job!
    exit /b %ERRORLEVEL%
)

echo Cron job completed successfully.
:: Pause if run manually to see output, otherwise comment out for automated tasks
:: pause

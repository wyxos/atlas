@echo off
setlocal

where docker >nul 2>&1
if %errorlevel% neq 0 (
  echo Docker is required. Install Docker Desktop, then re-run this script.
  echo https://www.docker.com/products/docker-desktop/
  exit /b 1
)

powershell -ExecutionPolicy Bypass -File ".\scripts\setup-env.ps1"

docker compose up -d --build

echo Waiting for app container...
set /a attempts=0
:wait
set /a attempts+=1
docker compose exec app php -v >nul 2>&1
if %errorlevel%==0 goto ready
if %attempts% geq 30 goto ready
timeout /t 2 >nul
goto wait

:ready
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --force
docker compose exec app php artisan app:setup

echo.
echo Atlas is starting up.
echo Open: http://localhost:8080

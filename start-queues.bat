@echo off
echo Starting 3 queue workers...

start "Queue Worker 1" php artisan queue:work
start "Queue Worker 2" php artisan queue:work
start "Queue Worker 3" php artisan queue:work

echo Started 3 queue workers successfully!

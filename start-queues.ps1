# Restart queue to cancel existing jobs
Write-Host "Restarting queue to cancel existing jobs..."
php artisan queue:restart

# Start 4 queue workers in separate processes
for ($i = 1; $i -le 4; $i++) {
    Write-Host "Starting queue worker $i..."
    Start-Process -FilePath "php" -ArgumentList "artisan", "queue:work", "--queue=default", "--tries=3" -WindowStyle Normal
}



for ($i = 1; $i -le 4; $i++) {
    Write-Host "Starting queue worker $i..."
#    Start-Process -FilePath "php" -ArgumentList "artisan", "queue:work", "--queue=default", "--tries=3" -WindowStyle Normal
    Start-Process -FilePath "php" -ArgumentList "artisan", "queue:work", "--queue=processing" -WindowStyle Normal
}

Write-Host "Started 4 queue workers successfully!"

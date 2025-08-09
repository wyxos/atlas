# Start 3 queue workers in separate processes
for ($i = 1; $i -le 3; $i++) {
    Write-Host "Starting queue worker $i..."
    Start-Process -FilePath "php" -ArgumentList "artisan", "queue:work" -WindowStyle Normal
}

Write-Host "Started 3 queue workers successfully!"

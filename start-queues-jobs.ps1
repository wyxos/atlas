# Start 3 queue workers as background jobs
for ($i = 1; $i -le 3; $i++) {
    Write-Host "Starting queue worker $i as background job..."
    Start-Job -Name "QueueWorker$i" -ScriptBlock {
        Set-Location $using:PWD
        php artisan queue:work --queue=default,processing
    }
}

Write-Host "Started 3 queue workers as background jobs!"
Write-Host "Use 'Get-Job' to see status and 'Stop-Job -Name QueueWorker*' to stop all workers"

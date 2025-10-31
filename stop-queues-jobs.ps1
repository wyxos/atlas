# Stop background queue worker jobs started by start-queues-jobs.ps1
# Looks for jobs named "QueueWorker*" and stops/removes them

$jobs = Get-Job -Name 'QueueWorker*' -ErrorAction SilentlyContinue

if (-not $jobs -or $jobs.Count -eq 0) {
    Write-Host "No QueueWorker jobs found to stop."
    return
}

Write-Host "Found $($jobs.Count) QueueWorker job(s). Stopping..."

foreach ($job in $jobs) {
    try {
        # Attempt graceful stop first
        Stop-Job -Job $job -ErrorAction SilentlyContinue
    } catch {
        # Force stop if needed
        Stop-Job -Job $job -Force -ErrorAction SilentlyContinue
    }
}

# Give a brief moment for jobs to transition state
Start-Sleep -Seconds 1

# Clean up stopped/failed jobs
foreach ($job in $jobs) {
    try {
        # Optionally retrieve any output to avoid stale data
        if ($job.HasMoreData) {
            Receive-Job -Job $job -ErrorAction SilentlyContinue | Out-Null
        }
        Remove-Job -Job $job -Force -ErrorAction SilentlyContinue
    } catch {
        # Ignore cleanup errors
    }
}

Write-Host "Stopped and removed QueueWorker jobs."

# Show any remaining QueueWorker jobs (should be none)
$remaining = Get-Job -Name 'QueueWorker*' -ErrorAction SilentlyContinue
if ($remaining -and $remaining.Count -gt 0) {
    Write-Host "Warning: Some QueueWorker jobs remain:"
    $remaining | Format-Table Id, Name, State, HasMoreData -AutoSize
} else {
    Write-Host "All QueueWorker jobs have been stopped."
}


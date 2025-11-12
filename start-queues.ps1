# Ensure the script runs from the project root
$projectPath = $PSScriptRoot
Set-Location -LiteralPath $projectPath

# Restart queue to cancel existing jobs
Write-Host "Restarting queue to cancel existing jobs..."
php artisan queue:restart

$pwshCommand = Get-Command pwsh -ErrorAction SilentlyContinue
if ($pwshCommand) {
    $shellExecutable = $pwshCommand.Source
} else {
    $shellExecutable = (Get-Command powershell -ErrorAction Stop).Source
}

function Start-QueueWorker {
    param(
        [string]$queueLabel,
        [int]$index,
        [string[]]$artisanArguments
    )

    Write-Host "Starting $queueLabel queue worker $index..."

    $arguments = $artisanArguments -join ' '
    $command = 'Set-Location -LiteralPath "{0}"; php artisan queue:work {1}' -f $projectPath, $arguments

    Start-Process -FilePath $shellExecutable -ArgumentList '-NoExit', '-Command', $command -WorkingDirectory $projectPath -WindowStyle Normal
}

# Default queue - quick operations (metadata, renames, deletions, covers, enrichment, Scout indexing)
for ($i = 1; $i -le 3; $i++) {
    Start-QueueWorker "default" $i @('--queue=default', '--tries=3')
}

# Processing queue - CPU/memory-intensive media processing (images, audio, video)
for ($i = 1; $i -le 2; $i++) {
    Start-QueueWorker "processing" $i @('--queue=processing', '--tries=3', '--timeout=300')
}

# Downloads queue - long-running file downloads
for ($i = 1; $i -le 2; $i++) {
    Start-QueueWorker "downloads" $i @('--queue=downloads', '--tries=2', '--timeout=600')
}

# Composer queue - system-level composer operations (single worker to prevent conflicts)
Start-QueueWorker "composer" 1 @('--queue=composer', '--tries=1', '--timeout=600')

# Spotify queue - API-bound Spotify operations
Start-QueueWorker "spotify" 1 @('--queue=spotify', '--tries=2', '--timeout=300')

Write-Host "Started 9 queue workers successfully!"
Write-Host "  - 3x default queue workers"
Write-Host "  - 2x processing queue workers"
Write-Host "  - 2x downloads queue workers"
Write-Host "  - 1x composer queue worker"
Write-Host "  - 1x spotify queue worker"
Write-Host ""
Write-Host "Note: For production, use Horizon instead: php artisan horizon"

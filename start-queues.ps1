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

for ($i = 1; $i -le 4; $i++) {
    Start-QueueWorker "default" $i @('--queue=default', '--tries=3')
}

for ($i = 1; $i -le 4; $i++) {
    Start-QueueWorker "processing" $i @('--queue=processing')
}

Write-Host "Started 8 queue workers successfully!"

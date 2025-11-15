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

# Extract queue configuration from Horizon config using PHP
Write-Host "Reading queue configuration from Horizon config..."
$phpCode = @'
<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$defaults = config('horizon.defaults', []);
$environments = config('horizon.environments', []);
$env = app()->environment();

$envOverrides = $environments[$env] ?? [];

$queues = [];
foreach ($defaults as $supervisor => $supervisorConfig) {
    // Merge environment-specific overrides
    $mergedConfig = array_merge($supervisorConfig, $envOverrides[$supervisor] ?? []);
    
    foreach ($mergedConfig['queue'] ?? [] as $queueName) {
        $queues[] = [
            'name' => $queueName,
            'supervisor' => $supervisor,
            'maxProcesses' => $mergedConfig['maxProcesses'] ?? 1,
            'tries' => $mergedConfig['tries'] ?? 3,
            'timeout' => $mergedConfig['timeout'] ?? 60,
        ];
    }
}

echo json_encode($queues, JSON_PRETTY_PRINT);
'@

$horizonConfigJson = $phpCode | php
$queues = $horizonConfigJson | ConvertFrom-Json

$totalWorkers = 0
$queueSummary = @()

foreach ($queue in $queues) {
    $queueName = $queue.name
    $maxProcesses = $queue.maxProcesses
    $tries = $queue.tries
    $timeout = $queue.timeout
    
    $artisanArgs = @("--queue=$queueName", "--tries=$tries")
    if ($timeout -gt 0) {
        $artisanArgs += "--timeout=$timeout"
    }
    
    for ($i = 1; $i -le $maxProcesses; $i++) {
        Start-QueueWorker $queueName $i $artisanArgs
        $totalWorkers++
    }
    
    $queueSummary += "  - ${maxProcesses}x $queueName queue workers"
}

Write-Host ""
Write-Host "Started $totalWorkers queue workers successfully!"
foreach ($summary in $queueSummary) {
    Write-Host $summary
}
Write-Host ""
Write-Host "Note: For production, use Horizon instead: php artisan horizon"

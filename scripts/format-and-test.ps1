# Requires: Node (npm), PHP, Composer vendor deps installed
$ErrorActionPreference = 'Stop'

# Run from repo root
$scriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
Set-Location (Resolve-Path (Join-Path $scriptDir '..'))

function Assert-LastExit {
  param(
    [int]$Code,
    [string]$Step
  )
  if ($Code -ne 0) {
    Write-Error "Failed: $Step (code $Code)"
    exit $Code
  }
}

# 1/4 ESLint (fix)
Write-Host '1/4 ESLint (fix)'
& npm.cmd run lint --if-present --silent -- --fix
Assert-LastExit -Code ($LASTEXITCODE ?? 0) -Step 'ESLint'

# 2/4 Laravel Pint (fix)
Write-Host '2/4 Laravel Pint (fix)'
$LASTEXITCODE = 0
$ranPint = $false
if (Test-Path ./vendor/bin/pint) {
  & php ./vendor/bin/pint
  $ranPint = $true
} elseif (Get-Command pint -ErrorAction SilentlyContinue) {
  & pint
  $ranPint = $true
} else {
  Write-Host 'Pint not found; skipping'
}
if ($ranPint) { Assert-LastExit -Code ($LASTEXITCODE ?? 0) -Step 'Pint' }

# 3/4 JS tests
Write-Host '3/4 JS tests'
& npm.cmd run test
Assert-LastExit -Code ($LASTEXITCODE ?? 0) -Step 'JS tests'

# 4/4 PHP tests
Write-Host '4/4 PHP tests'
& php artisan test
Assert-LastExit -Code ($LASTEXITCODE ?? 0) -Step 'PHP tests'

Write-Host 'Done'
exit 0

# Atlas v2 installer (Windows PowerShell)
# Usage (PowerShell):
#   iwr -useb https://raw.githubusercontent.com/wyxos/atlas/main/install.ps1 | iex
# Optional env:
#   $env:ATLAS_DIR = "Atlas"   # target directory
#   $env:ATLAS_REF = "main"    # branch/tag/sha

$ErrorActionPreference = "Stop"

$RepoUrl = if ($env:ATLAS_REPO_URL) { $env:ATLAS_REPO_URL } else { "https://github.com/wyxos/atlas.git" }
$AtlasDir = if ($env:ATLAS_DIR) { $env:ATLAS_DIR } else { "Atlas" }
$AtlasRef = if ($env:ATLAS_REF) { $env:ATLAS_REF } else { "main" }

function Need-Command([string]$Name) {
    if (-not (Get-Command $Name -ErrorAction SilentlyContinue)) {
        throw "Missing required command: $Name"
    }
}

Need-Command git
Need-Command curl

if (-not (Get-Command docker -ErrorAction SilentlyContinue)) {
    Write-Error "Docker is required. Install Docker Desktop, then re-run this script."
    Write-Error "https://www.docker.com/products/docker-desktop/"
    exit 1
}

if (Test-Path $AtlasDir) {
    if (-not (Test-Path (Join-Path $AtlasDir ".git"))) {
        Write-Host "Target directory exists but is not a git repo: $AtlasDir" -ForegroundColor Yellow
        Write-Host "Delete/rename it or set ATLAS_DIR to a different path." -ForegroundColor Yellow
        exit 1
    }
    Write-Host "Using existing repo at: $AtlasDir"
} else {
    Write-Host "Cloning Atlas into: $AtlasDir"
    git clone --depth 1 --branch $AtlasRef $RepoUrl $AtlasDir
}

Push-Location $AtlasDir
try {
    if (-not (Test-Path ".\scripts\setup.bat")) {
        throw "Missing scripts\\setup.bat in repo. Are you on the right branch/tag?"
    }
    cmd /c ".\\scripts\\setup.bat"
}
finally {
    Pop-Location
}

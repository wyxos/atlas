param(
    [string]$EnvFile = ".env"
)

function Prompt-Value {
    param(
        [string]$Label,
        [string]$Default
    )
    if ($Auto) { return $Default }
    $value = Read-Host "$Label [$Default]"
    if ([string]::IsNullOrWhiteSpace($value)) {
        return $Default
    }
    return $value
}

function Set-EnvValue {
    param(
        [string]$Key,
        [string]$Value,
        [string]$Path
    )
    $content = Get-Content $Path -Raw
    $escapedKey = [regex]::Escape($Key)
    $pattern = "(?m)^$escapedKey=.*$"
    if ($content -match $pattern) {
        $content = [regex]::Replace($content, $pattern, "$Key=$Value")
    } else {
        if (-not $content.EndsWith("`n")) {
            $content += "`n"
        }
        $content += "$Key=$Value`n"
    }
    Set-Content $Path $content -Encoding ascii -NoNewline
}

if (-not (Test-Path $EnvFile)) {
    Copy-Item ".env.example" $EnvFile
}

Write-Host "Atlas .env setup"
Write-Host "Press Enter to accept defaults."

$appUrl = Prompt-Value "APP_URL" "http://localhost:6363"
$dbConnection = Prompt-Value "DB_CONNECTION" "mariadb"
$dbHost = Prompt-Value "DB_HOST" "db"
$dbPort = Prompt-Value "DB_PORT" "3306"
$dbDatabase = Prompt-Value "DB_DATABASE" "atlas"
$dbUsername = Prompt-Value "DB_USERNAME" "atlas"
$dbPassword = Prompt-Value "DB_PASSWORD" "atlas"
$redisHost = Prompt-Value "REDIS_HOST" "redis"
$scoutDriver = Prompt-Value "SCOUT_DRIVER" "typesense"
$typesenseHost = Prompt-Value "TYPESENSE_HOST" "typesense"
$typesensePort = Prompt-Value "TYPESENSE_PORT" "8108"
$typesenseApiKey = Prompt-Value "TYPESENSE_API_KEY" "typesense"
$ffmpegPath = Prompt-Value "DOWNLOADS_FFMPEG_PATH" "ffmpeg"
$atlasStorage = Prompt-Value "ATLAS_STORAGE" "/data/atlas"
$reverbHost = Prompt-Value "REVERB_HOST" "localhost"
$reverbPort = Prompt-Value "REVERB_PORT" "6364"
$reverbScheme = Prompt-Value "REVERB_SCHEME" "http"

Set-EnvValue "APP_URL" $appUrl $EnvFile
Set-EnvValue "DB_CONNECTION" $dbConnection $EnvFile
Set-EnvValue "DB_HOST" $dbHost $EnvFile
Set-EnvValue "DB_PORT" $dbPort $EnvFile
Set-EnvValue "DB_DATABASE" $dbDatabase $EnvFile
Set-EnvValue "DB_USERNAME" $dbUsername $EnvFile
Set-EnvValue "DB_PASSWORD" $dbPassword $EnvFile
Set-EnvValue "REDIS_HOST" $redisHost $EnvFile
Set-EnvValue "SCOUT_DRIVER" $scoutDriver $EnvFile
Set-EnvValue "TYPESENSE_HOST" $typesenseHost $EnvFile
Set-EnvValue "TYPESENSE_PORT" $typesensePort $EnvFile
Set-EnvValue "TYPESENSE_API_KEY" $typesenseApiKey $EnvFile
Set-EnvValue "DOWNLOADS_FFMPEG_PATH" $ffmpegPath $EnvFile
Set-EnvValue "ATLAS_STORAGE" $atlasStorage $EnvFile
Set-EnvValue "REVERB_HOST" $reverbHost $EnvFile
Set-EnvValue "REVERB_PORT" $reverbPort $EnvFile
Set-EnvValue "REVERB_SCHEME" $reverbScheme $EnvFile
Set-EnvValue "QUEUE_CONNECTION" "redis" $EnvFile
Set-EnvValue "CACHE_STORE" "redis" $EnvFile

Write-Host "Updated $EnvFile"

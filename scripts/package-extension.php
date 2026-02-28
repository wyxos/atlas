<?php

declare(strict_types=1);

function parseSemver(string $version): ?array
{
    if (! preg_match('/^(\d+)\.(\d+)\.(\d+)$/', $version, $matches)) {
        return null;
    }

    return [
        (int) $matches[1],
        (int) $matches[2],
        (int) $matches[3],
    ];
}

function incrementSemver(string $currentVersion, string $bumpType): ?string
{
    $parts = parseSemver($currentVersion);
    if ($parts === null) {
        return null;
    }

    [$major, $minor, $patch] = $parts;

    return match ($bumpType) {
        'major' => sprintf('%d.0.0', $major + 1),
        'minor' => sprintf('%d.%d.0', $major, $minor + 1),
        'patch' => sprintf('%d.%d.%d', $major, $minor, $patch + 1),
        default => null,
    };
}

$projectRoot = dirname(__DIR__);
$extensionRoot = $projectRoot.'/extension';
$distDirectory = $extensionRoot.'/dist';
$manifestPath = $extensionRoot.'/manifest.json';
$defaultDownloadsDirectory = $projectRoot.'/public/downloads';

if (! is_dir($distDirectory)) {
    fwrite(STDERR, "Extension dist directory not found: {$distDirectory}\n");
    exit(1);
}

if (! file_exists($manifestPath)) {
    fwrite(STDERR, "Extension manifest not found: {$manifestPath}\n");
    exit(1);
}

$manifestData = json_decode((string) file_get_contents($manifestPath), true);
if (! is_array($manifestData) || ! isset($manifestData['version']) || ! is_string($manifestData['version'])) {
    fwrite(STDERR, "Extension manifest is missing a valid version field.\n");
    exit(1);
}

$currentVersion = trim($manifestData['version']);
if ($currentVersion === '') {
    fwrite(STDERR, "Extension version is empty.\n");
    exit(1);
}

$options = getopt('', ['version::', 'bump::', 'output-dir::']);
$requestedVersion = isset($options['version']) ? trim((string) $options['version']) : null;
$requestedBump = isset($options['bump']) ? trim((string) $options['bump']) : null;
$requestedOutputDirectory = isset($options['output-dir']) ? trim((string) $options['output-dir']) : null;

if ($requestedVersion !== null && $requestedVersion !== '' && parseSemver($requestedVersion) === null) {
    fwrite(STDERR, "Invalid --version value '{$requestedVersion}'. Expected SemVer format like 1.2.3.\n");
    exit(1);
}

if ($requestedBump !== null && $requestedBump !== '' && ! in_array($requestedBump, ['major', 'minor', 'patch'], true)) {
    fwrite(STDERR, "Invalid --bump value '{$requestedBump}'. Use major, minor, or patch.\n");
    exit(1);
}

if ($requestedOutputDirectory !== null && $requestedOutputDirectory === '') {
    fwrite(STDERR, "Invalid --output-dir value ''. Provide a valid directory path.\n");
    exit(1);
}

$version = $currentVersion;

if ($requestedVersion !== null && $requestedVersion !== '') {
    $version = $requestedVersion;
} elseif ($requestedBump !== null && $requestedBump !== '') {
    $nextVersion = incrementSemver($currentVersion, $requestedBump);
    if ($nextVersion === null) {
        fwrite(STDERR, "Unable to bump version '{$currentVersion}'. Expected SemVer format like 1.2.3.\n");
        exit(1);
    }

    $version = $nextVersion;
}

if ($version !== $currentVersion) {
    $manifestData['version'] = $version;
    $encodedManifest = json_encode($manifestData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($encodedManifest === false || file_put_contents($manifestPath, $encodedManifest.PHP_EOL) === false) {
        fwrite(STDERR, "Failed to update extension manifest version.\n");
        exit(1);
    }

    fwrite(STDOUT, "Updated extension manifest version: {$currentVersion} -> {$version}\n");
}

if (! copy($manifestPath, $distDirectory.'/manifest.json')) {
    fwrite(STDERR, "Failed to sync extension manifest into dist output.\n");
    exit(1);
}

if ($requestedOutputDirectory === null || $requestedOutputDirectory === '') {
    $downloadsDirectory = $defaultDownloadsDirectory;
} else {
    $downloadsDirectory = $requestedOutputDirectory;
}

if (! is_dir($downloadsDirectory) && ! mkdir($downloadsDirectory, 0775, true) && ! is_dir($downloadsDirectory)) {
    fwrite(STDERR, "Failed to create downloads directory: {$downloadsDirectory}\n");
    exit(1);
}

$versionedArchive = "{$downloadsDirectory}/atlas-extension-{$version}.zip";
$latestArchive = "{$downloadsDirectory}/atlas-extension.zip";

$zip = new ZipArchive;
if ($zip->open($versionedArchive, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "Unable to create archive: {$versionedArchive}\n");
    exit(1);
}

$directoryIterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($distDirectory, RecursiveDirectoryIterator::SKIP_DOTS),
    RecursiveIteratorIterator::LEAVES_ONLY,
);

foreach ($directoryIterator as $fileInfo) {
    if (! $fileInfo instanceof SplFileInfo || ! $fileInfo->isFile()) {
        continue;
    }

    $absolutePath = $fileInfo->getPathname();
    $archivePath = str_replace('\\', '/', substr($absolutePath, strlen($distDirectory) + 1));
    $zip->addFile($absolutePath, $archivePath);
}

$zip->close();

if (! copy($versionedArchive, $latestArchive)) {
    fwrite(STDERR, "Failed to refresh latest archive alias: {$latestArchive}\n");
    exit(1);
}

fwrite(STDOUT, "Created extension package: {$versionedArchive}\n");
fwrite(STDOUT, "Updated latest package: {$latestArchive}\n");

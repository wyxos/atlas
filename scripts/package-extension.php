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

function clearDirectory(string $directory, ?callable $keepPredicate = null): bool
{
    $entries = scandir($directory);
    if ($entries === false) {
        return false;
    }

    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $absolutePath = $directory.DIRECTORY_SEPARATOR.$entry;
        if ($keepPredicate !== null && $keepPredicate($absolutePath, $entry) === true) {
            continue;
        }

        if (is_dir($absolutePath)) {
            if (! clearDirectory($absolutePath) || ! rmdir($absolutePath)) {
                return false;
            }

            continue;
        }

        if (! unlink($absolutePath)) {
            return false;
        }
    }

    return true;
}

function runGitCommand(string $projectRoot, string $command, ?array &$output = null): int
{
    $fullCommand = 'git -C '.escapeshellarg($projectRoot).' '.$command.' 2>&1';

    exec($fullCommand, $output, $exitCode);

    return $exitCode;
}

function hasExtensionChanges(string $projectRoot): bool
{
    $gitOutput = [];
    if (runGitCommand($projectRoot, 'rev-parse --is-inside-work-tree', $gitOutput) !== 0) {
        return true;
    }

    $statusOutput = [];
    if (runGitCommand($projectRoot, 'status --porcelain=1 --untracked-files=all -- extension', $statusOutput) !== 0) {
        return true;
    }

    foreach ($statusOutput as $line) {
        if (trim((string) $line) !== '') {
            return true;
        }
    }

    return false;
}

function normalizeEnvValue(string $value): string
{
    $trimmed = trim($value);
    if ($trimmed === '') {
        return '';
    }

    $quote = $trimmed[0];
    if (($quote === '"' || $quote === "'") && str_ends_with($trimmed, $quote)) {
        return substr($trimmed, 1, -1);
    }

    return $trimmed;
}

function readDotEnvValue(string $dotenvPath, string $key): ?string
{
    if (! file_exists($dotenvPath)) {
        return null;
    }

    $lines = file($dotenvPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (! is_array($lines)) {
        return null;
    }

    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '#') || ! str_contains($trimmed, '=')) {
            continue;
        }

        [$candidateKey, $candidateValue] = explode('=', $trimmed, 2);
        if (trim($candidateKey) !== $key) {
            continue;
        }

        $normalized = normalizeEnvValue($candidateValue);

        return $normalized === '' ? null : $normalized;
    }

    return null;
}

$projectRoot = dirname(__DIR__);
$extensionRoot = $projectRoot.'/extension';
$distDirectory = $extensionRoot.'/dist';
$manifestPath = $extensionRoot.'/manifest.json';
$defaultDownloadsDirectory = $projectRoot.'/public/downloads';
$dotenvPath = $projectRoot.'/.env';

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

$options = getopt('', ['version::', 'bump::', 'bump-if-extension-changed', 'output-dir::', 'extract-dir::']);
$requestedVersion = isset($options['version']) ? trim((string) $options['version']) : null;
$requestedBump = isset($options['bump']) ? trim((string) $options['bump']) : null;
$bumpIfExtensionChanged = array_key_exists('bump-if-extension-changed', $options);
$requestedOutputDirectory = isset($options['output-dir']) ? trim((string) $options['output-dir']) : null;
$requestedExtractDirectory = isset($options['extract-dir']) ? trim((string) $options['extract-dir']) : null;
$envExtractDirectory = getenv('EXTENSION_LOCAL_EXTRACT_DIR');
if ($envExtractDirectory === false || trim((string) $envExtractDirectory) === '') {
    $envExtractDirectory = readDotEnvValue($dotenvPath, 'EXTENSION_LOCAL_EXTRACT_DIR');
}
$envPackageDirectory = getenv('EXTENSION_LOCAL_PACKAGE_DIR');
if ($envPackageDirectory === false || trim((string) $envPackageDirectory) === '') {
    $envPackageDirectory = readDotEnvValue($dotenvPath, 'EXTENSION_LOCAL_PACKAGE_DIR');
}

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

if ($requestedExtractDirectory !== null && $requestedExtractDirectory === '') {
    fwrite(STDERR, "Invalid --extract-dir value ''. Provide a valid directory path.\n");
    exit(1);
}

$version = $currentVersion;

if ($requestedVersion !== null && $requestedVersion !== '') {
    $version = $requestedVersion;
} elseif ($requestedBump !== null && $requestedBump !== '') {
    if ($bumpIfExtensionChanged && ! hasExtensionChanges($projectRoot)) {
        fwrite(STDOUT, "Skipped extension version bump ({$requestedBump}); no extension changes detected.\n");
    } else {
        $nextVersion = incrementSemver($currentVersion, $requestedBump);
        if ($nextVersion === null) {
            fwrite(STDERR, "Unable to bump version '{$currentVersion}'. Expected SemVer format like 1.2.3.\n");
            exit(1);
        }

        $version = $nextVersion;
    }
}

if ($version !== $currentVersion) {
    $manifestData['version'] = $version;
    $encodedManifest = json_encode($manifestData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($encodedManifest === false || file_put_contents($manifestPath, $encodedManifest.PHP_EOL) === false) {
        fwrite(STDERR, "Failed to update extension manifest version.\n");
        exit(1);
    }

    fwrite(STDOUT, "Updated extension manifest version: {$currentVersion} -> {$version}\n");

    $gitOutput = [];
    if (runGitCommand($projectRoot, 'rev-parse --is-inside-work-tree', $gitOutput) === 0) {
        $manifestPathspec = escapeshellarg('extension/manifest.json');

        $gitOutput = [];
        if (runGitCommand($projectRoot, 'add '.$manifestPathspec, $gitOutput) !== 0) {
            fwrite(STDERR, "Failed to stage extension manifest for commit.\n");
            exit(1);
        }

        $gitOutput = [];
        if (runGitCommand($projectRoot, 'diff --cached --quiet -- '.$manifestPathspec, $gitOutput) === 1) {
            $commitMessage = escapeshellarg("Bump extension manifest version to {$version}");
            $commitCommand = 'commit -m '.$commitMessage.' -- '.$manifestPathspec;
            $gitOutput = [];
            if (runGitCommand($projectRoot, $commitCommand, $gitOutput) !== 0) {
                fwrite(STDERR, "Failed to commit extension manifest version bump.\n");
                fwrite(STDERR, implode(PHP_EOL, $gitOutput).PHP_EOL);
                exit(1);
            }

            fwrite(STDOUT, "Committed extension manifest version bump.\n");
        }
    }
}

if (! copy($manifestPath, $distDirectory.'/manifest.json')) {
    fwrite(STDERR, "Failed to sync extension manifest into dist output.\n");
    exit(1);
}

$distManifestPath = $distDirectory.'/manifest.json';
$distManifestData = json_decode((string) file_get_contents($distManifestPath), true);
if (! is_array($distManifestData)) {
    fwrite(STDERR, "Dist manifest is not valid JSON: {$distManifestPath}\n");
    exit(1);
}

$contentScripts = $distManifestData['content_scripts'] ?? null;
if (! is_array($contentScripts) || $contentScripts === []) {
    fwrite(STDERR, "Dist manifest is missing content_scripts.\n");
    exit(1);
}

$hasContentEntry = false;
foreach ($contentScripts as $contentScript) {
    if (! is_array($contentScript) || ! isset($contentScript['js']) || ! is_array($contentScript['js'])) {
        continue;
    }

    if (in_array('content.js', $contentScript['js'], true)) {
        $hasContentEntry = true;
        break;
    }
}

if (! $hasContentEntry) {
    fwrite(STDERR, "Dist manifest content_scripts do not include content.js.\n");
    exit(1);
}

if (! file_exists($distDirectory.'/content.js')) {
    fwrite(STDERR, "Dist content script bundle missing: {$distDirectory}/content.js\n");
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

if ($requestedExtractDirectory === null || $requestedExtractDirectory === '') {
    if (is_string($envExtractDirectory) && trim($envExtractDirectory) !== '') {
        $requestedExtractDirectory = trim($envExtractDirectory);
    } elseif (is_string($envPackageDirectory) && trim($envPackageDirectory) !== '') {
        // Keep the old env var working as a local unpacked extension target.
        $requestedExtractDirectory = trim($envPackageDirectory);
    }
}

if ($requestedExtractDirectory !== null && $requestedExtractDirectory !== '') {
    if (! is_dir($requestedExtractDirectory) && ! mkdir($requestedExtractDirectory, 0775, true) && ! is_dir($requestedExtractDirectory)) {
        fwrite(STDERR, "Failed to create extract directory: {$requestedExtractDirectory}\n");
        exit(1);
    }

    $keepPredicate = null;
    if (realpath($requestedExtractDirectory) === realpath($downloadsDirectory)) {
        $keepPredicate = static fn (string $absolutePath, string $entry): bool => is_file($absolutePath) && preg_match('/^atlas-extension(?:-\d+\.\d+\.\d+)?\.zip$/', $entry) === 1;
    }

    if (! clearDirectory($requestedExtractDirectory, $keepPredicate)) {
        fwrite(STDERR, "Failed to clear extract directory: {$requestedExtractDirectory}\n");
        exit(1);
    }

    $extractZip = new ZipArchive;
    if ($extractZip->open($latestArchive) !== true) {
        fwrite(STDERR, "Unable to open archive for extraction: {$latestArchive}\n");
        exit(1);
    }

    if (! $extractZip->extractTo($requestedExtractDirectory)) {
        $extractZip->close();
        fwrite(STDERR, "Failed to extract archive to: {$requestedExtractDirectory}\n");
        exit(1);
    }

    $extractZip->close();
    fwrite(STDOUT, "Extracted latest package to: {$requestedExtractDirectory}\n");
}

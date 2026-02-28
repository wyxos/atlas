<?php

declare(strict_types=1);

$projectRoot = dirname(__DIR__);
$extensionRoot = $projectRoot.'/extension';
$distDirectory = $extensionRoot.'/dist';
$manifestPath = $extensionRoot.'/manifest.json';
$downloadsDirectory = $projectRoot.'/public/downloads';

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

$version = trim($manifestData['version']);
if ($version === '') {
    fwrite(STDERR, "Extension version is empty.\n");
    exit(1);
}

if (! is_dir($downloadsDirectory) && ! mkdir($downloadsDirectory, 0775, true) && ! is_dir($downloadsDirectory)) {
    fwrite(STDERR, "Failed to create downloads directory: {$downloadsDirectory}\n");
    exit(1);
}

$versionedArchive = "{$downloadsDirectory}/atlas-extension-{$version}.zip";
$latestArchive = "{$downloadsDirectory}/atlas-extension.zip";

$zip = new ZipArchive();
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

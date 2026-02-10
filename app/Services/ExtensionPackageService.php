<?php

namespace App\Services;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use ZipArchive;

class ExtensionPackageService
{
    public function package(bool $force = false): array
    {
        $extensionPath = base_path('extension/atlas-downloader');
        $manifestPath = $extensionPath.'/manifest.json';

        if (! is_dir($extensionPath) || ! is_file($manifestPath)) {
            throw new RuntimeException('Extension folder not found.');
        }

        $manifest = json_decode((string) file_get_contents($manifestPath), true);
        $version = is_array($manifest) && isset($manifest['version'])
            ? (string) $manifest['version']
            : 'dev';

        $filename = "atlas-extension-{$version}.zip";
        $zipPath = storage_path("app/{$filename}");

        $includedFiles = $this->includedFiles($extensionPath);
        $latestChange = $this->latestMtime($includedFiles);
        $needsRebuild = $force || ! is_file($zipPath) || filemtime($zipPath) < $latestChange;

        // If the packaging rules change (e.g. newly-required files), mtime checks alone won't detect it.
        // Ensure the existing archive contains the required entry list; otherwise rebuild it.
        if (! $needsRebuild && is_file($zipPath)) {
            $needsRebuild = ! $this->zipContainsRequiredEntries($zipPath);
        }

        if ($needsRebuild) {
            $this->buildZip($extensionPath, $zipPath, $includedFiles);
        }

        return [
            'path' => $zipPath,
            'filename' => $filename,
            'version' => $version,
        ];
    }

    /**
     * @param  array<int, string>  $files
     */
    private function buildZip(string $sourceDir, string $zipPath, array $files): void
    {
        $root = realpath($sourceDir);
        if (! $root) {
            throw new RuntimeException('Unable to resolve extension path.');
        }

        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Unable to create extension archive.');
        }

        foreach ($files as $filePath) {
            $real = realpath($filePath);
            if (! $real || ! is_file($real)) {
                continue;
            }

            if (! str_starts_with($real, $root.DIRECTORY_SEPARATOR)) {
                continue;
            }

            $relative = substr($real, strlen($root) + 1);
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', $relative);
            $zip->addFile($real, $relative);
        }

        $zip->close();
    }

    /**
     * @param  array<int, string>  $files
     */
    private function latestMtime(array $files): int
    {
        $latest = 0;

        foreach ($files as $file) {
            if (! is_file($file)) {
                continue;
            }

            $latest = max($latest, (int) filemtime($file));
        }

        return $latest;
    }

    private function zipContainsRequiredEntries(string $zipPath): bool
    {
        $requiredEntries = [
            'manifest.json',
            'icon.svg',
            'icon-16.png',
            'icon-32.png',
            'icon-48.png',
            'icon-256.png',
            // The extension won't function without the compiled artifacts.
            'dist/background.js',
            'dist/content.js',
            'dist/content.css',
            'dist/options.html',
            'dist/options.js',
            'dist/options.css',
        ];

        $zip = new ZipArchive;
        if ($zip->open($zipPath) !== true) {
            return false;
        }

        foreach ($requiredEntries as $entry) {
            if ($zip->locateName($entry, ZipArchive::FL_NOCASE) === false) {
                $zip->close();

                return false;
            }
        }

        $zip->close();

        return true;
    }

    /**
     * @return array<int, string>
     */
    private function includedFiles(string $extensionPath): array
    {
        $distPath = $extensionPath.'/dist';
        if (! is_dir($distPath)) {
            throw new RuntimeException('Extension dist folder not found. Run `npm run build:extension`.');
        }

        $required = [
            $extensionPath.'/manifest.json',
            $extensionPath.'/icon.svg',
            $extensionPath.'/icon-16.png',
            $extensionPath.'/icon-32.png',
            $extensionPath.'/icon-48.png',
            $extensionPath.'/icon-256.png',
        ];

        $files = [];
        foreach ($required as $path) {
            if (! is_file($path)) {
                throw new RuntimeException("Missing required extension file: {$path}");
            }
            $real = realpath($path);
            if (! $real) {
                throw new RuntimeException("Unable to resolve extension file: {$path}");
            }
            $files[] = $real;
        }

        $distFiles = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($distPath, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($distFiles as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $real = $file->getRealPath();
            if (! $real) {
                continue;
            }

            $files[] = $real;
        }

        // Optional, but handy when someone opens the zip.
        $readme = $extensionPath.'/README.md';
        if (is_file($readme)) {
            $real = realpath($readme);
            if ($real) {
                $files[] = $real;
            }
        }

        return array_values(array_unique($files));
    }
}

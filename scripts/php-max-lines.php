<?php

declare(strict_types=1);

/**
 * Fail when PHP files exceed a max line threshold outside the approved legacy baseline.
 *
 * Usage:
 *   php scripts/php-max-lines.php --max=500 --report-baseline app routes config
 */
$defaultMaxLines = 500;
$defaultRoots = ['app', 'bootstrap', 'config', 'database', 'routes', 'tests'];
$legacyBaseline = [
    'app/Services/LocalService.php' => 1000,
    'app/Http/Controllers/ExtensionApiController.php' => 675,
    'app/Http/Controllers/FilesController.php' => 665,
    'tests/Feature/Browse/ModerationTest.php' => 629,
    'app/Services/Wallhaven.php' => 594,
    'tests/Feature/Browse/BrowseIndexTest.php' => 554,
    'app/Services/Downloads/FileDownloadFinalizer.php' => 552,
    'app/Services/MetricsService.php' => 551,
    'app/Services/Spotify/SpotifyOAuthService.php' => 521,
    'app/Services/Downloads/DownloadTransferPayload.php' => 517,
];

$args = $argv;
array_shift($args);

$maxLines = $defaultMaxLines;
$roots = [];
$reportBaseline = false;

foreach ($args as $arg) {
    if (str_starts_with($arg, '--max=')) {
        $value = (int) substr($arg, 6);

        if ($value <= 0) {
            fwrite(STDERR, "Invalid --max value: {$arg}\n");
            exit(1);
        }

        $maxLines = $value;

        continue;
    }

    if ($arg === '--report-baseline') {
        $reportBaseline = true;

        continue;
    }

    $roots[] = $arg;
}

if ($roots === []) {
    $roots = $defaultRoots;
}

$baseDir = dirname(__DIR__);
$violations = [];
$baselineWarnings = [];

foreach ($roots as $root) {
    $fullRoot = $baseDir.DIRECTORY_SEPARATOR.$root;

    if (! is_dir($fullRoot)) {
        continue;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($fullRoot, FilesystemIterator::SKIP_DOTS)
    );

    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        if (! $file->isFile() || strtolower($file->getExtension()) !== 'php') {
            continue;
        }

        $pathname = $file->getPathname();
        if (
            str_contains($pathname, DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR)
            || str_contains($pathname, DIRECTORY_SEPARATOR.'node_modules'.DIRECTORY_SEPARATOR)
            || str_contains($pathname, DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR)
            || str_contains($pathname, DIRECTORY_SEPARATOR.'bootstrap'.DIRECTORY_SEPARATOR.'cache'.DIRECTORY_SEPARATOR)
        ) {
            continue;
        }

        $lineCount = count(file($pathname));
        if ($lineCount <= $maxLines) {
            continue;
        }

        $relativePath = ltrim(str_replace($baseDir, '', $pathname), DIRECTORY_SEPARATOR);
        $normalizedPath = str_replace(DIRECTORY_SEPARATOR, '/', $relativePath);
        $baselineLimit = $legacyBaseline[$normalizedPath] ?? null;

        if ($baselineLimit === null) {
            $violations[] = [
                'path' => $normalizedPath,
                'lines' => $lineCount,
                'reason' => 'new',
            ];

            continue;
        }

        if ($lineCount > $baselineLimit) {
            $violations[] = [
                'path' => $normalizedPath,
                'lines' => $lineCount,
                'reason' => 'regressed',
                'baseline' => $baselineLimit,
            ];

            continue;
        }

        if ($reportBaseline) {
            $baselineWarnings[] = [
                'path' => $normalizedPath,
                'lines' => $lineCount,
                'baseline' => $baselineLimit,
            ];
        }
    }
}

usort($violations, static fn (array $a, array $b): int => $b['lines'] <=> $a['lines']);
usort($baselineWarnings, static fn (array $a, array $b): int => $b['lines'] <=> $a['lines']);

if ($violations !== []) {
    echo "PHP max-lines violations (>{$maxLines} lines):\n";
    foreach ($violations as $violation) {
        $suffix = $violation['reason'] === 'regressed'
            ? " (baseline {$violation['baseline']})"
            : ' (not in baseline)';
        echo " - {$violation['path']}: {$violation['lines']}{$suffix}\n";
    }

    exit(1);
}

$baselineCount = count($legacyBaseline);
echo "PHP max-lines check: no new violations above {$maxLines} lines.";
if ($baselineCount > 0) {
    echo " Legacy baseline files still above limit: {$baselineCount}.\n";
} else {
    echo "\n";
}

if ($reportBaseline && $baselineWarnings !== []) {
    echo "Legacy baseline files:\n";
    foreach ($baselineWarnings as $warning) {
        echo " - {$warning['path']}: {$warning['lines']} (baseline {$warning['baseline']})\n";
    }
}

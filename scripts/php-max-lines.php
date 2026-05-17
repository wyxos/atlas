<?php

declare(strict_types=1);

/**
 * Fail when PHP files exceed a max line threshold.
 *
 * Usage:
 *   php scripts/php-max-lines.php --max=500 --report-baseline app routes config
 */
$defaultMaxLines = 500;
$defaultRoots = ['app', 'bootstrap', 'config', 'database', 'routes', 'tests'];

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
        $violations[] = [
            'path' => $normalizedPath,
            'lines' => $lineCount,
        ];
    }
}

usort($violations, static fn (array $a, array $b): int => $b['lines'] <=> $a['lines']);

if ($violations !== []) {
    echo "PHP max-lines violations (>{$maxLines} lines):\n";
    foreach ($violations as $violation) {
        echo " - {$violation['path']}: {$violation['lines']}\n";
    }

    exit(1);
}

echo "PHP max-lines check: no violations above {$maxLines} lines.";
echo "\n";

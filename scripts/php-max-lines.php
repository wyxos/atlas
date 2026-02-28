<?php

declare(strict_types=1);

/**
 * Warn when PHP files exceed a max line threshold.
 *
 * Usage:
 *   php scripts/php-max-lines.php --max=500 app routes config
 */
$defaultMaxLines = 500;
$defaultRoots = ['app', 'bootstrap', 'config', 'database', 'routes', 'tests'];

$args = $argv;
array_shift($args);

$maxLines = $defaultMaxLines;
$roots = [];

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

    $roots[] = $arg;
}

if ($roots === []) {
    $roots = $defaultRoots;
}

$baseDir = dirname(__DIR__);
$warnings = [];

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
        if (! $file->isFile()) {
            continue;
        }

        if (strtolower($file->getExtension()) !== 'php') {
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

        if ($lineCount > $maxLines) {
            $relativePath = ltrim(str_replace($baseDir, '', $pathname), DIRECTORY_SEPARATOR);
            $warnings[] = ['path' => str_replace(DIRECTORY_SEPARATOR, '/', $relativePath), 'lines' => $lineCount];
        }
    }
}

if ($warnings === []) {
    echo "PHP max-lines check: no files exceed {$maxLines} lines.\n";
    exit(0);
}

usort($warnings, static fn (array $a, array $b): int => $b['lines'] <=> $a['lines']);

echo "PHP max-lines warnings (>{$maxLines} lines):\n";
foreach ($warnings as $warning) {
    echo " - {$warning['path']}: {$warning['lines']}\n";
}

exit(0);

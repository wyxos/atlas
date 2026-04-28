<?php

namespace App\Services\Downloads;

class YtDlpProgressLineClassifier
{
    public static function shouldMarkAssemblingFromLine(string $line): bool
    {
        $trimmed = trim($line);
        if ($trimmed === '') {
            return false;
        }

        $lower = strtolower($trimmed);

        if (preg_match('/^\[download\]\s+100(?:\.0+)?\s*%/i', $trimmed) === 1) {
            return true;
        }

        return str_starts_with($lower, '[merger]')
            || str_starts_with($lower, '[extractaudio]')
            || str_starts_with($lower, '[fixup')
            || str_starts_with($lower, '[metadata]')
            || str_starts_with($lower, '[modifychapters]')
            || str_contains($lower, 'merging formats into')
            || str_contains($lower, 'fixing mpeg-ts')
            || str_contains($lower, 'correcting container')
            || str_contains($lower, 'deleting original file');
    }
}

<?php

namespace App\Support;

class VideoPreviewSamplingPlan
{
    /**
     * @return list<array{start: float, end: float}>
     */
    public static function sampledWindows(float $durationSeconds, float $clipSeconds, int $clipCount): array
    {
        $durationSeconds = max(0.1, $durationSeconds);
        $clipSeconds = max(0.1, $clipSeconds);
        $clipCount = max(1, $clipCount);
        $sampleRangeEnd = max($clipSeconds, $durationSeconds / 2);

        if ($clipCount === 1) {
            return [[
                'start' => 0.0,
                'end' => min($clipSeconds, $sampleRangeEnd),
            ]];
        }

        $lastStart = max(0, $sampleRangeEnd - $clipSeconds);
        $step = $lastStart / ($clipCount - 1);
        $windows = [];

        for ($index = 0; $index < $clipCount; $index++) {
            $start = $index === $clipCount - 1 ? $lastStart : $step * $index;

            $windows[] = [
                'start' => $start,
                'end' => min($start + $clipSeconds, $sampleRangeEnd),
            ];
        }

        return $windows;
    }

    public static function selectFilterForDuration(
        float $durationSeconds,
        float $shortMaxSeconds,
        float $clipSeconds,
        int $clipCount,
    ): ?string {
        if ($durationSeconds <= max(0.1, $shortMaxSeconds)) {
            return null;
        }

        $ranges = array_map(function (array $window): string {
            return sprintf(
                'between(t\\,%s\\,%s)',
                self::formatTime($window['start']),
                self::formatTime($window['end']),
            );
        }, self::sampledWindows($durationSeconds, $clipSeconds, $clipCount));

        return "select='".implode('+', $ranges)."',setpts=N/FRAME_RATE/TB";
    }

    private static function formatTime(float $value): string
    {
        $formatted = number_format($value, 3, '.', '');

        return rtrim(rtrim($formatted, '0'), '.');
    }
}

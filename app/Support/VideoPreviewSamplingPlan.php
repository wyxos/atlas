<?php

namespace App\Support;

class VideoPreviewSamplingPlan
{
    /**
     * @return list<array{start: float, end: float}>
     */
    public static function windows(
        float $startSecond,
        float $takeSeconds,
        float $skipSeconds,
        int $repeatCount,
    ): array {
        $startSecond = max(0, $startSecond);
        $takeSeconds = max(0.1, $takeSeconds);
        $skipSeconds = max(0, $skipSeconds);
        $repeatCount = max(1, $repeatCount);

        $windows = [];
        $cursor = $startSecond;

        for ($index = 0; $index < $repeatCount; $index++) {
            $windows[] = [
                'start' => $cursor,
                'end' => $cursor + $takeSeconds,
            ];

            $cursor += $takeSeconds + $skipSeconds;
        }

        return $windows;
    }

    public static function selectFilter(
        float $startSecond,
        float $takeSeconds,
        float $skipSeconds,
        int $repeatCount,
    ): string {
        $windows = self::windows($startSecond, $takeSeconds, $skipSeconds, $repeatCount);

        $ranges = array_map(function (array $window): string {
            return sprintf(
                'between(t\\,%s\\,%s)',
                self::formatTime($window['start']),
                self::formatTime($window['end']),
            );
        }, $windows);

        return "select='".implode('+', $ranges)."',setpts=N/FRAME_RATE/TB";
    }

    private static function formatTime(float $value): string
    {
        $formatted = number_format($value, 3, '.', '');

        return rtrim(rtrim($formatted, '0'), '.');
    }
}

<?php

use App\Support\VideoPreviewSamplingPlan;

it('uses the whole file for videos at or below the short preview threshold', function () {
    expect(VideoPreviewSamplingPlan::selectFilterForDuration(60, 60, 5, 10))->toBeNull();
});

it('builds ten evenly distributed five second windows from the first half of long videos', function () {
    $windows = VideoPreviewSamplingPlan::sampledWindows(120, 5, 10);

    expect($windows)->toHaveCount(10)
        ->and($windows[0])->toBe(['start' => 0.0, 'end' => 5.0])
        ->and($windows[9])->toBe(['start' => 55.0, 'end' => 60.0])
        ->and(round($windows[4]['start'], 3))->toBe(24.444)
        ->and(round($windows[5]['start'], 3))->toBe(30.556);
});

it('builds an ffmpeg select filter for long video preview windows', function () {
    expect(VideoPreviewSamplingPlan::selectFilterForDuration(120, 60, 5, 10))
        ->toBe("select='between(t\\,0\\,5)+between(t\\,6.111\\,11.111)+between(t\\,12.222\\,17.222)+between(t\\,18.333\\,23.333)+between(t\\,24.444\\,29.444)+between(t\\,30.556\\,35.556)+between(t\\,36.667\\,41.667)+between(t\\,42.778\\,47.778)+between(t\\,48.889\\,53.889)+between(t\\,55\\,60)',setpts=N/FRAME_RATE/TB");
});

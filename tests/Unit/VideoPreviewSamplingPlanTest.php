<?php

use App\Support\VideoPreviewSamplingPlan;

it('builds take-skip windows for sampled video previews', function () {
    expect(VideoPreviewSamplingPlan::windows(1, 5, 10, 5))->toBe([
        ['start' => 1.0, 'end' => 6.0],
        ['start' => 16.0, 'end' => 21.0],
        ['start' => 31.0, 'end' => 36.0],
        ['start' => 46.0, 'end' => 51.0],
        ['start' => 61.0, 'end' => 66.0],
    ]);
});

it('builds an ffmpeg select filter from sampled preview windows', function () {
    expect(VideoPreviewSamplingPlan::selectFilter(1, 5, 10, 3))
        ->toBe("select='between(t\\,1\\,6)+between(t\\,16\\,21)+between(t\\,31\\,36)',setpts=N/FRAME_RATE/TB");
});

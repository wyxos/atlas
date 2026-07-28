<?php

use App\Enums\DownloadTransferStatus;
use App\Enums\LibraryScanMediaTask as LibraryScanMediaTaskType;
use App\Enums\MediaProcessorOperation;
use App\Enums\MediaProcessorTaskStatus;
use App\Models\DownloadTransfer;
use App\Models\File;
use App\Models\LibraryScanMediaTask;
use App\Models\MediaProcessorTask;
use App\Support\FileProcessingFailure;
use Tests\TestCase;

uses(TestCase::class);

function fileWithProcessingRelations(
    ?DownloadTransfer $downloadTransfer = null,
    ?LibraryScanMediaTask $libraryTask = null,
    ?MediaProcessorTask $processorTask = null,
): File {
    $file = new File;
    $file->setRelation('latestDownloadTransfer', $downloadTransfer);
    $file->setRelation('latestLibraryConversionTask', $libraryTask);
    $file->setRelation('latestStandaloneConversionMediaProcessorTask', $processorTask);

    return $file;
}

it('reports a failed download before conversion starts', function () {
    $file = fileWithProcessingRelations(downloadTransfer: new DownloadTransfer([
        'status' => DownloadTransferStatus::FAILED,
        'error' => 'The source video timed out.',
        'failed_at' => now(),
    ]));

    expect(FileProcessingFailure::state($file))->toMatchArray([
        'stage' => 'download',
        'title' => 'Download failed',
        'message' => 'The source video timed out.',
        'error_code' => null,
    ]);
});

it('does not report a resolved download failure', function () {
    $file = fileWithProcessingRelations(downloadTransfer: new DownloadTransfer([
        'status' => DownloadTransferStatus::COMPLETED,
        'error' => null,
        'finished_at' => now(),
    ]));

    expect(FileProcessingFailure::state($file))->toBeNull();
});

it('reports a failed library video conversion', function () {
    $file = fileWithProcessingRelations(libraryTask: new LibraryScanMediaTask([
        'type' => LibraryScanMediaTaskType::TASK_VIDEO_STREAMABLE,
        'status' => LibraryScanMediaTaskType::STATUS_FAILED,
        'error_code' => 'ffmpeg_failed',
        'error_message' => 'ffmpeg could not decode the input.',
        'updated_at' => now(),
    ]));

    expect(FileProcessingFailure::state($file))->toMatchArray([
        'stage' => 'video_conversion',
        'title' => 'Video conversion failed',
        'message' => 'ffmpeg could not decode the input.',
        'error_code' => 'ffmpeg_failed',
    ]);
});

it('reports a failed standalone audio conversion', function () {
    $file = fileWithProcessingRelations(processorTask: new MediaProcessorTask([
        'operation' => MediaProcessorOperation::AUDIO_NORMALIZATION,
        'status' => MediaProcessorTaskStatus::FAILED,
        'error_code' => 'processor_failed',
        'error_message' => 'The remote processor rejected the audio stream.',
        'updated_at' => now(),
    ]));

    expect(FileProcessingFailure::state($file))->toMatchArray([
        'stage' => 'audio_conversion',
        'title' => 'Audio conversion failed',
        'message' => 'The remote processor rejected the audio stream.',
        'error_code' => 'processor_failed',
    ]);
});

<?php

namespace App\Console\Commands;

use App\Models\File;
use App\Models\FileMetadata;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class BackfillFileDimensions extends Command
{
    protected $signature = 'atlas:backfill-file-dimensions {fileId : File ID} {--only-missing : Only set width/height if missing in FileMetadata}';

    protected $description = 'Backfill FileMetadata width/height from the downloaded file on disk';

    public function handle(): int
    {
        $fileId = (int) $this->argument('fileId');
        if ($fileId <= 0) {
            $this->error('Invalid fileId.');

            return self::FAILURE;
        }

        /** @var File|null $file */
        $file = File::query()->find($fileId);
        if (! $file) {
            $this->error("File {$fileId} not found.");

            return self::FAILURE;
        }

        if (! $file->downloaded || ! $file->path) {
            $this->error('File is not downloaded (missing path).');

            return self::FAILURE;
        }

        $disk = Storage::disk(config('downloads.disk'));
        if (! $disk->exists($file->path)) {
            $this->error("File missing on disk at path: {$file->path}");

            return self::FAILURE;
        }

        $absolutePath = $disk->path($file->path);
        $size = @getimagesize($absolutePath);
        if (! is_array($size)) {
            $this->error('Unable to read image dimensions (is this an image file?).');

            return self::FAILURE;
        }

        $width = isset($size[0]) ? (int) $size[0] : 0;
        $height = isset($size[1]) ? (int) $size[1] : 0;
        if ($width <= 0 || $height <= 0) {
            $this->error('Invalid dimensions returned from getimagesize().');

            return self::FAILURE;
        }

        $meta = FileMetadata::query()->firstOrNew(['file_id' => $file->id]);
        $payload = is_array($meta->payload) ? $meta->payload : [];

        if ($this->option('only-missing') && isset($payload['width'], $payload['height'])) {
            $this->info('FileMetadata already has width/height; nothing changed (use without --only-missing to overwrite).');

            return self::SUCCESS;
        }

        $payload['width'] = $width;
        $payload['height'] = $height;

        $meta->payload = $payload;
        $meta->save();

        $this->info("Set width={$width}, height={$height} for file {$file->id}.");

        return self::SUCCESS;
    }
}

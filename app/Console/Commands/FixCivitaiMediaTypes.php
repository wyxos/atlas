<?php

namespace App\Console\Commands;

use App\Models\File;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Mime\MimeTypes;

class FixCivitaiMediaTypes extends Command
{
    protected $signature = 'media:fix-civitai-types {--dry-run : Report mismatches without changing files}';

    protected $description = 'Correct mislabeled CivetAI media files that are stored locally with incorrect extensions.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $disk = Storage::disk('atlas_app');

        $files = File::query()
            ->where('source', 'CivitAI')
            ->where('url', 'like', '%.mp4%')
            ->whereNotNull('path')
            ->get();

        if ($files->isEmpty()) {
            $this->info('No CivetAI files with mp4 URLs found.');

            return self::SUCCESS;
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE) ?: null;
        $updated = 0;

        foreach ($files as $file) {
            if (! $disk->exists($file->path)) {
                $this->warn("Skipping file {$file->id}: {$file->path} is missing");
                continue;
            }

            $contents = $disk->get($file->path);
            $mime = $finfo ? (finfo_buffer($finfo, $contents) ?: null) : null;
            $mime ??= $file->mime_type;

            if (! $mime || ! str_starts_with($mime, 'image/')) {
                continue;
            }

            $extension = MimeTypes::getDefault()->getExtensions($mime)[0] ?? match ($mime) {
                'image/webp' => 'webp',
                default => null,
            };

            if (! $extension) {
                continue;
            }

            $currentExt = strtolower((string) pathinfo($file->filename ?? '', PATHINFO_EXTENSION));
            if ($currentExt === $extension) {
                continue;
            }

            $baseName = $currentExt !== '' && $file->filename
                ? Str::beforeLast($file->filename, '.'.$currentExt)
                : ($file->filename ?: pathinfo($file->path, PATHINFO_FILENAME));

            $newFilename = $baseName.'.'.$extension;
            $newPath = 'downloads/'.$newFilename;

            $this->line("File {$file->id}: {$file->filename} -> {$newFilename}");

            if ($dryRun) {
                continue;
            }

            $disk->move($file->path, $newPath);

            $file->forceFill([
                'filename' => $newFilename,
                'path' => $newPath,
                'mime_type' => $mime,
            ])->saveQuietly();

            try {
                $file->searchable();
            } catch (\Throwable $e) {
                // ignore indexing failures
            }

            $updated++;
        }

        if ($finfo) {
            finfo_close($finfo);
        }

        $message = $dryRun
            ? 'Dry run complete.'
            : "Updated {$updated} file(s).";

        $this->info($message);

        return self::SUCCESS;
    }
}


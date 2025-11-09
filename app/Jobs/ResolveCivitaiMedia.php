<?php

namespace App\Jobs;

use App\Events\CivitaiMediaResolved;
use App\Models\File;
use App\Support\CivitaiMediaResolver;
use App\Support\FilePreviewUrl;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ResolveCivitaiMedia implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public File $file
    ) {}

    public function handle(CivitaiMediaResolver $resolver): void
    {
        if (strcasecmp((string) $this->file->source, 'CivitAI') !== 0) {
            event(new CivitaiMediaResolved(
                $this->file->id,
                false,
                false,
                false,
                'Unsupported file source.'
            ));

            return;
        }

        $resolution = $resolver->resolveAndUpdate($this->file);

        $this->file->refresh();

        $preview = FilePreviewUrl::for($this->file) ?? $this->file->thumbnail_url ?? $this->file->url;
        $type = $this->detectType($this->file->mime_type);

        event(new CivitaiMediaResolved(
            $this->file->id,
            $resolution->found,
            $resolution->notFound,
            $resolution->updated,
            null,
            $preview,
            $this->file->url,
            $this->file->thumbnail_url,
            $this->file->url,
            $this->file->thumbnail_url ?? $preview,
            $this->file->mime_type,
            $type
        ));
    }

    protected function detectType(?string $mime): string
    {
        $mime = (string) $mime;

        if (str_starts_with($mime, 'video/')) {
            return 'video';
        }

        if (str_starts_with($mime, 'image/')) {
            return 'image';
        }

        if (str_starts_with($mime, 'audio/')) {
            return 'audio';
        }

        return 'other';
    }
}

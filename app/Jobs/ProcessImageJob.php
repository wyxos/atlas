<?php

namespace App\Jobs;

class ProcessImageJob extends BaseProcessJob
{
    protected function process(): void
    {
        $file = $this->file->fresh(['metadata']) ?? $this->file;
        if (! $file || ! $file->exists) {
            return;
        }

        // Generate a 450px wide thumbnail (preserving aspect ratio) stored on the atlas_app disk
        $path = $file->path;
        if (! $path) {
            return;
        }

        $existingThumbPath = $file->thumbnail_path;
        $metadataPayload = $file->metadata?->payload ?? [];
        $hasDimensions = isset($metadataPayload['thumbnail_width'], $metadataPayload['thumbnail_height']);
        if ($existingThumbPath && $hasDimensions) {
            try {
                if (\Illuminate\Support\Facades\Storage::disk('atlas_app')->exists($existingThumbPath)) {
                    return;
                }
            } catch (\Throwable $e) {
                report($e);
            }
        }

        try {
            $disk = $this->disk;
            $sourceContents = \Illuminate\Support\Facades\Storage::disk($disk)->get($path);
            if (! is_string($sourceContents) || $sourceContents === '') {
                return;
            }

            // Create image resource from bytes (supports jpeg/png/webp/gif if GD compiled with support)
            $src = @imagecreatefromstring($sourceContents);
            if (! $src) {
                return;
            }

            $width = imagesx($src);
            $height = imagesy($src);
            if (! $width || ! $height) {
                imagedestroy($src);

                return;
            }

            $targetWidth = 450;
            if ($width <= $targetWidth) {
                // Small images: keep original size
                $newWidth = $width;
                $newHeight = $height;
            } else {
                $ratio = $height / $width;
                $newWidth = (int) $targetWidth;
                $newHeight = max(1, (int) round($newWidth * $ratio));
            }

            $thumb = imagecreatetruecolor($newWidth, $newHeight);
            // Preserve transparency for PNG and GIF
            imagealphablending($thumb, false);
            imagesavealpha($thumb, true);
            $transparent = imagecolorallocatealpha($thumb, 255, 255, 255, 127);
            imagefilledrectangle($thumb, 0, 0, $newWidth, $newHeight, $transparent);

            imagecopyresampled($thumb, $src, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

            // Encode thumbnail to webp if possible, else jpeg
            $thumbBytes = null;
            ob_start();
            if (function_exists('imagewebp')) {
                // quality 80
                imagewebp($thumb, null, 80);
            } else {
                // Fallback to JPEG with quality 85 (no alpha)
                // If original had alpha, flatten onto white background
                if (imageistruecolor($thumb) && imagecolortransparent($thumb) !== -1) {
                    // convert to truecolor without alpha
                }
                imagejpeg($thumb, null, 85);
            }
            $thumbBytes = ob_get_clean();

            imagedestroy($thumb);
            imagedestroy($src);

            if (! is_string($thumbBytes) || $thumbBytes === '') {
                return;
            }

            // Determine destination path under atlas_app/thumbnails/<sha1>.<ext>
            $hash = sha1($thumbBytes);
            $ext = function_exists('imagewebp') ? 'webp' : 'jpg';
            $destPath = 'thumbnails/'.$hash.'.'.$ext;

            // Write to atlas_app disk
            \Illuminate\Support\Facades\Storage::disk('atlas_app')->put($destPath, $thumbBytes);

            // Update thumbnail_path on the persistent File record
            $file->thumbnail_path = $destPath;

            // Persist original/thumbnail dimensions in metadata for downstream consumers (e.g. CivitAI classes)
            $metadata = $file->metadata;

            if (! $metadata) {
                $metadata = new \App\Models\FileMetadata([
                    'file_id' => $file->id,
                    'payload' => [],
                    'is_review_required' => false,
                    'is_extracted' => true,
                ]);
            }

            $payload = $metadata->payload ?? [];
            $payload['width'] = $width;
            $payload['height'] = $height;
            $payload['thumbnail_width'] = $newWidth;
            $payload['thumbnail_height'] = $newHeight;

            $metadata->payload = $payload;
            $metadata->save();

            if (! $file->metadata) {
                $file->metadata()->save($metadata);
            }

            $file->save();
        } catch (\Throwable $e) {
            report($e);

            return;
        }
    }
}

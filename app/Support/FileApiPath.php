<?php

namespace App\Support;

final class FileApiPath
{
    public static function downloaded(int $fileId): string
    {
        return "/api/files/{$fileId}/downloaded";
    }

    public static function preview(int $fileId): string
    {
        return "/api/files/{$fileId}/preview";
    }

    public static function poster(int $fileId): string
    {
        return "/api/files/{$fileId}/poster";
    }

    public static function serve(int $fileId): string
    {
        return "/api/files/{$fileId}/serve";
    }

    public static function icon(int $fileId): string
    {
        return "/api/files/{$fileId}/icon";
    }
}

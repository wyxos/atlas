<?php

namespace App\Http\Controllers;

use App\Models\File;
use App\Services\FileStorageResponseService;

class FileStreamableVideoController extends Controller
{
    public function __invoke(File $file, FileStorageResponseService $fileStorageResponses)
    {
        return $fileStorageResponses->serveStreamableVideo($file);
    }
}

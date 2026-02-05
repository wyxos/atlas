<?php

namespace App\Http\Controllers;

use App\Services\ExtensionPackageService;

class ExtensionDownloadController extends Controller
{
    public function download(ExtensionPackageService $service)
    {
        $package = $service->package();

        return response()->download(
            $package['path'],
            $package['filename'],
            ['Content-Type' => 'application/zip']
        );
    }
}

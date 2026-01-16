<?php

namespace App\Http\Controllers;

use App\Listings\DownloadTransferListing;
use Illuminate\Http\JsonResponse;

class DownloadTransfersController extends Controller
{
    public function index(DownloadTransferListing $listing): JsonResponse
    {
        return response()->json($listing->handle());
    }
}

<?php

namespace App\Http\Controllers;

use App\Http\Resources\DownloadTransferResource;
use App\Models\DownloadTransfer;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class DownloadTransfersController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $transfers = DownloadTransfer::query()
            ->with('file')
            ->latest('id')
            ->paginate(50);

        return DownloadTransferResource::collection($transfers);
    }
}

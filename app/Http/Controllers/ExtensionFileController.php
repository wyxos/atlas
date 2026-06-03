<?php

namespace App\Http\Controllers;

use App\Models\File;
use App\Services\DownloadedFileClearService;
use App\Services\Extension\ExtensionRequestAuthenticator;
use App\Services\ExtensionApiKeyService;
use App\Services\FileReactionService;
use App\Services\Library\LibraryIndexSyncDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExtensionFileController extends Controller
{
    public function __construct(private readonly ExtensionRequestAuthenticator $extensionAuthenticator) {}

    public function destroy(
        Request $request,
        File $file,
        ExtensionApiKeyService $extensionApiKey,
        DownloadedFileClearService $downloadedFileClearService,
        FileReactionService $fileReactionService,
        LibraryIndexSyncDispatcher $libraryIndexSyncDispatcher,
    ): JsonResponse {
        $user = $this->extensionAuthenticator->resolveUser($request, $extensionApiKey);
        if (! $user) {
            return response()->json([
                'message' => 'Invalid extension API key.',
            ], 401);
        }

        $validated = $request->validate([
            'also_from_disk' => ['sometimes', 'boolean'],
            'also_delete_record' => ['sometimes', 'boolean'],
        ]);

        $alsoFromDisk = (bool) ($validated['also_from_disk'] ?? false);
        $alsoDeleteRecord = (bool) ($validated['also_delete_record'] ?? false);
        $deletedFileId = (int) $file->id;

        if ($alsoFromDisk) {
            $downloadedFileClearService->clear($file);
        }

        if ($alsoDeleteRecord || ! $alsoFromDisk) {
            $fileReactionService->clearMany([$file], false);
            $file->delete();
            $libraryIndexSyncDispatcher->deleteFiles([$deletedFileId]);

            return response()->json([
                'deleted' => true,
                'file_id' => $deletedFileId,
                'message' => $alsoFromDisk
                    ? 'File deleted from disk and record deleted.'
                    : 'File deleted successfully.',
            ]);
        }

        return response()->json([
            'deleted' => false,
            'file_id' => $deletedFileId,
            'message' => 'File deleted from disk. Record kept.',
        ]);
    }
}

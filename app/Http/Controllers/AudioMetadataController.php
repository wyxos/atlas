<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReviewAudioMetadataProposalRequest;
use App\Http\Requests\StartAudioMetadataRunRequest;
use App\Models\AudioMetadataProposal;
use App\Models\AudioMetadataRun;
use App\Models\File;
use App\Services\Audio\AudioMetadataFileRestorer;
use App\Services\Audio\AudioMetadataProposalPayload;
use App\Services\Audio\AudioMetadataProposalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AudioMetadataController extends Controller
{
    public function store(StartAudioMetadataRunRequest $request, AudioMetadataProposalService $metadata): JsonResponse
    {
        $run = $metadata->startBatch($request->user(), $request->validated());

        return response()->json([
            'run' => AudioMetadataProposalPayload::run($run),
        ], 202);
    }

    public function storeForFile(Request $request, File $file, AudioMetadataProposalService $metadata): JsonResponse
    {
        abort_unless(str_starts_with((string) $file->mime_type, 'audio/'), 404);

        $run = $metadata->startSingle($request->user(), $file);
        $proposal = $run->proposals()->latest('id')->first();

        return response()->json([
            'run' => AudioMetadataProposalPayload::run($run),
            'proposal' => AudioMetadataProposalPayload::proposal($proposal),
        ], 202);
    }

    public function showRun(Request $request, AudioMetadataRun $audioMetadataRun): JsonResponse
    {
        $this->authorizeRun($request, $audioMetadataRun);

        $proposals = $audioMetadataRun->proposals()
            ->latest('id')
            ->limit(50)
            ->get()
            ->map(fn (AudioMetadataProposal $proposal): ?array => AudioMetadataProposalPayload::proposal($proposal))
            ->values();

        return response()->json([
            'run' => AudioMetadataProposalPayload::run($audioMetadataRun),
            'proposals' => $proposals,
        ]);
    }

    public function latestForFile(Request $request, File $file, AudioMetadataProposalService $metadata): JsonResponse
    {
        abort_unless(str_starts_with((string) $file->mime_type, 'audio/'), 404);

        return response()->json([
            'proposal' => AudioMetadataProposalPayload::proposal(
                $metadata->latestProposalForFile($request->user(), $file)
            ),
        ]);
    }

    public function restoreFromFile(Request $request, File $file, AudioMetadataFileRestorer $restorer): JsonResponse
    {
        abort_unless(str_starts_with((string) $file->mime_type, 'audio/'), 404);

        $result = $restorer->restore($file);

        return response()->json([
            'status' => 'restored',
            'values' => $result['values'],
            'ingested' => $result['ingested'],
        ]);
    }

    public function review(
        ReviewAudioMetadataProposalRequest $request,
        AudioMetadataProposal $audioMetadataProposal,
        AudioMetadataProposalService $metadata,
    ): JsonResponse {
        $this->authorizeProposal($request, $audioMetadataProposal);

        $validated = $request->validated();
        $proposal = $validated['action'] === 'ignore'
            ? $metadata->ignore($audioMetadataProposal, $request->user())
            : $metadata->apply($audioMetadataProposal, $request->user(), $validated['fields'] ?? []);

        return response()->json([
            'proposal' => AudioMetadataProposalPayload::proposal($proposal),
        ]);
    }

    private function authorizeRun(Request $request, AudioMetadataRun $run): void
    {
        abort_unless((int) $run->user_id === (int) $request->user()->id, 403);
    }

    private function authorizeProposal(Request $request, AudioMetadataProposal $proposal): void
    {
        $proposal->loadMissing('run');

        abort_unless((int) $proposal->run?->user_id === (int) $request->user()->id, 403);
    }
}

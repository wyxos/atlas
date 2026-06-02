<?php

namespace App\Services\Audio;

use App\Models\AudioMetadataProposal;
use App\Models\AudioMetadataRun;

class AudioMetadataProposalPayload
{
    /**
     * @return array<string, mixed>
     */
    public static function run(AudioMetadataRun $run): array
    {
        return [
            'id' => (int) $run->id,
            'scope' => (string) $run->scope,
            'source_filter' => (string) $run->source_filter,
            'status' => (string) $run->status,
            'total_files' => (int) $run->total_files,
            'processed_files' => (int) $run->processed_files,
            'proposal_count' => (int) $run->proposal_count,
            'failed_files' => (int) $run->failed_files,
            'error' => $run->error,
            'created_at' => $run->created_at?->toIso8601String(),
            'started_at' => $run->started_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function proposal(?AudioMetadataProposal $proposal): ?array
    {
        if (! $proposal) {
            return null;
        }

        return [
            'id' => (int) $proposal->id,
            'file_id' => (int) $proposal->file_id,
            'run_id' => (int) $proposal->audio_metadata_run_id,
            'provider' => (string) $proposal->provider,
            'status' => (string) $proposal->status,
            'confidence' => (int) $proposal->confidence,
            'current_values' => $proposal->current_values ?? [],
            'proposed_values' => $proposal->proposed_values ?? [],
            'changes' => $proposal->changes ?? [],
            'evidence' => $proposal->evidence ?? [],
            'created_at' => $proposal->created_at?->toIso8601String(),
            'reviewed_at' => $proposal->reviewed_at?->toIso8601String(),
            'applied_at' => $proposal->applied_at?->toIso8601String(),
            'ignored_at' => $proposal->ignored_at?->toIso8601String(),
        ];
    }
}

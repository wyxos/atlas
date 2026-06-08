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
        $options = is_array($run->options) ? $run->options : [];
        $progress = is_array($options['progress'] ?? null) ? $options['progress'] : [];

        return [
            'id' => (int) $run->id,
            'scope' => (string) $run->scope,
            'source_filter' => (string) $run->source_filter,
            'status' => (string) $run->status,
            'total_files' => (int) $run->total_files,
            'processed_files' => (int) $run->processed_files,
            'proposal_count' => (int) $run->proposal_count,
            'failed_files' => (int) $run->failed_files,
            'current_file_id' => self::nullableInteger($progress['file_id'] ?? null),
            'current_step' => self::nullableString($progress['step'] ?? null),
            'current_step_label' => self::nullableString($progress['label'] ?? null),
            'error' => $run->error,
            'created_at' => $run->created_at?->toIso8601String(),
            'started_at' => $run->started_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function proposal(?AudioMetadataProposal $proposal, bool $compact = false): ?array
    {
        if (! $proposal) {
            return null;
        }

        $payload = [
            'id' => (int) $proposal->id,
            'file_id' => (int) $proposal->file_id,
            'run_id' => (int) $proposal->audio_metadata_run_id,
            'provider' => (string) $proposal->provider,
            'status' => (string) $proposal->status,
            'confidence' => (int) $proposal->confidence,
            'current_values' => $proposal->current_values ?? [],
            'proposed_values' => $proposal->proposed_values ?? [],
            'changes' => $proposal->changes ?? [],
            'field_options' => is_array(data_get($proposal->evidence, 'field_options'))
                ? data_get($proposal->evidence, 'field_options')
                : [],
            'evidence' => $proposal->evidence ?? [],
            'created_at' => $proposal->created_at?->toIso8601String(),
            'reviewed_at' => $proposal->reviewed_at?->toIso8601String(),
            'applied_at' => $proposal->applied_at?->toIso8601String(),
            'ignored_at' => $proposal->ignored_at?->toIso8601String(),
        ];

        if (! $compact) {
            return $payload;
        }

        $payload['field_options'] = [];
        $payload['evidence'] = self::compactEvidence($proposal->evidence ?? []);
        $payload['is_compact'] = true;

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $evidence
     * @return array<string, mixed>
     */
    private static function compactEvidence(array $evidence): array
    {
        return array_filter([
            'source' => self::nullableString($evidence['source'] ?? null),
            'acoustid_score' => is_numeric($evidence['acoustid_score'] ?? null)
                ? (int) $evidence['acoustid_score']
                : null,
            'duration_delta_seconds' => is_numeric($evidence['duration_delta_seconds'] ?? null)
                ? (int) $evidence['duration_delta_seconds']
                : null,
            'matched_existing_fields' => is_array($evidence['matched_existing_fields'] ?? null)
                ? array_values($evidence['matched_existing_fields'])
                : null,
            'discogs_release_url' => self::nullableString($evidence['discogs_release_url'] ?? null),
            'discogs_master_url' => self::nullableString($evidence['discogs_master_url'] ?? null),
            'discogs_release_id' => self::nullableString($evidence['discogs_release_id'] ?? null),
            'discogs_master_id' => self::nullableString($evidence['discogs_master_id'] ?? null),
            'musicbrainz_release_id' => self::nullableString($evidence['musicbrainz_release_id'] ?? null),
            'musicbrainz_recording_id' => self::nullableString($evidence['musicbrainz_recording_id'] ?? null),
            'acoustid_id' => self::nullableString($evidence['acoustid_id'] ?? null),
            'vgmdb_album_id' => self::nullableString($evidence['vgmdb_album_id'] ?? null),
            'vgmdb_album_link' => self::nullableString($evidence['vgmdb_album_link'] ?? null),
            'cover_source' => self::nullableString($evidence['cover_source'] ?? null),
        ], fn (mixed $value): bool => $value !== null && $value !== []);
    }

    private static function nullableInteger(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $value = (int) $value;

        return $value > 0 ? $value : null;
    }

    private static function nullableString(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}

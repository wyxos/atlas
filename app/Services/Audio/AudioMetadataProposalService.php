<?php

namespace App\Services\Audio;

use App\Events\AudioMetadataRunUpdated;
use App\Jobs\GenerateAudioMetadataRun;
use App\Models\AudioMetadataProposal;
use App\Models\AudioMetadataRun;
use App\Models\File;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class AudioMetadataProposalService
{
    private const int BATCH_SIZE = 200;

    public function __construct(
        private readonly AudioMetadataProposalApplier $applier,
        private readonly AudioMetadataProposalGenerator $generator,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     */
    public function startBatch(User $user, array $options): AudioMetadataRun
    {
        $scope = $this->normalizeScope($options['scope'] ?? 'all');
        $sourceFilter = $this->normalizeSourceFilter($options['source_filter'] ?? 'all');
        $totalFiles = (int) $this->audioQuery($sourceFilter, $scope)->count();

        $run = AudioMetadataRun::query()->create([
            'user_id' => $user->id,
            'scope' => $scope,
            'source_filter' => $sourceFilter,
            'status' => 'pending',
            'total_files' => $totalFiles,
            'options' => [
                'scope' => $scope,
                'source_filter' => $sourceFilter,
            ],
        ]);

        GenerateAudioMetadataRun::dispatch($run->id);

        return $run->fresh();
    }

    public function startSingle(User $user, File $file): AudioMetadataRun
    {
        $run = AudioMetadataRun::query()->create([
            'user_id' => $user->id,
            'scope' => 'single',
            'source_filter' => $this->isSpotifyFile($file) ? 'spotify' : 'local',
            'status' => 'pending',
            'total_files' => 1,
            'options' => [
                'file_id' => (int) $file->id,
            ],
        ]);

        GenerateAudioMetadataRun::dispatch($run->id);

        return $run->fresh('proposals');
    }

    public function processRun(int $runId): void
    {
        $run = AudioMetadataRun::query()->find($runId);
        if (! $run || in_array($run->status, ['running', 'completed', 'failed'], true)) {
            return;
        }

        $user = $run->user()->first();
        if (! $user) {
            $run->forceFill([
                'status' => 'failed',
                'finished_at' => now(),
                'error' => 'Metadata run has no user.',
            ])->save();

            return;
        }

        $run->forceFill([
            'status' => 'running',
            'started_at' => $run->started_at ?? now(),
            'error' => null,
        ])->save();
        $this->broadcastRun($run);

        try {
            $this->processFiles($run, $user);

            $run->refresh()->forceFill([
                'status' => 'completed',
                'finished_at' => now(),
                'options' => $this->clearProgressOptions($run),
            ])->save();
            $this->broadcastRun($run, $this->latestRunProposal($run));
        } catch (\Throwable $exception) {
            report($exception);

            $run->refresh()->forceFill([
                'status' => 'failed',
                'finished_at' => now(),
                'error' => $exception->getMessage(),
                'options' => $this->clearProgressOptions($run),
            ])->save();
            $this->broadcastRun($run);
        }
    }

    /**
     * @param  list<string>  $fields
     */
    public function apply(AudioMetadataProposal $proposal, User $user, array $fields = []): AudioMetadataProposal
    {
        return $this->applier->apply($proposal, $user, $fields);
    }

    public function ignore(AudioMetadataProposal $proposal, User $user): AudioMetadataProposal
    {
        return $this->applier->ignore($proposal, $user);
    }

    public function latestProposalForFile(User $user, File $file): ?AudioMetadataProposal
    {
        return AudioMetadataProposal::query()
            ->where('file_id', $file->id)
            ->where('status', 'pending')
            ->whereHas('run', fn (Builder $query) => $query->where('user_id', $user->id))
            ->latest('id')
            ->first();
    }

    private function processFiles(AudioMetadataRun $run, User $user): void
    {
        $this->runAudioQuery($run)
            ->select([
                'id',
                'source',
                'source_id',
                'url',
                'referrer_url',
                'path',
                'filename',
                'mime_type',
                'title',
                'preview_url',
                'preview_path',
                'poster_path',
                'listing_metadata',
            ])
            ->with(['metadata', 'artists', 'albums.defaultCover'])
            ->chunkById(self::BATCH_SIZE, function (Collection $files) use ($run, $user): void {
                foreach ($files as $file) {
                    $this->processFile($run, $file, $user);
                }
            });
    }

    private function processFile(AudioMetadataRun $run, File $file, User $user): void
    {
        try {
            $this->updateRunProgress($run, $file, 'metadata', 'Reading current metadata');

            $proposal = $this->generator->generate(
                $run,
                $file,
                $user,
                function (string $step, string $label) use ($run, $file): void {
                    $this->updateRunProgress($run, $file, $step, $label);
                },
            );

            $run->increment('processed_files');
            if ($proposal) {
                $run->increment('proposal_count');
            }

            $this->broadcastRun($run->refresh(), $proposal);
        } catch (\Throwable $exception) {
            report($exception);

            $run->increment('processed_files');
            $run->increment('failed_files');

            $this->broadcastRun($run->refresh());
        }
    }

    private function runAudioQuery(AudioMetadataRun $run): Builder
    {
        if ((string) $run->scope === 'single') {
            $fileId = (int) data_get($run->options ?? [], 'file_id');

            return File::query()
                ->whereKey($fileId)
                ->where('mime_type', 'like', 'audio/%');
        }

        return $this->audioQuery((string) $run->source_filter, (string) $run->scope);
    }

    private function audioQuery(string $sourceFilter, string $scope): Builder
    {
        $query = File::query()->where('mime_type', 'like', 'audio/%');

        if ($sourceFilter === 'spotify') {
            $query->whereRaw("LOWER(COALESCE(source, '')) = ?", ['spotify']);
        } elseif ($sourceFilter === 'local') {
            $query->where(function (Builder $query): void {
                $query->whereNull('source')
                    ->orWhereRaw('LOWER(source) <> ?', ['spotify']);
            });
        }

        if ($scope === 'missing_metadata') {
            $query->where(function (Builder $query): void {
                $query->whereNull('title')
                    ->orWhere('title', '')
                    ->orWhereDoesntHave('artists')
                    ->orWhereDoesntHave('albums');
            });
        } elseif ($scope === 'missing_covers') {
            $query->whereNull('preview_url')
                ->whereNull('preview_path')
                ->whereNull('poster_path')
                ->whereDoesntHave('albums.defaultCover');
        }

        return $query;
    }

    private function isSpotifyFile(File $file): bool
    {
        return mb_strtolower(trim((string) $file->source)) === 'spotify';
    }

    private function normalizeScope(mixed $scope): string
    {
        return in_array($scope, ['all', 'missing_metadata', 'missing_covers'], true) ? $scope : 'all';
    }

    private function normalizeSourceFilter(mixed $sourceFilter): string
    {
        return in_array($sourceFilter, ['all', 'local', 'spotify'], true) ? $sourceFilter : 'all';
    }

    private function latestRunProposal(AudioMetadataRun $run): ?AudioMetadataProposal
    {
        return $run->proposals()->latest('id')->first();
    }

    private function broadcastRun(AudioMetadataRun $run, ?AudioMetadataProposal $proposal = null): void
    {
        AudioMetadataRunUpdated::dispatch($run->id, [
            'run' => AudioMetadataProposalPayload::run($run),
            'proposal' => AudioMetadataProposalPayload::proposal($proposal),
        ]);
    }

    private function updateRunProgress(AudioMetadataRun $run, File $file, string $step, string $label): void
    {
        $run->forceFill([
            'options' => $this->progressOptions($run, $file, $step, $label),
        ])->save();

        $this->broadcastRun($run->refresh());
    }

    /**
     * @return array<string, mixed>
     */
    private function progressOptions(AudioMetadataRun $run, File $file, string $step, string $label): array
    {
        $options = is_array($run->options) ? $run->options : [];
        $options['progress'] = [
            'file_id' => (int) $file->id,
            'step' => $step,
            'label' => $label,
        ];

        return $options;
    }

    /**
     * @return array<string, mixed>
     */
    private function clearProgressOptions(AudioMetadataRun $run): array
    {
        $options = is_array($run->options) ? $run->options : [];
        unset($options['progress']);

        return $options;
    }
}

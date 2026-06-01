<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ActionType;
use App\Enums\BlacklistPreviewedCountMode;
use App\Models\Container;
use App\Models\File;
use App\Models\FileModerationAction;
use App\Models\ModerationRule;
use App\Services\FileBlacklistService;
use App\Services\FilePreviewService;
use App\Services\Moderation\FilePromptResolver;
use App\Services\Moderation\Moderator;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class CleanFavoritedDownloadedModerationMatches extends Command
{
    protected $signature = 'atlas:clean-favorited-downloaded-moderation-matches
        {--date= : Downloaded date to scan, in YYYY-MM-DD form}
        {--reaction=love : Reaction type that marks a favorite}
        {--chunk=500 : Number of candidate file rows to scan per chunk}
        {--dry-run : Report aggregate matches without mutating rows}
        {--force : Apply changes without an interactive confirmation}';

    protected $description = 'Apply blacklist/moderation rules to favorited files downloaded on a given date';

    public function __construct(
        private readonly FilePromptResolver $promptResolver,
        private readonly FileBlacklistService $blacklistService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $date = trim((string) $this->option('date'));
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $this->error('Provide --date in YYYY-MM-DD form.');

            return self::FAILURE;
        }

        $reactionType = trim((string) $this->option('reaction'));
        if ($reactionType === '') {
            $this->error('Provide a non-empty --reaction value.');

            return self::FAILURE;
        }

        $chunkSize = max(1, min(5000, (int) $this->option('chunk')));
        $timezone = (string) config('app.timezone', 'UTC');
        $start = CarbonImmutable::createFromFormat('!Y-m-d', $date, $timezone);

        if (! $start instanceof CarbonImmutable) {
            $this->error('Unable to parse --date.');

            return self::FAILURE;
        }

        $end = $start->addDay();
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');

        if (! $dryRun && ! $force && ! $this->confirm('Apply this production cleanup?')) {
            $this->warn('Cleanup cancelled.');

            return self::SUCCESS;
        }

        $promptRules = $this->activePromptRules();
        $blacklistedContainers = $this->blacklistedContainers();
        $candidateCount = $this->candidateQuery($start, $end, $reactionType)->count();
        $stats = $this->emptyStats($candidateCount);

        $this->line('Favorited downloaded moderation cleanup:');
        $this->line('- date window: '.$start->toDateTimeString().' <= downloaded_at < '.$end->toDateTimeString().' ('.$timezone.')');
        $this->line('- favorite reaction type: '.$reactionType);
        $this->line('- active prompt moderation rules: '.$promptRules->count());
        $this->line('- blacklisted containers: '.$blacklistedContainers->count());
        $this->line('- candidate files: '.$candidateCount);
        $this->line('- chunk size: '.$chunkSize);
        $this->line('- mode: '.($dryRun ? 'dry-run' : 'apply'));

        if ($promptRules->isEmpty() && $blacklistedContainers->isEmpty()) {
            $this->warn('No active moderation rules or blacklisted containers were found. No rows were changed.');

            return self::SUCCESS;
        }

        $this->candidateQuery($start, $end, $reactionType)
            ->with([
                'containers:id,blacklisted_at,blacklist_previewed_count_mode',
                'metadata:id,file_id,payload',
                'reactions:id,file_id,type',
            ])
            ->orderBy('id')
            ->chunkById($chunkSize, function (Collection $files) use ($promptRules, $blacklistedContainers, $dryRun, &$stats): void {
                $this->processChunk($files, $promptRules, $blacklistedContainers, $dryRun, $stats);
            });

        if ($dryRun) {
            $this->warn('Dry run only. No rows were changed.');
        }

        $this->line('Scanned files: '.$stats['scanned']);
        $this->line('Matched files: '.$stats['matched']);
        $this->line('Prompt-rule matches: '.$stats['prompt_matches']);
        $this->line('Blacklisted-container matches: '.$stats['container_matches']);
        $this->line('Feed-removed level matches: '.$stats['feed_removed_matches']);
        $this->line('Preserve level matches: '.$stats['preserve_matches']);
        $this->line(($dryRun ? 'Reactions that would be removed: ' : 'Reactions removed: ').$stats['reactions_removed']);
        $this->line(($dryRun ? 'Files that would be blacklisted: ' : 'Files blacklisted: ').$stats['blacklisted']);
        $this->line(($dryRun ? 'Moderation action rows that would be recorded: ' : 'Moderation action rows recorded: ').$stats['moderation_actions_recorded']);
        $this->info('Favorited downloaded moderation cleanup complete.');

        return self::SUCCESS;
    }

    private function activePromptRules(): Collection
    {
        return ModerationRule::query()
            ->where('active', true)
            ->where('action_type', ActionType::BLACKLIST)
            ->orderBy('id')
            ->get();
    }

    private function blacklistedContainers(): Collection
    {
        return Container::query()
            ->select(['id', 'blacklist_previewed_count_mode'])
            ->whereNotNull('blacklisted_at')
            ->get()
            ->keyBy('id');
    }

    private function candidateQuery(CarbonImmutable $start, CarbonImmutable $end, string $reactionType): Builder
    {
        return File::query()
            ->select([
                'id',
                'listing_metadata',
                'detail_metadata',
                'path',
                'preview_path',
                'poster_path',
                'downloaded',
                'downloaded_at',
                'download_progress',
                'previewed_count',
                'blacklisted_at',
                'auto_blacklisted',
            ])
            ->where('downloaded_at', '>=', $start)
            ->where('downloaded_at', '<', $end)
            ->whereHas('reactions', fn (Builder $query): Builder => $query->where('type', $reactionType));
    }

    private function processChunk(
        Collection $files,
        Collection $promptRules,
        Collection $blacklistedContainers,
        bool $dryRun,
        array &$stats,
    ): void {
        $groups = [
            'preserve' => collect(),
            'feed_removed' => collect(),
        ];
        $moderationActionRows = [];

        foreach ($files as $file) {
            if (! $file instanceof File) {
                continue;
            }

            $stats['scanned']++;

            $promptMatch = $this->matchingPromptRule($file, $promptRules);
            $containerMatch = $this->matchingContainer($file, $blacklistedContainers);

            if (! $promptMatch instanceof ModerationRule && ! $containerMatch instanceof Container) {
                continue;
            }

            $minimumPreviewedCount = $this->minimumPreviewedCount($promptMatch, $containerMatch);
            $groupKey = $minimumPreviewedCount === FilePreviewService::FEED_REMOVED_PREVIEW_COUNT
                ? 'feed_removed'
                : 'preserve';

            $groups[$groupKey]->push($file);
            $stats['matched']++;
            $stats[$groupKey.'_matches']++;
            $stats['reactions_removed'] += $file->reactions->count();
            $stats['blacklisted']++;

            if ($promptMatch instanceof ModerationRule) {
                $stats['prompt_matches']++;
                $moderationActionRows[(int) $file->id] = [
                    'file_id' => (int) $file->id,
                    'action_type' => ActionType::BLACKLIST,
                    'moderation_rule_id' => (int) $promptMatch->id,
                    'moderation_rule_name' => (string) $promptMatch->name,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            if ($containerMatch instanceof Container) {
                $stats['container_matches']++;
            }
        }

        if ($dryRun || ($groups['preserve']->isEmpty() && $groups['feed_removed']->isEmpty())) {
            $stats['moderation_actions_recorded'] += count($moderationActionRows);

            return;
        }

        if ($moderationActionRows !== []) {
            $stats['moderation_actions_recorded'] += FileModerationAction::query()
                ->insertOrIgnore(array_values($moderationActionRows));
        }

        $this->applyBlacklistGroup($groups['preserve'], null);
        $this->applyBlacklistGroup($groups['feed_removed'], FilePreviewService::FEED_REMOVED_PREVIEW_COUNT);
    }

    private function matchingPromptRule(File $file, Collection $promptRules): ?ModerationRule
    {
        $prompt = $this->promptResolver->resolve($file);
        if ($prompt === null) {
            return null;
        }

        $moderator = new Moderator;
        $firstMatch = null;

        foreach ($promptRules as $rule) {
            if (! $rule instanceof ModerationRule) {
                continue;
            }

            $moderator->loadRule($rule);
            if (! $moderator->check($prompt)) {
                continue;
            }

            $firstMatch ??= $rule;

            if ($rule->blacklist_previewed_count_mode === BlacklistPreviewedCountMode::FEED_REMOVED) {
                return $rule;
            }
        }

        return $firstMatch;
    }

    private function matchingContainer(File $file, Collection $blacklistedContainers): ?Container
    {
        $firstMatch = null;

        foreach ($file->containers as $container) {
            $match = $blacklistedContainers->get((int) $container->id);
            if (! $match instanceof Container) {
                continue;
            }

            $firstMatch ??= $match;

            if ($match->blacklist_previewed_count_mode === BlacklistPreviewedCountMode::FEED_REMOVED) {
                return $match;
            }
        }

        return $firstMatch;
    }

    private function minimumPreviewedCount(?ModerationRule $promptMatch, ?Container $containerMatch): ?int
    {
        foreach ([$promptMatch, $containerMatch] as $match) {
            if ($match?->blacklist_previewed_count_mode === BlacklistPreviewedCountMode::FEED_REMOVED) {
                return FilePreviewService::FEED_REMOVED_PREVIEW_COUNT;
            }
        }

        return null;
    }

    private function applyBlacklistGroup(Collection $files, ?int $minimumPreviewedCount): void
    {
        if ($files->isEmpty()) {
            return;
        }

        $this->blacklistService->apply(
            $files,
            minimumPreviewedCount: $minimumPreviewedCount,
            autoBlacklisted: true,
        );
    }

    private function emptyStats(int $candidateCount): array
    {
        return [
            'candidate_files' => $candidateCount,
            'scanned' => 0,
            'matched' => 0,
            'prompt_matches' => 0,
            'container_matches' => 0,
            'feed_removed_matches' => 0,
            'preserve_matches' => 0,
            'reactions_removed' => 0,
            'blacklisted' => 0,
            'moderation_actions_recorded' => 0,
        ];
    }
}

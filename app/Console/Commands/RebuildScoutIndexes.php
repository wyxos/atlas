<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Laravel\Scout\Searchable;
use ReflectionClass;

class RebuildScoutIndexes extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'scout:rebuild
                            {--no-queue-restart : Do not call queue:restart before re-indexing}
                            {--only=* : Optional list of FQCN models to limit re-indexing}
                            {--dry : Show what would be done without making changes}';

    /**
     * The console command description.
     */
    protected $description = 'Restart the queue, then flush and re-import Scout indexes for all models using Laravel Scout.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        // Discover Scout-enabled models
        $models = $this->discoverSearchableModels();

        // Allow limiting via --only
        $only = collect((array) $this->option('only'))
            ->filter(fn ($v) => is_string($v) && $v !== '')
            ->values();

        if ($only->isNotEmpty()) {
            $models = array_values(array_intersect($models, $only->all()));
        }

        if (empty($models)) {
            $this->warn('No models using Laravel Scout were discovered.');

            return self::SUCCESS;
        }

        $this->line('Discovered Scout-enabled models:');
        foreach ($models as $m) {
            $this->line(' - '.$m);
        }

        if ($this->option('dry')) {
            $this->warn('DRY RUN: no actions will be performed.');

            return self::SUCCESS;
        }

        if (! $this->option('no-queue-restart')) {
            $this->info('Restarting queues (queue:restart)...');
            $this->call('queue:restart');
        } else {
            $this->line('Skipping queue restart (--no-queue-restart).');
        }

        foreach ($models as $model) {
            $this->newLine();
            $this->info("Flushing index for {$model}...");
            $this->call('scout:flush', ['model' => $model]);

            $this->info("Re-importing {$model}...");
            $this->call('scout:import', ['model' => $model]);
        }

        $this->newLine();
        $this->info('Scout re-index complete.');

        return self::SUCCESS;
    }

    /**
     * Discover Eloquent models that use the Laravel Scout Searchable trait.
     *
     * @return list<class-string<EloquentModel>>
     */
    protected function discoverSearchableModels(): array
    {
        $modelsPath = app_path('Models');

        if (! is_dir($modelsPath)) {
            return [];
        }

        $classes = [];

        foreach (File::allFiles($modelsPath) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            // Build FQCN from file path assuming PSR-4 App\ namespace
            $relative = Str::after($file->getPathname(), realpath(app_path()).DIRECTORY_SEPARATOR);
            $class = 'App\\'.str_replace(['/', '\\', '.php'], ['\\', '\\', ''], $relative);

            if (! class_exists($class)) {
                continue;
            }

            // Must be an Eloquent model
            if (! is_subclass_of($class, EloquentModel::class)) {
                continue;
            }

            if ($this->usesTraitRecursive($class, Searchable::class)) {
                $classes[] = $class;
            }
        }

        sort($classes);

        return $classes;
    }

    /**
     * Determine if a class uses a trait (including via parent classes or nested traits).
     *
     * @param  class-string  $class
     * @param  class-string  $trait
     */
    protected function usesTraitRecursive(string $class, string $trait): bool
    {
        $traits = $this->classUsesRecursive($class);

        return in_array($trait, $traits, true);
    }

    /**
     * Get all traits used by a class, its parents, and nested traits.
     *
     * @param  class-string  $class
     * @return array<class-string>
     */
    protected function classUsesRecursive(string $class): array
    {
        $results = [];

        $rc = new ReflectionClass($class);
        while ($rc) {
            $results = array_merge($results, $rc->getTraitNames());
            $rc = $rc->getParentClass();
        }

        // Also include traits used by traits (nested)
        $nested = $results;
        while (! empty($nested)) {
            $next = [];
            foreach ($nested as $t) {
                $tr = new ReflectionClass($t);
                $names = $tr->getTraitNames();
                $next = array_merge($next, $names);
                $results = array_merge($results, $names);
            }
            $nested = array_values(array_diff(array_unique($next), array_unique($results)));
        }

        return array_values(array_unique($results));
    }
}

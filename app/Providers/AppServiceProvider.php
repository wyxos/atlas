<?php

namespace App\Providers;

use App\Models\File;
use App\Models\Reaction;
use App\Observers\FileObserver;
use App\Observers\ReactionObserver;
use App\Services\CivitAiImages;
use App\Services\Plugin\PluginServiceLoader;
use App\Services\Plugin\PluginServiceResolver;
use App\Services\Plugin\ServiceRegistry as LocalServiceRegistry;
use Atlas\Plugin\Contracts\ServiceRegistry;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Testing\ParallelTesting;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(ServiceRegistry::class, function () {
            return new LocalServiceRegistry;
        });

        $this->app->singleton(PluginServiceLoader::class, function ($app) {
            return new PluginServiceLoader($app, $app->make(ServiceRegistry::class));
        });

        $this->app->singleton(PluginServiceResolver::class, function ($app) {
            return new PluginServiceResolver(
                $app->make(PluginServiceLoader::class),
                $app->make(ServiceRegistry::class),
                $app
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Force HTTPS in production
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

        // Register observers
        File::observe(FileObserver::class);
        Reaction::observe(ReactionObserver::class);

        $loader = $this->app->make(PluginServiceLoader::class);
        $loader->load();

        try {
            app(ServiceRegistry::class)->register(app(CivitAiImages::class));
        } catch (\Throwable $e) {
            // Ignore failures during early application boot (e.g. before migrations).
        }

        // Configure atlas storage root from settings (machine override > global > env > default)
        try {
            $defaultPath = storage_path('app/atlas');
            $atlasRoot = \App\Models\Setting::get('atlas.path', $defaultPath);
            if (is_string($atlasRoot) && $atlasRoot !== '') {
                config(['filesystems.disks.atlas.root' => $atlasRoot]);
                // Also set the app assets disk root to <atlasRoot>/.app
                $sep = DIRECTORY_SEPARATOR;
                $appRoot = rtrim($atlasRoot, '\\/').$sep.'.app';
                config(['filesystems.disks.atlas_app.root' => $appRoot]);
            }
        } catch (\Throwable $e) {
            // Leave default config in case of early migration/state
        }

        // When running tests in parallel, isolate Scout indexes per process
        //        if (class_exists(ParallelTesting::class)) {
        //            app(ParallelTesting::class)->setUpProcess(function (int $token): void {
        //                // Prefix Scout indexes with the parallel token to isolate Typesense collections
        //                config()->set('scout.prefix', 'testing_'.$token);
        //            });
        //
        //            // Flush Scout indexes once after this worker finishes its suite.
        //            app(ParallelTesting::class)->tearDownProcess(function (int $token): void {
        //                \Artisan::call('scout:flush', ['model' => \App\Models\File::class]);
        //            });
        //        }
    }
}

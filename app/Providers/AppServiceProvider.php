<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Typesense\Client;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(Client::class, function (): Client {
            return new Client(config('scout.typesense.client-settings', []));
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}

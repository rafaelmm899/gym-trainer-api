<?php

namespace App\Providers;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureCommands();
        $this->configureDates();
        $this->configureModels();
        $this->configureUrl();
    }

    /**
     * Block destructive database commands (migrate:fresh, migrate:refresh,
     * db:wipe) while running in production.
     */
    private function configureCommands(): void
    {
        DB::prohibitDestructiveCommands($this->app->isProduction());
    }

    /**
     * Use immutable dates everywhere, so a Carbon instance can never be mutated
     * in place by accident.
     */
    private function configureDates(): void
    {
        Date::use(CarbonImmutable::class);
    }

    /**
     * Enforce strict Eloquent behaviour outside production.
     *
     * `shouldBeStrict()` turns on three guards:
     * - preventLazyLoading                  → throws on any N+1 (lazy) relation access
     * - preventSilentlyDiscardingAttributes → throws on fill() of unfillable attributes
     * - preventAccessingMissingAttributes   → throws when reading an attribute not loaded/selected
     *
     * Kept off in production so a stray lazy load degrades performance instead
     * of returning a 500 to a user.
     */
    private function configureModels(): void
    {
        Model::shouldBeStrict(! $this->app->isProduction());
    }

    /**
     * Force https:// URL generation in production, where the API always sits
     * behind TLS.
     */
    private function configureUrl(): void
    {
        URL::forceHttps($this->app->isProduction());
    }
}

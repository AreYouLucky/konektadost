<?php

namespace App\Providers;

use App\Support\Assessment\AssessmentComputationCache;
use App\Support\Assessment\AssessmentReferenceData;
use App\Support\Caching\ReadCache;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(AssessmentComputationCache::class, fn ($app) => new AssessmentComputationCache($app->make(ReadCache::class)));
        $this->app->scoped(AssessmentReferenceData::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        DB::listen(fn (QueryExecuted $event) => app(ReadCache::class)->invalidateForWrite($event));
    }
}

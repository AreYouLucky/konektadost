<?php

namespace App\Providers;

use App\Support\Assessment\AssessmentComputationCache;
use App\Support\Assessment\AssessmentReferenceData;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(AssessmentComputationCache::class);
        $this->app->scoped(AssessmentReferenceData::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}

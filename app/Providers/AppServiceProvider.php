<?php

namespace App\Providers;

use App\Support\Assessment\AssessmentComputationCache;
use App\Support\Assessment\AssessmentReferenceData;
use App\Support\Caching\ReadCache;
use App\Support\Database\CachedSchemaBuilder;
use App\Support\Database\SchemaInspectionCache;
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
        // Memoize Schema::hasTable/hasColumn lookups for the request (see CachedSchemaBuilder).
        $this->app->scoped(SchemaInspectionCache::class);
        $this->app->bind('db.schema', fn ($app) => new CachedSchemaBuilder(
            $app['db']->connection()->getSchemaBuilder(),
            $app->make(SchemaInspectionCache::class),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        DB::listen(function (QueryExecuted $event) {
            app(ReadCache::class)->invalidateForWrite($event);
            app(SchemaInspectionCache::class)->flushOnSchemaChange($event);
        });
    }
}

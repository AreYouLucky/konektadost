<?php

use App\Support\Caching\ReadCache;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('read-cache:clear', function () {
    app(ReadCache::class)->invalidate('domain');
    app(ReadCache::class)->invalidate('planning');
    $this->info('Map, assessment, catalog and planning read caches invalidated.');
})->purpose('Refresh computed data after imports or edits made outside the application');

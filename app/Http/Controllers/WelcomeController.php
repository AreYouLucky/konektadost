<?php

namespace App\Http\Controllers;

use App\Support\Caching\ReadCache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

/** Public landing page with live coverage counts (null when a table is absent, never a fake zero). */
class WelcomeController extends Controller
{
    public function __invoke(ReadCache $cache): Response
    {
        $stats = $cache->remember('welcome.stats', [], function () {
            $count = fn (string $table) => Schema::hasTable($table) ? DB::table($table)->count() : null;
            $catalog = array_filter([$count('dost_techs'), $count('technologies')], fn ($value) => $value !== null);

            return [
                'provinces' => $count('provinces'),
                'municipalities' => $count('municipalities'),
                'barangays' => $count('barangays'),
                'facilities' => $count('facilities'),
                'laboratories' => $count('laboratories'),
                'catalog' => $catalog === [] ? null : array_sum($catalog),
            ];
        });

        return Inertia::render('welcome', ['stats' => $stats]);
    }
}

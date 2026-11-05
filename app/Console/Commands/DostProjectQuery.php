<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DostProjectQuery extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:dost-project-query';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Inspect database tables, columns, counts, and sample records';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $service = app(\App\Support\Assessment\MunicipalityProfileService::class);
        $municipalities = \App\Models\Municipality::query()
            ->whereNotNull('psg_code')
            ->whereNotNull('area_km2')
            ->limit(3)
            ->get();

        $profiles = $municipalities->map(fn ($mun) => $service->getMunicipalityProfile($mun))->all();

        $this->line(json_encode($profiles, JSON_PRETTY_PRINT));

        return Command::SUCCESS;
    }
}


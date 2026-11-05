<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Assessment\AssessmentReferenceData;
use App\Support\Caching\ReadCache;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class ReadCacheTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'read-cache.enabled' => true, 'read-cache.store' => 'array', 'read-cache.ttl' => 300,
            'database.default' => 'read_cache_testing',
            'database.connections.read_cache_testing' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
        ]);
        Schema::create('provinces', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });
        DB::table('provinces')->insert(['id' => 1, 'name' => 'First province']);
    }

    public function test_repeated_reads_reuse_data_and_expire_without_mixing_filters_locations_or_users(): void
    {
        $cache = app(ReadCache::class);
        $calls = 0;
        $compute = function () use (&$calls) {
            return ['count' => ++$calls];
        };
        $this->assertSame(['count' => 1], $cache->remember('assessment', ['level' => 'province', 'id' => 1], $compute));
        $this->assertSame(['count' => 1], $cache->remember('assessment', ['id' => 1, 'level' => 'province'], $compute));
        $this->assertSame(['count' => 2], $cache->remember('assessment', ['level' => 'barangay', 'id' => 1], $compute));
        $this->assertSame(['count' => 3], $cache->remember('assessment', ['level' => 'province', 'id' => 1, 'page' => 2], $compute));
        $this->assertSame(['count' => 4], $cache->remember('messages', ['user' => 1], $compute, group: 'planning'));
        $this->assertSame(['count' => 5], $cache->remember('messages', ['user' => 2], $compute, group: 'planning'));
        $this->travel(301)->seconds();
        $this->assertSame(['count' => 6], $cache->remember('assessment', ['id' => 1, 'level' => 'province'], $compute));
    }

    public function test_query_builder_writes_refresh_data_and_request_scoped_reference_values(): void
    {
        $cache = app(ReadCache::class);
        $references = app(AssessmentReferenceData::class);
        $read = fn () => DB::table('provinces')->value('name');
        $this->assertSame('First province', $cache->remember('province', [], $read));
        $references->remember('province_name', $read);
        DB::table('provinces')->where('id', 1)->update(['name' => 'Updated province']);
        $this->assertSame('Updated province', $cache->remember('province', [], $read));
        $this->assertSame('Updated province', $references->remember('province_name', $read));
    }

    public function test_transactions_bypass_shared_cache_and_invalidate_only_after_commit(): void
    {
        $cache = app(ReadCache::class);
        $calls = 0;
        $read = function () use (&$calls) {
            $calls++;

            return DB::table('provinces')->count();
        };
        $this->assertSame(1, $cache->remember('count', [], $read));
        DB::beginTransaction();
        DB::table('provinces')->insert(['name' => 'Rolled back']);
        $this->assertSame(2, $cache->remember('count', [], $read));
        DB::rollBack();
        $this->assertSame(1, $cache->remember('count', [], $read));
        $this->assertSame(2, $calls, 'Rollback must retain the previously committed cache.');
        DB::transaction(fn () => DB::table('provinces')->insert(['name' => 'Committed']));
        $this->assertSame(2, $cache->remember('count', [], $read));
        $this->assertSame(3, $calls);
        $cache->remember('count', [], $read);
        $this->assertSame(3, $calls, 'The committed result should be reused.');
    }

    public function test_errors_are_retried_and_manual_refresh_invalidates_cached_empty_results(): void
    {
        $cache = app(ReadCache::class);
        try {
            $cache->remember('failed', [], fn () => throw new RuntimeException('Source unavailable'));
        } catch (RuntimeException $exception) {
            $this->assertSame('Source unavailable', $exception->getMessage());
        }
        $this->assertSame(['recovered'], $cache->remember('failed', [], fn () => ['recovered']));
        $this->assertSame([], $cache->remember('empty', [], fn () => []));
        $this->assertSame([], $cache->remember('empty', [], fn () => ['changed']));
        $this->artisan('read-cache:clear')->assertSuccessful();
        $this->assertSame(['changed'], $cache->remember('empty', [], fn () => ['changed']));
    }

    public function test_warm_map_endpoints_skip_repeated_queries_and_still_enforce_access(): void
    {
        $this->actingAs(new User(['id' => 1, 'role' => 'admin']));
        DB::enableQueryLog();
        $url = '/admin/search-map/geography?level=province';
        $this->getJson($url)->assertOk()->assertJsonPath('locations.0.name', 'First province');
        DB::flushQueryLog();
        $this->getJson($url)->assertOk()->assertJsonPath('locations.0.name', 'First province');
        $reads = array_filter(DB::getQueryLog(), fn ($query) => str_contains(strtolower($query['query']), 'from "provinces"'));
        $this->assertSame([], $reads, 'A warm lookup must reuse the geographic data.');
        $this->actingAs(new User(['id' => 2, 'role' => 'user']));
        $this->get($url)->assertRedirect();
        $this->getJson('/intelligence-map/geography?level=province')->assertOk();
        $this->getJson('/intelligence-map/geography?level=invalid')->assertUnprocessable();
        DB::table('provinces')->update(['name' => 'Renamed province']);
        $this->getJson('/intelligence-map/geography?level=province')->assertOk()->assertJsonPath('locations.0.name', 'Renamed province');
    }
}

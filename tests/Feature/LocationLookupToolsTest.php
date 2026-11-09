<?php

namespace Tests\Feature;

use App\FunctionTools\FunctionToolRegistry;
use App\FunctionTools\Support\LocationNameLookup;
use App\Models\Barangay;
use App\Models\CollectionYear;
use App\Models\Municipality;
use App\Models\PlaceDescription;
use App\Support\Ai\PlanningChatService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Name lookups and comparisons against the live `konektadost` place names. Read-only. */
class LocationLookupToolsTest extends TestCase
{
    private const ANY_MUNICIPALITY = 959;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'mysql',
            'database.connections.mysql.database' => env('DB_TEST_LIVE_DATABASE', 'konektadost'),
            'cache.default' => 'array',
        ]);

        // The connection may already be open on the empty `testing` database.
        DB::purge('mysql');

        CollectionYear::flushActiveYearCache();
        PlaceDescription::flushLatestReleaseCache();
    }

    public function test_city_names_match_however_people_write_them(): void
    {
        foreach (['Iloilo City', 'City of Iloilo', 'iloilo', 'ILOILO CITY'] as $name) {
            $this->assertSame('0631000000', $this->found('get_city_by_name', ['name' => $name])['psg_code'], $name);
        }

        $this->assertSame('1381300000', $this->found('get_city_by_name', ['name' => 'Quezon City'])['psg_code']);
        $this->assertSame('City of Las Piñas', $this->found('get_city_by_name', ['name' => 'Las Pinas'])['name']);
        $this->assertSame('City of Lapu-Lapu', $this->found('get_city_by_name', ['name' => 'Lapulapu'])['name']);
    }

    public function test_a_shared_city_name_is_listed_until_a_province_narrows_it(): void
    {
        $shared = $this->tool('get_city_by_name', ['name' => 'San Jose']);

        $this->assertSame('multiple_matches', $shared['status']);
        $this->assertLessThanOrEqual(10, count($shared['matches']));
        $this->assertNotEmpty($shared['matches'][0]['province']);

        $narrowed = $this->found('get_city_by_name', ['name' => 'San Jose', 'province' => 'Negros Oriental']);
        $this->assertSame('Negros Oriental', $narrowed['province']);
    }

    public function test_saying_city_prefers_cities_over_municipalities_of_the_same_name(): void
    {
        $result = $this->tool('get_city_by_name', ['name' => 'San Fernando City']);

        $this->assertSame('multiple_matches', $result['status']);
        $this->assertSame(['City of San Fernando'], array_values(array_unique(array_column($result['matches'], 'name'))));
    }

    public function test_provinces_ignore_missing_spaces_and_understand_metro_manila(): void
    {
        $this->assertSame('Ilocos Norte', $this->found('get_province_by_name', ['name' => 'Ilocos Norte'])['name']);
        $this->assertSame('Agusan del Sur', $this->found('get_province_by_name', ['name' => 'Province of Agusan del Sur'])['name']);

        foreach (['NCR', 'Metro Manila', 'National Capital Region'] as $name) {
            $this->assertSame('1300000000', $this->found('get_province_by_name', ['name' => $name])['psg_code'], $name);
        }

        $this->assertSame('multiple_matches', $this->tool('get_province_by_name', ['name' => 'Ilocos'])['status']);
    }

    public function test_barangays_are_narrowed_by_city_or_province(): void
    {
        $shared = $this->tool('get_barangay_by_name', ['name' => 'Poblacion']);
        $this->assertSame('multiple_matches', $shared['status']);
        $this->assertTrue($shared['more_matches_exist']);
        $this->assertCount(10, $shared['matches']);

        foreach (['Sta. Teresita', 'Santa Teresita', 'Brgy. Sta. Teresita'] as $name) {
            $found = $this->found('get_barangay_by_name', ['name' => $name, 'city' => 'Angeles City']);
            $this->assertSame('0330100025', $found['psg_code'], $name);
            $this->assertSame('City of Angeles', $found['city']);
            $this->assertNotEmpty($found['province']);
        }

        $inManila = $this->tool('get_city_by_name', ['name' => 'Manila', 'province' => 'Metro Manila']);
        $this->assertSame('City of Manila', $inManila['location']['name']);
    }

    public function test_unknown_or_blank_names_are_errors_not_guesses(): void
    {
        $this->assertStringContainsString('No city or municipality', $this->tool('get_city_by_name', ['name' => 'Atlantis'])['error']);
        $this->assertStringContainsString('No province', $this->tool('get_city_by_name', ['name' => 'San Jose', 'province' => 'Atlantis'])['error']);
        $this->assertStringContainsString('No barangay', $this->tool('get_barangay_by_name', ['name' => 'Zzqx', 'city' => 'Tigbauan'])['error']);
        $this->assertArrayHasKey('error', $this->tool('get_province_by_name', ['name' => '   ']));
        $this->assertArrayHasKey('error', $this->tool('get_barangay_by_name', ['name' => 'brgy. .']));
    }

    public function test_like_wildcards_in_a_name_are_literal(): void
    {
        $this->assertArrayHasKey('error', $this->tool('get_city_by_name', ['name' => '%']));
        $this->assertArrayHasKey('error', $this->tool('get_barangay_by_name', ['name' => '_____', 'city' => 'Tigbauan']));
    }

    public function test_two_provinces_can_be_compared_without_the_selected_municipality(): void
    {
        $iloilo = $this->found('get_province_by_name', ['name' => 'Iloilo']);
        $cebu = $this->found('get_province_by_name', ['name' => 'Cebu']);

        $result = $this->compare(['compare_from' => $iloilo['psg_code'], 'compare_with' => $cebu['psg_code']]);

        $this->assertSame('province', $result['locations']['a']['level']);
        $this->assertSame('Iloilo', $result['locations']['a']['name']);
        $this->assertSame('Cebu', $result['locations']['b']['name']);
        $this->assertGreaterThan(0, $result['locations']['a']['population']);
        $this->assertNotEmpty($result['indicators']);
        $this->assertStringNotContainsString('different administrative levels', $result['note']);
    }

    public function test_barangays_sharing_a_name_keep_distinct_labels(): void
    {
        [$first, $second] = Barangay::query()->where('brgy_name', 'Poblacion')->orderBy('id')->limit(2)->pluck('psg_code')->all();

        $result = $this->compare(['compare_from' => $first, 'compare_with' => $second]);

        $this->assertStringStartsWith('Poblacion, ', $result['locations']['a']['name']);
        $this->assertStringStartsWith('Poblacion, ', $result['locations']['b']['name']);
        $this->assertNotSame($result['locations']['a']['name'], $result['locations']['b']['name']);
        $this->assertStringContainsString('Barangay populations are small', $result['note']);
    }

    public function test_the_selected_municipality_remains_the_default_first_location(): void
    {
        $result = $this->compare(['compare_with' => 'Iloilo City']);

        $this->assertSame('Maribojoc', $result['locations']['a']['name']);
        $this->assertSame('City of Iloilo', $result['locations']['b']['name']);
        $this->assertSame('Bohol', $result['locations']['a']['province']);
    }

    public function test_comparing_across_levels_is_flagged(): void
    {
        $province = $this->found('get_province_by_name', ['name' => 'Iloilo']);

        $result = $this->compare(['compare_with' => $province['psg_code']]);

        $this->assertStringContainsString('different administrative levels', $result['note']);
    }

    public function test_province_filters_with_spaces_now_narrow_a_comparison(): void
    {
        $result = $this->compare(['compare_with' => 'Batac', 'compare_province' => 'Ilocos Norte']);

        $this->assertSame('City of Batac', $result['locations']['b']['name']);
    }

    public function test_comparison_refuses_the_same_place_ambiguous_names_and_unknown_codes(): void
    {
        $this->assertStringContainsString('same', $this->compare(['compare_with' => 'Maribojoc', 'compare_province' => 'Bohol'])['error']);
        $this->assertSame('No location has that PSGC code.', $this->compare(['compare_with' => '9999999999'])['error']);

        $ambiguous = $this->compare(['compare_with' => 'San Jose']);
        $this->assertStringContainsString('Several locations', $ambiguous['error']);
        $this->assertNotEmpty($ambiguous['candidates'][0]['psg_code']);
    }

    public function test_geo_agent_can_find_and_compare_two_provinces(): void
    {
        $lookup = app(LocationNameLookup::class);
        $iloilo = $lookup->province('Iloilo')['location']['psg_code'];
        $cebu = $lookup->province('Cebu')['location']['psg_code'];

        config(['services.openai.api_key' => 'private-test-key', 'services.openai.model' => 'configured-chat-model']);
        Http::preventStrayRequests();
        Http::fake(['api.openai.com/v1/responses' => Http::sequence()
            ->push($this->functionCall('get_province_by_name', ['name' => 'Iloilo']))
            ->push($this->functionCall('get_province_by_name', ['name' => 'Cebu']))
            ->push($this->functionCall('compare_locations', ['compare_from' => $iloilo, 'compare_with' => $cebu]))
            ->push(['status' => 'completed', 'output' => [['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => 'Compared.']]]]])]);

        $result = app(PlanningChatService::class)->reply(Municipality::query()->findOrFail(self::ANY_MUNICIPALITY), 'Compare Iloilo and Cebu provinces');

        $this->assertSame('ready', $result['status']);
        $reply = $result['state']['messages'][array_key_last($result['state']['messages'])];
        $comparison = collect($reply['visuals'])->firstWhere('type', 'comparison');
        $this->assertSame(['a' => 'Iloilo', 'b' => 'Cebu'], $comparison['locations']);
        $this->assertNotEmpty($comparison['rows']);
        $this->assertContains('get_province_by_name', array_column($reply['sources'], 'name'));
        Http::assertSentCount(4);
    }

    public function test_the_chat_schema_keeps_the_lookup_arguments(): void
    {
        $definitions = array_column(app(PlanningChatService::class)->definitions(), null, 'name');

        $this->assertSame(['name', 'province'], array_keys((array) $definitions['get_city_by_name']['parameters']['properties']));
        $this->assertSame(['name'], array_keys((array) $definitions['get_province_by_name']['parameters']['properties']));
        $this->assertSame(['name', 'city', 'province'], array_keys((array) $definitions['get_barangay_by_name']['parameters']['properties']));
        $this->assertSame(['compare_with', 'compare_province', 'compare_from'], array_keys((array) $definitions['compare_locations']['parameters']['properties']));
    }

    private function tool(string $name, array $args): array
    {
        return app(FunctionToolRegistry::class)->execute($name, $args);
    }

    private function found(string $name, array $args): array
    {
        $result = $this->tool($name, $args);
        $this->assertSame('found', $result['status'] ?? null, json_encode($result));

        return $result['location'];
    }

    private function compare(array $args): array
    {
        return $this->tool('compare_locations', ['municipality_id' => self::ANY_MUNICIPALITY, ...$args]);
    }

    private function functionCall(string $name, array $arguments): array
    {
        return ['status' => 'completed', 'output' => [[
            'type' => 'function_call', 'call_id' => 'call_'.md5($name.json_encode($arguments)),
            'name' => $name, 'arguments' => json_encode($arguments),
        ]]];
    }
}

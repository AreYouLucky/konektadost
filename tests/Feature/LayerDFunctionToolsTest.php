<?php

namespace Tests\Feature;

use App\FunctionTools\FunctionToolRegistry;
use App\Models\Municipality;
use App\Models\Province;
use Tests\TestCase;

class LayerDFunctionToolsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'mysql',
            'database.connections.mysql.database' => env('DB_TEST_LIVE_DATABASE', 'konektadost'),
            'cache.default' => 'array',
        ]);
    }

    public function test_registry_exposes_both_layer_d_function_tools(): void
    {
        $registry = app(FunctionToolRegistry::class);
        $definitions = $registry->definitions('D');

        $names = array_column($definitions, 'name');

        $this->assertSame([
            'search_dost_technologies',
            'get_local_dost_projects',
        ], $names);
    }

    public function test_search_dost_technologies_executes_and_filters_across_live_catalogs(): void
    {
        $registry = app(FunctionToolRegistry::class);

        // General search across catalogs
        $general = $registry->execute('search_dost_technologies', ['limit' => 25]);
        $this->assertGreaterThan(0, $general['total_matched']);
        $this->assertLessThanOrEqual(25, $general['returned_count']);
        $this->assertContains('dost_techs', $general['sources_queried']);
        $this->assertContains('technologies', $general['sources_queried']);
        $this->assertContains('dost_projects', $general['sources_queried']);
        $this->assertArrayHasKey('by_sector', $general['summary_facets']);
        $this->assertArrayHasKey('by_agency', $general['summary_facets']);
        $this->assertArrayHasKey('by_tech_status', $general['summary_facets']);

        $first = $general['records'][0];
        $this->assertArrayHasKey('id', $first);
        $this->assertArrayHasKey('source_catalog', $first);
        $this->assertArrayHasKey('title', $first);
        $this->assertArrayHasKey('agency', $first);
        $this->assertArrayHasKey('sector', $first);
        $this->assertArrayHasKey('tech_status', $first);
        $this->assertContains($first['tech_status'], ['available', 'limited', 'research_stage', 'unavailable', 'unknown']);

        // Keyword filter
        $kw = $registry->execute('search_dost_technologies', ['keyword' => 'Water', 'limit' => 10]);
        $this->assertGreaterThan(0, $kw['total_matched']);
        foreach ($kw['records'] as $rec) {
            $matchedText = strtolower(
                $rec['title'].' '
                .($rec['description'] ?? '').' '
                .($rec['sector'] ?? '').' '
                .($rec['expected_outcome'] ?? '').' '
                .($rec['intended_beneficiaries'] ?? '')
            );
            $this->assertStringContainsString('water', $matchedText);
        }

        // Sector filter
        $health = $registry->execute('search_dost_technologies', ['sector' => 'Health', 'limit' => 10]);
        $this->assertGreaterThan(0, $health['total_matched']);

        // Agency filter
        $itdi = $registry->execute('search_dost_technologies', ['agency' => 'ITDI', 'limit' => 10]);
        $this->assertGreaterThan(0, $itdi['total_matched']);
        foreach ($itdi['records'] as $rec) {
            $this->assertStringContainsString('ITDI', (string) $rec['agency']);
        }

        // Technology readiness / availability status filter
        $available = $registry->execute('search_dost_technologies', ['tech_status' => 'available', 'limit' => 15]);
        $this->assertGreaterThan(0, $available['total_matched']);
        foreach ($available['records'] as $rec) {
            $this->assertSame('available', $rec['tech_status']);
        }
    }

    public function test_get_local_dost_projects_executes_against_live_database(): void
    {
        $registry = app(FunctionToolRegistry::class);

        // Test with Province of Bohol (id = 15)
        $bohol = Province::query()->findOrFail(15);
        $projectsBohol = $registry->execute('get_local_dost_projects', ['province_id' => 15]);

        $this->assertSame('province', $projectsBohol['location_level']);
        $this->assertSame(15, $projectsBohol['location_id']);
        $this->assertContains('bohol', $projectsBohol['search_tokens']);
        $this->assertArrayHasKey('total_matched_projects', $projectsBohol);
        $this->assertArrayHasKey('historical_projects_count', $projectsBohol);
        $this->assertArrayHasKey('ongoing_projects_count', $projectsBohol);
        $this->assertArrayHasKey('total_recorded_budget_php', $projectsBohol);
        $this->assertArrayHasKey('summary_facets', $projectsBohol);
        $this->assertArrayHasKey('disclaimer', $projectsBohol);

        // Test with Quezon City (id = 126) which has many matched DOST projects
        $qc = Municipality::query()->findOrFail(126);
        $projectsQc = $registry->execute('get_local_dost_projects', ['municipality_id' => 126]);

        $this->assertSame('municipality', $projectsQc['location_level']);
        $this->assertSame(126, $projectsQc['location_id']);
        $this->assertGreaterThan(0, $projectsQc['total_matched_projects']);
        $this->assertGreaterThan(0, $projectsQc['total_recorded_budget_php']);
        $this->assertIsArray($projectsQc['projects']);

        $proj = $projectsQc['projects'][0];
        $this->assertArrayHasKey('id', $proj);
        $this->assertArrayHasKey('agency', $proj);
        $this->assertArrayHasKey('project_title', $proj);
        $this->assertArrayHasKey('status_category', $proj);
        $this->assertContains($proj['status_category'], ['historical_completed', 'active_ongoing']);
        $this->assertArrayHasKey('normalized_status', $proj);

        // Layer D bundle execution
        $bundle = $registry->executeLayerD($qc, ['keyword' => 'Food']);
        $this->assertArrayHasKey('local_projects', $bundle);
        $this->assertArrayHasKey('technologies', $bundle);
        $this->assertGreaterThan(0, $bundle['local_projects']['total_matched_projects']);
        $this->assertGreaterThan(0, $bundle['technologies']['total_matched']);
    }
}

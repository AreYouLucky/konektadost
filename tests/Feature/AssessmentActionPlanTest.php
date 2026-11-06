<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Saving the action plans generated on the assessment page. Isolated in-memory schema; no reference DB. */
class AssessmentActionPlanTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'cache.stores.file' => ['driver' => 'array'],
            'database.default' => 'plan_testing',
            'database.connections.plan_testing' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
        ]);
        Schema::create('provinces', function (Blueprint $table) {
            $table->id();
            $table->string('psg_code');
            $table->string('name');
        });
        Schema::create('municipalities', function (Blueprint $table) {
            $table->id();
            $table->string('psg_code');
            $table->string('name');
            $table->string('province_name')->nullable();
            $table->unsignedBigInteger('province_id')->nullable();
            $table->timestamps();
        });
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });
        DB::table('provinces')->insert(['id' => 1, 'psg_code' => '0701200000', 'name' => 'Test Province']);
        DB::table('municipalities')->insert([
            ['id' => 1, 'psg_code' => '0701232000', 'name' => 'Test City', 'province_name' => 'Test Province', 'province_id' => 1],
            ['id' => 2, 'psg_code' => '0701233000', 'name' => 'Other City', 'province_name' => 'Test Province', 'province_id' => 1],
        ]);
        DB::table('users')->insert([['id' => 1, 'name' => 'First User'], ['id' => 2, 'name' => 'Second User']]);
        (require database_path('migrations/2026_11_03_120000_create_planning_chat_history_tables.php'))->up();
        (require database_path('migrations/2026_11_03_130000_add_visuals_to_planning_history_tables.php'))->up();
        (require database_path('migrations/2026_11_06_000400_create_assessment_action_plans_table.php'))->up();
    }

    public function test_saving_requires_authentication_and_a_valid_plan(): void
    {
        $this->postJson(route('location.assess.plans.store'), ['city' => '0701232000', 'snapshot' => $this->snapshot()])->assertUnauthorized();
        $this->getJson(route('location.assess.plans.show', ['plan' => 1]))->assertUnauthorized();
        $this->signIn();
        $this->postJson(route('location.assess.plans.store'), ['city' => '0701232000'])->assertUnprocessable();
        $this->postJson(route('location.assess.plans.store'), ['snapshot' => $this->snapshot()])->assertUnprocessable();
        $this->postJson(route('location.assess.plans.store'), ['city' => 'bad', 'snapshot' => $this->snapshot()])->assertUnprocessable();
        $this->postJson(route('location.assess.plans.store'), ['city' => '0701232000', 'snapshot' => $this->snapshot(['activities' => []])])->assertUnprocessable();
        $this->postJson(route('location.assess.plans.store'), ['city' => '9999999999', 'snapshot' => $this->snapshot()])->assertNotFound();
        $this->assertSame(0, DB::table('assessment_action_plans')->count());
    }

    public function test_a_generated_plan_is_saved_once_and_a_changed_plan_becomes_a_new_version(): void
    {
        $this->signIn();
        $first = $this->postJson(route('location.assess.plans.store'), ['city' => '0701232000', 'focus' => 'general', 'snapshot' => $this->snapshot()])
            ->assertCreated()->assertJsonPath('created', true)->assertJsonPath('plan.version', 1)
            ->assertJsonPath('plan.title', 'Assessment action plan — Test City')->json('plan.id');

        // Reopening the same plan later only changes its generation time: no duplicate version.
        $this->postJson(route('location.assess.plans.store'), ['city' => '0701232000', 'focus' => 'general',
            'snapshot' => $this->snapshot(['generatedAt' => '2026-11-06T05:00:00.000Z'])])
            ->assertOk()->assertJsonPath('created', false)->assertJsonPath('plan.id', $first);

        $this->postJson(route('location.assess.plans.store'), ['city' => '0701232000', 'focus' => 'general',
            'snapshot' => $this->snapshot(['laboratory' => 'Nearest recorded referral: Test Lab.', 'aiGeneratedAt' => '2026-11-06T09:00:00+08:00'])])
            ->assertCreated()->assertJsonPath('plan.version', 2)->assertJsonPath('plan.ai_assisted', true);

        $this->getJson(route('location.assess.plans.show', ['plan' => $first]))->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('plan.location_name', 'Test City')
            ->assertJsonPath('plan.assessment_url', route('location.assess', ['city' => '0701232000', 'focus' => 'general']))
            // Keys without their own validation rule are stored as sent.
            ->assertJsonPath('plan.snapshot.plan.options.0.aiNote', 'Kept as sent')
            ->assertJsonPath('plan.snapshot.sources.0.source', 'PSA');
        $this->assertSame(2, DB::table('assessment_action_plans')->count());
    }

    public function test_map_scoped_plans_take_the_location_from_the_geography_tables(): void
    {
        $this->signIn();
        $this->postJson(route('location.assess.plans.store'), [
            'level' => 'municipality', 'location' => 2, 'map' => 'admin',
            'snapshot' => $this->snapshot(['location' => 'Spoofed name']),
        ])->assertCreated()->assertJsonPath('plan.location_name', 'Other City')->assertJsonPath('plan.province_name', 'Test Province')
            ->assertJsonPath('plan.level', 'municipality')
            // A non-admin is never sent back to the admin map.
            ->assertJsonPath('plan.assessment_url', route('location.assess', ['level' => 'municipality', 'location' => 2, 'map' => 'user', 'focus' => 'general']));
        $this->postJson(route('location.assess.plans.store'), ['level' => 'municipality', 'location' => 99, 'snapshot' => $this->snapshot()])->assertNotFound();
    }

    public function test_plans_are_private_and_listed_with_the_generated_action_plans(): void
    {
        $this->signIn();
        $id = $this->postJson(route('location.assess.plans.store'), ['city' => '0701232000', 'snapshot' => $this->snapshot()])->json('plan.id');
        $this->getJson(route('planning-chat.saved-plans'))->assertOk()->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.kind', 'assessment')->assertJsonPath('items.0.id', $id)
            ->assertJsonPath('items.0.municipality', 'Test City')->assertJsonPath('items.0.city', '0701232000');

        $this->signIn(2);
        $this->getJson(route('planning-chat.saved-plans'))->assertOk()->assertJsonCount(0, 'items');
        $this->getJson(route('location.assess.plans.show', ['plan' => $id]))->assertNotFound();
    }

    public function test_the_csrf_endpoint_returns_the_current_session_token(): void
    {
        $this->getJson(route('csrf-token'))->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonStructure(['token']);
    }

    private function signIn(int $id = 1): void
    {
        $this->actingAs((new User)->forceFill(['id' => $id, 'name' => 'Test User', 'role' => 'user']));
    }

    private function snapshot(array $plan = []): array
    {
        return [
            'schema' => 1,
            'province' => 'Test Province',
            'targets' => null,
            'sources' => [['data' => 'Population', 'source' => 'PSA', 'reference' => '2024', 'use' => 'Population need']],
            'plan' => array_replace([
                'location' => 'Test City',
                'level' => 'City',
                'scopeNote' => null,
                'populationYear' => 2024,
                'generatedAt' => '2026-11-06T01:00:00.000Z',
                'aiGeneratedAt' => null,
                'options' => [['ref' => 'technologies:1', 'name' => 'STARBOOKS', 'agency' => 'SEI', 'need' => 'Learning access',
                    'relevance' => 72, 'status' => 'Active', 'why' => 'Sector match', 'aiNote' => 'Kept as sent']],
                'priorities' => [['area' => 'Test City', 'basis' => 'Selected area only.']],
                'targets' => [],
                'targetLevel' => null,
                'needs' => [],
                'activities' => [['phase' => 'Validation', 'activity' => 'Validate needs', 'objective' => 'Check records', 'result' => 'Baseline',
                    'startWeek' => 1, 'endWeek' => 2, 'resources' => 'To be costed', 'responsible' => 'Proposed: LGU planning team']],
                'laboratory' => 'No verified service partner in the returned evidence.',
                'limitations' => ['Draft for LGU/DOST review.'],
                'charts' => [],
            ], $plan),
        ];
    }
}

<?php

namespace Tests\Feature;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Live smoke test for the GeoRiskPH map API behind the GATES API Manager (read-only requests).
 *
 * Skipped unless GEORISK_TOKEN is set, so the normal suite stays offline. Tokens last one hour.
 *
 *   $env:GEORISK_TOKEN = '<access token>'; php artisan test --compact tests/Feature/GeoRiskApiTest.php
 *
 * Keep the trailing slash on the root URL: requesting it without one returned "Error in Sender"
 * (101509), and the shared backend endpoint then briefly reported SUSPENDED (303001).
 */
class GeoRiskApiTest extends TestCase
{
    private const DEFAULT_BASE_URL = 'https://infra-api-manager.gates-staging.work/georisk/1.0.0';

    private const SERVICES = [
        'ActiveFault',
        'Barangay_2020',
        'Bridges2',
        'GroundShaking',
        'Health_Facilities_Location_points',
        'Liquefaction',
        'LRS_Road_Network_Primary2',
        'LRS_Road_Network_Secondary2',
        'LRS_Road_Network_Tertiary2',
        'Municipal_2020',
        'School_Location_points',
        'Tsunami',
    ];

    private string $token;

    private string $baseUrl;

    protected function setUp(): void
    {
        parent::setUp();

        $this->token = (string) env('GEORISK_TOKEN', '');
        $this->baseUrl = rtrim((string) (env('GEORISK_BASE_URL') ?: self::DEFAULT_BASE_URL), '/');

        if ($this->token === '') {
            $this->markTestSkipped('Set GEORISK_TOKEN to run the live GeoRisk API checks.');
        }
    }

    public function test_a_request_without_a_token_is_rejected(): void
    {
        $response = $this->client()->get($this->baseUrl.'/');

        $this->assertSame(401, $response->status(), $this->describe($response));
        $this->assertSame('900902', (string) $response->json('code'));
    }

    public function test_an_invalid_token_is_rejected(): void
    {
        $response = $this->client()->withToken('invalid.token.value')->get($this->baseUrl.'/');

        $this->assertSame(401, $response->status(), $this->describe($response));
        $this->assertSame('900901', (string) $response->json('code'));
    }

    public function test_the_root_lists_every_map_service(): void
    {
        $response = $this->authorized()->get($this->baseUrl.'/');

        $this->assertSame(200, $response->status(), $this->describe($response));

        $services = collect($response->json('services'));
        $this->assertEqualsCanonicalizing(self::SERVICES, $services->pluck('name')->all());
        $this->assertSame(['MapServer'], $services->pluck('type')->unique()->values()->all());
    }

    #[DataProvider('services')]
    public function test_each_service_exposes_map_metadata(string $service): void
    {
        $response = $this->authorized()->get("{$this->baseUrl}/{$service}/MapServer", ['f' => 'json']);

        $this->assertSame(200, $response->status(), $this->describe($response));
        $this->assertNotEmpty($response->json('layers'), $this->describe($response));
        $this->assertStringContainsString('Map', (string) $response->json('capabilities'));
    }

    public function test_map_metadata_can_be_read_with_a_form_encoded_post(): void
    {
        $response = $this->authorized()->asForm()->post("{$this->baseUrl}/ActiveFault/MapServer", ['f' => 'json']);

        $this->assertSame(200, $response->status(), $this->describe($response));
        $this->assertNotEmpty($response->json('layers'), $this->describe($response));
        $this->assertStringContainsString('Map', (string) $response->json('capabilities'));
    }

    public function test_a_map_image_can_be_exported(): void
    {
        $response = $this->authorized()->get("{$this->baseUrl}/ActiveFault/MapServer/export", [
            'bbox' => '116,4,127,21',
            'bboxSR' => 4326,
            'size' => '256,256',
            'format' => 'png',
            'f' => 'image',
        ]);

        $this->assertSame(200, $response->status(), $this->describe($response));
        $this->assertStringStartsWith('image/png', $response->header('Content-Type'));
        $this->assertStringStartsWith("\x89PNG\r\n\x1a\n", $response->body());
    }

    /** @return array<string, array{string}> */
    public static function services(): array
    {
        return array_combine(self::SERVICES, array_map(fn (string $service) => [$service], self::SERVICES));
    }

    private function client(): PendingRequest
    {
        return Http::withHeaders(['Accept' => '*/*'])
            ->withOptions(['verify' => config('services.gates.ca_bundle') ?: true])
            ->connectTimeout(10)
            ->timeout(60);
    }

    private function authorized(): PendingRequest
    {
        return $this->client()->withToken($this->token);
    }

    private function describe(Response $response): string
    {
        return 'GeoRisk responded with '.$response->status().': '.Str::limit($response->body(), 500);
    }
}

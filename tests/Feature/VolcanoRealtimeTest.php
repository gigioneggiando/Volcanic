<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class VolcanoRealtimeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['services.ambee.key' => 'test-api-key']);
        Http::preventStrayRequests();
    }

    public function test_latest_returns_normalized_volcano_events(): void
    {
        Http::fake([
            'api.ambeedata.com/*' => Http::response([
                'result' => [[
                    'details' => [
                        'event_place' => 'Mount Etna',
                        'country_code' => 'IT',
                        'event_type' => 'VO',
                        'event_date' => '2026-10-26T09:15:00Z',
                        'severity' => 'Moderate',
                    ],
                ]],
            ]),
        ]);

        $this->getJson('/api/volcano/latest?continent=EUR&limit=3&page=2')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('event_source', 'volcano_only')
            ->assertJsonPath('events.0.title', 'Mount Etna')
            ->assertJsonPath('events.0.country_code', 'IT');

        Http::assertSent(function (Request $request) {
            $url = parse_url($request->url());
            parse_str($url['query'] ?? '', $query);

            return ($url['scheme'] ?? null) === 'https'
                && ($url['host'] ?? null) === 'api.ambeedata.com'
                && ($url['path'] ?? null) === '/disasters/latest/by-continent'
                && $request->hasHeader('x-api-key', 'test-api-key')
                && ($query['continent'] ?? null) === 'EUR'
                && ($query['eventType'] ?? null) === 'VO'
                && ($query['limit'] ?? null) === '3'
                && ($query['page'] ?? null) === '2';
        });
    }

    public function test_latest_does_not_expose_an_upstream_error_body(): void
    {
        Http::fake([
            'api.ambeedata.com/*' => Http::response([
                'message' => 'Internal upstream detail',
                'token' => 'must-not-leak',
            ], 503),
        ]);

        $response = $this->getJson('/api/volcano/latest');

        $response
            ->assertStatus(503)
            ->assertExactJson([
                'status' => 'error',
                'message' => 'Unable to fetch volcano data from Ambee.',
            ]);

        $this->assertStringNotContainsString('must-not-leak', $response->getContent());
    }

    public function test_latest_does_not_expose_connection_exception_details(): void
    {
        Http::fake([
            'api.ambeedata.com/*' => Http::failedConnection('sensitive TLS path'),
        ]);

        $response = $this->getJson('/api/volcano/latest');

        $response
            ->assertStatus(500)
            ->assertExactJson([
                'status' => 'error',
                'message' => 'Unexpected error while contacting Ambee.',
            ]);

        $this->assertStringNotContainsString('sensitive TLS path', $response->getContent());
    }
}

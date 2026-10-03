<?php

namespace Tests\Feature;

use App\Models\StockClass;
use App\Models\StockRecord;
use App\Services\DemoStockResponder;
use App\Services\StockProposalValidator;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiSafetyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.anthropic.requests_per_minute', 1000);
        config()->set('services.anthropic.daily_request_limit', 1000);
    }

    public function test_demo_parse_returns_validated_fixture_without_an_external_request(): void
    {
        $this->seed(DatabaseSeeder::class);
        config()->set('services.anthropic.demo_mode', true);
        Http::fake();

        $this->postJson('/api/stock/parse')
            ->assertOk()
            ->assertJsonPath('ai_mode', 'demo')
            ->assertJsonPath('meta.source', 'recorded_fixture')
            ->assertJsonCount(17, 'proposals')
            ->assertJsonCount(1, 'unresolved');

        Http::assertNothingSent();
    }

    public function test_demo_fixture_covers_each_seeded_record_once_and_passes_the_validator(): void
    {
        $this->seed(DatabaseSeeder::class);
        $fixture = app(DemoStockResponder::class)->parseResponse();
        $fixtureIds = collect([...$fixture['proposals'], ...$fixture['unresolved']])
            ->flatMap(fn (array $item) => $item['record_ids'])
            ->sort()
            ->values()
            ->all();

        $this->assertSame(range(1, 21), $fixtureIds);
        $this->assertCount(count(array_unique($fixtureIds)), $fixtureIds);

        $validated = app(StockProposalValidator::class)->validate(
            $fixture,
            StockClass::orderBy('id')->get(),
            StockRecord::orderBy('id')->get(),
        );

        $this->assertCount(17, $validated['proposals']);
        $this->assertCount(1, $validated['unresolved']);
        $this->assertSame([21], $validated['unresolved'][0]['record_ids']);
        $this->assertSame('duplicate', collect($validated['proposals'])->firstWhere('record_ids', [11, 12])['flag']);
    }

    public function test_demo_report_uses_the_validated_request_numbers_without_http(): void
    {
        config()->set('services.anthropic.demo_mode', true);
        Http::fake();

        $response = $this->postJson('/api/stock/report', [
            'report' => $this->report(
                opening: 150,
                births: 10,
                sales: 20,
                calculatedClosing: 140,
                recordedClosing: 135,
                difference: 5,
            ),
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('ai_mode', 'demo')
            ->assertJsonPath('meta.source', 'deterministic_renderer');
        $this->assertStringContainsString('Opening: 150', $response->json('text'));
        $this->assertStringContainsString('Calculated closing: 140', $response->json('text'));
        $this->assertStringContainsString('Recorded closing: 135', $response->json('text'));
        $this->assertStringContainsString('Difference: +5', $response->json('text'));

        Http::assertNothingSent();
    }

    public function test_report_endpoint_rejects_inconsistent_client_calculations(): void
    {
        $report = $this->report();
        $report['classes'][0]['calculated_closing'] = 999;
        Http::fake();

        $this->postJson('/api/stock/report', ['report' => $report])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('report.classes.0');

        Http::assertNothingSent();
    }

    public function test_live_parse_calls_anthropic_and_returns_live_mode(): void
    {
        $this->seed(DatabaseSeeder::class);
        config()->set('services.anthropic.demo_mode', false);
        config()->set('services.anthropic.key', 'test-anthropic-key');
        $fixture = app(DemoStockResponder::class)->parseResponse();
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => json_encode($fixture, JSON_THROW_ON_ERROR)]],
            ]),
        ]);

        $this->postJson('/api/stock/parse')
            ->assertOk()
            ->assertJsonPath('ai_mode', 'live')
            ->assertJsonPath('meta.provider', 'anthropic')
            ->assertJsonCount(17, 'proposals');

        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.anthropic.com/v1/messages'
            && $request->hasHeader('x-api-key', 'test-anthropic-key')
        );
    }

    public function test_live_report_calls_anthropic_and_returns_live_mode(): void
    {
        config()->set('services.anthropic.demo_mode', false);
        config()->set('services.anthropic.key', 'test-anthropic-key');
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => 'Live report text']],
            ]),
        ]);

        $this->postJson('/api/stock/report', ['report' => $this->report()])
            ->assertOk()
            ->assertJsonPath('text', 'Live report text')
            ->assertJsonPath('ai_mode', 'live')
            ->assertJsonPath('meta.provider', 'anthropic');

        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.anthropic.com/v1/messages'
            && str_contains((string) $request['messages'][0]['content'], 'calculated_closing')
        );
    }

    public function test_live_mode_without_a_key_returns_a_clear_configuration_error(): void
    {
        $this->seed(DatabaseSeeder::class);
        config()->set('services.anthropic.demo_mode', false);
        config()->set('services.anthropic.key', null);
        Http::fake();

        $this->postJson('/api/stock/parse')
            ->assertStatus(503)
            ->assertJsonPath(
                'error',
                'Anthropic is not configured. Set ANTHROPIC_API_KEY in .env and clear the configuration cache.',
            );

        Http::assertNothingSent();
    }

    public function test_generic_ai_endpoint_is_disabled_by_default(): void
    {
        config()->set('services.anthropic.allow_generic_endpoint', false);
        config()->set('services.anthropic.demo_mode', false);
        Http::fake();

        $this->postJson('/api/ai', ['prompt' => 'Hello'])
            ->assertForbidden()
            ->assertJsonPath('error', 'The generic AI endpoint is disabled. Use a task-specific endpoint.');

        Http::assertNothingSent();
    }

    public function test_generic_endpoint_in_demo_mode_still_cannot_make_a_live_call(): void
    {
        config()->set('services.anthropic.demo_mode', true);
        config()->set('services.anthropic.allow_generic_endpoint', true);
        Http::fake();

        $this->postJson('/api/ai', ['prompt' => 'Hello'])
            ->assertStatus(503);

        Http::assertNothingSent();
    }

    public function test_generic_ai_endpoint_rejects_oversized_prompts_before_calling_provider(): void
    {
        config()->set('services.anthropic.allow_generic_endpoint', true);
        config()->set('services.anthropic.max_prompt_chars', 10);
        Http::fake();

        $this->postJson('/api/ai', ['prompt' => 'This is longer than ten characters'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('prompt');

        Http::assertNothingSent();
    }

    private function report(
        int $opening = 100,
        int $births = 10,
        int $sales = 20,
        int $calculatedClosing = 90,
        int $recordedClosing = 90,
        int $difference = 0,
    ): array {
        return [
            'classes' => [[
                'stock_class' => 'Lambs',
                'opening' => $opening,
                'births' => $births,
                'purchases' => 0,
                'deaths' => 0,
                'sales' => $sales,
                'calculated_closing' => $calculatedClosing,
                'recorded_closing' => $recordedClosing,
                'difference' => $difference,
                'status' => $difference === 0 ? 'reconciled' : 'unreconciled',
            ]],
            'accepted_proposals' => [],
            'review_proposals' => [],
            'unresolved' => [],
            'already_keyed' => [],
            'counts' => [
                'parser_proposals' => 0,
                'included_by_parser' => 0,
                'added_to_report' => 0,
                'needs_review' => 0,
                'already_keyed' => 0,
            ],
        ];
    }
}

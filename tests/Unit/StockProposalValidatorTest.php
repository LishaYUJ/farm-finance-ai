<?php

namespace Tests\Unit;

use App\Models\StockClass;
use App\Models\StockRecord;
use App\Services\StockProposalValidator;
use PHPUnit\Framework\TestCase;

class StockProposalValidatorTest extends TestCase
{
    private StockProposalValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->validator = new StockProposalValidator;
    }

    public function test_it_accepts_a_strict_well_formed_proposal_and_resolves_the_class_id(): void
    {
        $result = $this->validator->validate([
            'proposals' => [$this->proposal()],
            'unresolved' => [],
        ], $this->classes(), $this->records());

        $this->assertCount(1, $result['proposals']);
        $this->assertSame(7, $result['proposals'][0]['stock_class_id']);
        $this->assertSame(false, $result['proposals'][0]['include']);
        $this->assertSame([], $result['unresolved']);
    }

    public function test_it_rejects_string_booleans_and_fractional_quantities(): void
    {
        $stringBoolean = $this->validator->validate([
            'proposals' => [$this->proposal(['include' => 'false'])],
            'unresolved' => [],
        ], $this->classes(), $this->records());

        $fractionalQuantity = $this->validator->validate([
            'proposals' => [$this->proposal(['quantity' => 12.5])],
            'unresolved' => [],
        ], $this->classes(), $this->records());

        $this->assertSame([], $stringBoolean['proposals']);
        $this->assertStringContainsString('JSON Boolean', $stringBoolean['unresolved'][0]['reason']);
        $this->assertSame([], $fractionalQuantity['proposals']);
        $this->assertStringContainsString('JSON integer', $fractionalQuantity['unresolved'][0]['reason']);
    }

    public function test_it_rejects_unknown_and_reused_source_records(): void
    {
        $unknown = $this->validator->validate([
            'proposals' => [$this->proposal(['record_ids' => [999]])],
            'unresolved' => [],
        ], $this->classes(), $this->records());

        $duplicate = $this->validator->validate([
            'proposals' => [
                $this->proposal(),
                $this->proposal(['type' => 'death', 'quantity' => 2]),
            ],
            'unresolved' => [],
        ], $this->classes(), $this->records());

        $this->assertStringContainsString('Unknown source record', $unknown['unresolved'][0]['reason']);
        $this->assertSame([], $duplicate['proposals']);
        $this->assertCount(2, $duplicate['unresolved']);
        $this->assertStringContainsString('appears more than once', $duplicate['unresolved'][0]['reason']);
    }

    private function proposal(array $overrides = []): array
    {
        return array_replace([
            'record_ids' => [11],
            'stock_class' => 'Lambs',
            'type' => 'sale',
            'confidence' => 0.92,
            'quantity' => 12,
            'note' => 'Docket S-100',
            'include' => false,
            'flag' => 'duplicate',
            'reasoning' => 'The same docket appears twice.',
        ], $overrides);
    }

    private function classes()
    {
        return collect([new StockClass(['id' => 7, 'name' => 'Lambs'])]);
    }

    private function records()
    {
        return collect([
            new StockRecord(['id' => 11, 'recorded_on' => '2026-05-01', 'source' => 'Diary', 'body' => 'Sold 12 lambs']),
            new StockRecord(['id' => 12, 'recorded_on' => '2026-05-02', 'source' => 'Sale docket', 'body' => 'Sold 12 lambs']),
        ]);
    }
}

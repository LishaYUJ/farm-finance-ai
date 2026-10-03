<?php

namespace App\Services;

use JsonException;
use RuntimeException;

/**
 * Provides a zero-cost, deterministic demonstration using only seeded data.
 */
class DemoStockResponder
{
    public const FIXTURE = 'data/demo/stock_parse_response.json';

    public function parseResponse(): array
    {
        $contents = file_get_contents(base_path(self::FIXTURE));
        if ($contents === false) {
            throw new RuntimeException('The demo stock response fixture could not be read.', 500);
        }

        try {
            $response = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('The demo stock response fixture is not valid JSON.', 500, $exception);
        }

        if (! is_array($response)) {
            throw new RuntimeException('The demo stock response fixture must contain a JSON object.', 500);
        }

        return $response;
    }

    /**
     * Render only the server-validated request data; no report totals are stored
     * in the fixture, so manual changes remain visible in the demo narrative.
     */
    public function report(array $report): string
    {
        $classes = $report['classes'];
        $reconciled = count(array_filter(
            $classes,
            fn (array $stockClass) => $stockClass['status'] === 'reconciled',
        ));

        $lines = [
            "Overall summary: {$reconciled} of ".count($classes).' stock classes reconcile to the recorded closing count.',
            'The figures below are deterministic application calculations; no balancing movements were invented.',
        ];

        foreach ($classes as $stockClass) {
            $lines[] = '';
            $lines[] = $stockClass['stock_class'];
            $lines[] = 'Opening: '.$this->number($stockClass['opening']);
            $lines[] = 'Births: '.$this->number($stockClass['births']);
            $lines[] = 'Purchases: '.$this->number($stockClass['purchases']);
            $lines[] = 'Deaths: '.$this->number($stockClass['deaths']);
            $lines[] = 'Sales: '.$this->number($stockClass['sales']);
            $lines[] = 'Calculated closing: '.$this->number($stockClass['calculated_closing']);
            $lines[] = 'Recorded closing: '.$this->number($stockClass['recorded_closing']);
            $lines[] = 'Difference: '.$this->signedNumber($stockClass['difference']);
            $lines[] = 'Status: '.$stockClass['status'];
        }

        $lines[] = '';
        $lines[] = 'Needs review';
        if ($report['review_proposals'] === [] && $report['unresolved'] === []) {
            $lines[] = 'None.';
        }

        foreach ($report['review_proposals'] as $proposal) {
            $records = implode(', ', $proposal['record_ids']);
            $confidence = $proposal['confidence'] === null ? 'not supplied' : $proposal['confidence'];
            $flag = $proposal['flag'] ?? 'none';
            $lines[] = "Records {$records}: {$proposal['stock_class']} {$proposal['type']} × {$this->number($proposal['quantity'])}; confidence {$confidence}; flag {$flag}. {$proposal['reasoning']}";
        }

        if ($report['unresolved'] !== []) {
            $lines[] = '';
            $lines[] = 'Unresolved records';
            foreach ($report['unresolved'] as $item) {
                $records = $item['record_ids'] === [] ? 'not supplied' : implode(', ', $item['record_ids']);
                $lines[] = "Records {$records}: {$item['reason']}";
            }
        }

        $lines[] = '';
        $lines[] = 'Already keyed';
        if ($report['already_keyed'] === []) {
            $lines[] = 'None.';
        }
        foreach ($report['already_keyed'] as $proposal) {
            $records = implode(', ', $proposal['record_ids']);
            $lines[] = "Records {$records}: {$proposal['stock_class']} {$proposal['type']} × {$this->number($proposal['quantity'])} ({$proposal['note']}). This movement remains counted only through the existing keyed entry.";
        }

        return implode("\n", $lines);
    }

    private function number(int $number): string
    {
        return number_format($number);
    }

    private function signedNumber(int $number): string
    {
        return ($number > 0 ? '+' : '').$this->number($number);
    }
}

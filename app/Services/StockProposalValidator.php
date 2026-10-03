<?php

namespace App\Services;

use Illuminate\Support\Collection;

/**
 * Treat model output as untrusted input and resolve it against database truth.
 */
class StockProposalValidator
{
    private const MOVEMENT_TYPES = ['birth', 'purchase', 'death', 'sale'];

    private const PROPOSAL_FLAGS = [
        'duplicate',
        'superseded',
        'same_event',
        'source_mislabelled',
        'quantity_estimated',
    ];

    public function validate(array $parsed, Collection $classes, Collection $records): array
    {
        $classIds = $classes->mapWithKeys(
            fn ($stockClass) => [mb_strtolower($stockClass->name) => $stockClass->id],
        );
        $recordIds = $records->pluck('id')->map(fn ($id) => (int) $id)->all();
        $proposalsInput = is_array($parsed['proposals'] ?? null) ? $parsed['proposals'] : [];
        $unresolvedInput = is_array($parsed['unresolved'] ?? null) ? $parsed['unresolved'] : [];
        $recordUseCounts = $this->recordUseCounts([...$proposalsInput, ...$unresolvedInput]);

        $proposals = [];
        $unresolved = [];

        if (! is_array($parsed['proposals'] ?? null)) {
            $unresolved[] = [
                'record_ids' => [],
                'reason' => 'Parser output did not contain a proposals array.',
            ];
        }

        foreach ($unresolvedInput as $item) {
            [$ids, $recordError] = $this->validateRecordIds($item['record_ids'] ?? null, $recordIds);
            $duplicateId = $this->firstRepeatedSource($ids, $recordUseCounts);

            if ($recordError || $duplicateId !== null) {
                $unresolved[] = [
                    'record_ids' => $ids,
                    'reason' => $recordError ?? "Source record {$duplicateId} appears more than once in parser output.",
                ];

                continue;
            }

            $reason = $item['reason'] ?? null;
            $unresolved[] = [
                'record_ids' => $ids,
                'reason' => is_string($reason) && trim($reason) !== ''
                    ? trim($reason)
                    : 'No reason given.',
            ];
        }

        foreach ($proposalsInput as $proposal) {
            if (! is_array($proposal)) {
                $unresolved[] = ['record_ids' => [], 'reason' => 'Proposal must be a JSON object.'];

                continue;
            }

            $className = is_string($proposal['stock_class'] ?? null)
                ? mb_strtolower(trim($proposal['stock_class']))
                : '';
            $type = is_string($proposal['type'] ?? null)
                ? mb_strtolower(trim($proposal['type']))
                : '';
            $quantity = $proposal['quantity'] ?? null;
            $include = $proposal['include'] ?? null;
            $confidence = $proposal['confidence'] ?? null;
            [$ids, $recordError] = $this->validateRecordIds($proposal['record_ids'] ?? null, $recordIds);
            $duplicateId = $this->firstRepeatedSource($ids, $recordUseCounts);

            $reject = match (true) {
                $recordError !== null => $recordError,
                $duplicateId !== null => "Source record {$duplicateId} appears more than once in parser output.",
                ! $classIds->has($className) => "Unknown stock class '{$className}'.",
                ! in_array($type, self::MOVEMENT_TYPES, true) => "Unknown movement type '{$type}'.",
                ! is_int($quantity) || $quantity < 1 => 'Quantity must be a positive JSON integer.',
                ! is_bool($include) => 'Include must be a JSON Boolean.',
                ! is_int($confidence) && ! is_float($confidence) => 'Confidence must be a JSON number from 0 to 1.',
                $confidence < 0 || $confidence > 1 => 'Confidence must be between 0 and 1.',
                ! is_string($proposal['note'] ?? null) || trim($proposal['note']) === '' => 'Note must be a non-empty string.',
                ! is_string($proposal['reasoning'] ?? null) || trim($proposal['reasoning']) === '' => 'Reasoning must be a non-empty string.',
                default => null,
            };

            $rawFlag = $proposal['flag'] ?? null;
            $flag = is_string($rawFlag) ? mb_strtolower(trim($rawFlag)) : $rawFlag;
            if ($reject === null && $flag !== null && ! in_array($flag, self::PROPOSAL_FLAGS, true)) {
                $reject = 'Flag must be null or a supported flag value.';
            }

            if ($reject !== null) {
                $unresolved[] = ['record_ids' => $ids, 'reason' => $reject];

                continue;
            }

            $stockClassId = $classIds[$className];
            $proposals[] = [
                'record_ids' => $ids,
                'stock_class' => $classes->firstWhere('id', $stockClassId)->name,
                'stock_class_id' => $stockClassId,
                'type' => $type,
                'confidence' => round((float) $confidence, 2),
                'quantity' => $quantity,
                'note' => trim($proposal['note']),
                'include' => $include,
                'flag' => $flag,
                'reasoning' => trim($proposal['reasoning']),
            ];
        }

        return ['proposals' => $proposals, 'unresolved' => $unresolved];
    }

    private function validateRecordIds(mixed $rawIds, array $knownIds): array
    {
        if (! is_array($rawIds) || $rawIds === []) {
            return [[], 'Record_ids must be a non-empty JSON array.'];
        }

        $ids = [];
        foreach ($rawIds as $id) {
            if (! is_int($id) || $id < 1) {
                return [$ids, 'Every record_id must be a positive JSON integer.'];
            }
            if (! in_array($id, $knownIds, true)) {
                return [$ids, "Unknown source record {$id}."];
            }
            if (in_array($id, $ids, true)) {
                return [$ids, "Source record {$id} is repeated within one item."];
            }

            $ids[] = $id;
        }

        return [$ids, null];
    }

    private function recordUseCounts(array $items): array
    {
        $counts = [];
        foreach ($items as $item) {
            if (! is_array($item) || ! is_array($item['record_ids'] ?? null)) {
                continue;
            }
            foreach (array_unique($item['record_ids'], SORT_REGULAR) as $id) {
                if (is_int($id)) {
                    $counts[$id] = ($counts[$id] ?? 0) + 1;
                }
            }
        }

        return $counts;
    }

    private function firstRepeatedSource(array $ids, array $recordUseCounts): ?int
    {
        foreach ($ids as $id) {
            if (($recordUseCounts[$id] ?? 0) > 1) {
                return $id;
            }
        }

        return null;
    }
}

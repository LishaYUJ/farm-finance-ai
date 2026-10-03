<?php

namespace App\Http\Controllers;

use App\Models\StockClass;
use App\Models\StockMovement;
use App\Models\StockRecord;
use App\Services\DemoStockResponder;
use App\Services\StockProposalValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class StockController extends Controller
{
    private const PARSE_SYSTEM_PROMPT = <<<'SYSTEM'
        You are a stock reconciliation clerk at a rural accounting practice in New Zealand. You read
        a farmer's raw records - diary notes, sale dockets, text messages - and turn them into stock
        movements. A movement is one of birth, purchase, death or sale, and the year must satisfy
        opening + births + purchases - deaths - sales = closing.

        Farmers keep messy records. The same sale turns up twice, a text message corrects an earlier
        one, a docket gets filed under the wrong heading, and quantities are often words rather than
        numbers. Flag anything you are not certain of rather than resolving it silently.

        Reply with JSON only. No prose, no markdown fences.
        SYSTEM;

    private const REPORT_SYSTEM_PROMPT = 'You are a careful livestock reconciliation assistant. Report only the supplied facts and calculations.';

    public function index(): JsonResponse
    {
        return response()->json([
            'classes' => StockClass::with('movements')->orderBy('id')->get(),
            'records' => StockRecord::orderBy('recorded_on')->orderBy('id')->get(),
        ]);
    }

    public function storeMovement(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'stock_class_id' => ['required', 'exists:stock_classes,id'],
            'type' => ['required', 'in:birth,purchase,death,sale'],
            'quantity' => ['required', 'integer', 'min:1'],
            'note' => ['nullable', 'string'],
        ]);

        return response()->json(StockMovement::create($validated), 201);
    }

    public function destroyMovement(StockMovement $stockMovement): JsonResponse
    {
        // Mis-keyed a movement? Delete it and key it again.
        $stockMovement->delete();

        return response()->json(['deleted' => true]);
    }

    /**
     * Read the whole paper trail and propose the movements it implies.
     *
     * Demo and Live sources converge before validation: everything is re-checked
     * against the database before it goes to the browser. Anything that fails a
     * check is reported in `unresolved` rather than quietly dropped.
     */
    public function parseRecords(
        StockProposalValidator $validator,
        DemoStockResponder $demoResponder,
    ): JsonResponse {
        $classes = StockClass::orderBy('id')->get();
        $records = StockRecord::orderBy('recorded_on')->orderBy('id')->get();
        $demoMode = (bool) config('services.anthropic.demo_mode');

        try {
            if ($demoMode) {
                $parsed = $demoResponder->parseResponse();
            } else {
                $text = AiController::ask($this->parsePrompt($classes, $records), self::PARSE_SYSTEM_PROMPT);
                $parsed = $this->decodeModelJson($text);
            }
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], $e->getCode() ?: 500);
        }

        if (! is_array($parsed)) {
            return response()->json([
                'error' => 'Anthropic did not return valid JSON. The raw reply is below.',
                'raw' => $text ?? null,
            ], 502);
        }

        return response()->json([
            ...$validator->validate($parsed, $classes, $records),
            'ai_mode' => $demoMode ? 'demo' : 'live',
            'meta' => $demoMode
                ? ['source' => 'recorded_fixture', 'fixture' => DemoStockResponder::FIXTURE]
                : ['provider' => 'anthropic', 'model' => config('services.anthropic.model')],
        ]);
    }

    /**
     * Generate narrative from a constrained reconciliation payload instead of
     * exposing the generic prompt proxy to a public browser.
     */
    public function generateReport(Request $request, DemoStockResponder $demoResponder): JsonResponse
    {
        $rules = [
            'report' => ['required', 'array'],
            'report.classes' => ['required', 'array', 'max:50'],
            'report.classes.*.stock_class' => ['required', 'string', 'max:100'],
            'report.classes.*.opening' => ['required', 'integer', 'min:0'],
            'report.classes.*.births' => ['required', 'integer', 'min:0'],
            'report.classes.*.purchases' => ['required', 'integer', 'min:0'],
            'report.classes.*.deaths' => ['required', 'integer', 'min:0'],
            'report.classes.*.sales' => ['required', 'integer', 'min:0'],
            'report.classes.*.calculated_closing' => ['required', 'integer'],
            'report.classes.*.recorded_closing' => ['required', 'integer', 'min:0'],
            'report.classes.*.difference' => ['required', 'integer'],
            'report.classes.*.status' => ['required', 'in:reconciled,unreconciled'],
            'report.accepted_proposals' => ['present', 'array', 'max:100'],
            'report.review_proposals' => ['present', 'array', 'max:100'],
            'report.unresolved' => ['present', 'array', 'max:100'],
            'report.unresolved.*.record_ids' => ['required', 'array', 'max:20'],
            'report.unresolved.*.record_ids.*' => ['integer', 'min:1'],
            'report.unresolved.*.reason' => ['required', 'string', 'max:1000'],
            'report.already_keyed' => ['present', 'array', 'max:100'],
            'report.counts' => ['required', 'array'],
            'report.counts.*' => ['integer', 'min:0'],
        ];

        foreach (['accepted_proposals', 'review_proposals', 'already_keyed'] as $key) {
            $rules["report.{$key}.*.record_ids"] = ['required', 'array', 'min:1', 'max:20'];
            $rules["report.{$key}.*.record_ids.*"] = ['integer', 'min:1'];
            $rules["report.{$key}.*.stock_class"] = ['required', 'string', 'max:100'];
            $rules["report.{$key}.*.stock_class_id"] = ['required', 'integer', 'min:1'];
            $rules["report.{$key}.*.type"] = ['required', 'in:birth,purchase,death,sale'];
            $rules["report.{$key}.*.confidence"] = ['nullable', 'numeric', 'between:0,1'];
            $rules["report.{$key}.*.quantity"] = ['required', 'integer', 'min:1'];
            $rules["report.{$key}.*.note"] = ['required', 'string', 'max:500'];
            $rules["report.{$key}.*.include"] = ['required', 'boolean'];
            $rules["report.{$key}.*.flag"] = ['nullable', 'in:duplicate,superseded,same_event,source_mislabelled,quantity_estimated'];
            $rules["report.{$key}.*.reasoning"] = ['required', 'string', 'max:1000'];
        }

        $validated = $request->validate($rules);

        foreach ($validated['report']['classes'] as $index => $stockClass) {
            $calculated = $stockClass['opening'] + $stockClass['births'] + $stockClass['purchases']
                - $stockClass['deaths'] - $stockClass['sales'];
            $difference = $calculated - $stockClass['recorded_closing'];
            $status = $difference === 0 ? 'reconciled' : 'unreconciled';

            if ($stockClass['calculated_closing'] !== $calculated ||
                $stockClass['difference'] !== $difference ||
                $stockClass['status'] !== $status) {
                throw ValidationException::withMessages([
                    "report.classes.{$index}" => 'Reconciliation totals are internally inconsistent.',
                ]);
            }
        }

        $demoMode = (bool) config('services.anthropic.demo_mode');

        try {
            $text = $demoMode
                ? $demoResponder->report($validated['report'])
                : AiController::ask(
                    $this->reportPrompt($validated['report']),
                    self::REPORT_SYSTEM_PROMPT,
                );
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], $e->getCode() ?: 500);
        }

        return response()->json([
            'text' => $text,
            'ai_mode' => $demoMode ? 'demo' : 'live',
            'meta' => $demoMode
                ? ['source' => 'deterministic_renderer']
                : ['provider' => 'anthropic', 'model' => config('services.anthropic.model')],
        ]);
    }

    private function decodeModelJson(string $text): mixed
    {
        // We ask for bare JSON, but models can still wrap it in a Markdown fence.
        $json = trim($text);
        if (str_starts_with($json, '```')) {
            $json = trim(preg_replace('/^```[a-z]*\s*|\s*```$/i', '', $json));
        }

        return json_decode($json, true);
    }

    private function reportPrompt(array $report): string
    {
        $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return <<<PROMPT
        Write a concise livestock reconciliation report for a New Zealand rural accountant.

        Treat every supplied number as fixed. Do not recalculate, alter, invent or omit a movement to make a stock class reconcile. Start with a short summary, give each stock class its own section, list every review_proposal and unresolved item under "Needs review", identify already_keyed items separately, and state exact differences for unreconciled classes. Use professional plain text without a Markdown table.

        Reconciliation data:
        {$json}
        PROMPT;
    }

    private function parsePrompt($classes, $records): string
    {
        $classLines = $classes
            ->map(fn ($c) => "- {$c->name}: opening {$c->opening_count}, farmer's recorded closing {$c->closing_count}")
            ->implode("\n");

        $recordLines = $records
            ->map(fn ($r) => "{$r->id} | {$r->recorded_on->format('Y-m-d')} | {$r->source} | {$r->body}")
            ->implode("\n");

        return <<<PROMPT
        Farm: Kahikatea Downs (sheep and beef). Stock year 1 Jul 2025 - 30 Jun 2026.

        Stock classes:
        {$classLines}

        The paper trail, as `id | date | source | body`:
        {$recordLines}

        How to read these records:
        - A docking tally is the count of lambs born. Calves "on the ground" are cattle births.
        - Home kill ("killed 2 lambs for the freezer") is a death, not a sale.
        - Cull ewes are still Ewes; cull cows, calves, steers and heifers are all Cattle.
        - Trust the body over the source label. A row labelled "Sale docket" whose body says
          "purchase docket" is a purchase, which increases the count rather than decreasing it.
        - The same event can appear twice: the same docket number on two dates, or a docket and a
          diary note describing the same head count within a week or two. Emit it ONCE, listing
          every record id it came from, with flag "duplicate" (same docket) or "same_event"
          (docket plus diary), and include: false so a human confirms before it is keyed.
        - A later record can correct an earlier one. Emit the corrected quantity with flag
          "superseded" and include: false.
        - Turn vague quantities into numbers where the wording supports it ("a dozen" is 12, "Two"
          is 2) and flag those "quantity_estimated", keeping include: true with lower confidence.
          Where no defensible number exists, put the record in "unresolved" instead of guessing.
          Never invent a head count to make a tally balance.
        - note must cite where it came from, e.g. "Docket S-40102, 12 Dec 2025" or "Diary 19 Oct 2025".
        - confidence is how sure you are of the movement TYPE, from 0 to 1.

        "unresolved" is only for records you could not turn into a movement at all. If you already
        emitted a record as a flagged proposal, do not repeat it in "unresolved". Every entry must
        name at least one record id: no summaries, totals or reconciliation workings go in there.

        The opening and closing counts above are a sanity check, not a target. Never adjust or invent
        a quantity to make a tally balance - if the movements do not reach the recorded closing
        count, leave it unbalanced.

        Return JSON in exactly this shape and nothing else. Keep each "reasoning" to one sentence.

        {
          "proposals": [
            {
              "record_ids": [3],
              "stock_class": "Lambs",
              "type": "birth",
              "confidence": 0.98,
              "quantity": 1240,
              "note": "Docking tally, diary 6 Oct 2025",
              "include": true,
              "flag": null,
              "reasoning": "A docking tally is the count of lambs born."
            }
          ],
          "unresolved": [
            { "record_ids": [21], "reason": "Why this record could not be turned into a movement." }
          ]
        }
        PROMPT;
    }
}

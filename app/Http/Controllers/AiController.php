<?php

namespace App\Http\Controllers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use RuntimeException;

/**
 * The AI proxy. The frontend posts { system?, prompt } here; we call the
 * Anthropic Messages API with the key from .env and return { text }.
 *
 * The key stays on the server. Never call the Anthropic API from browser
 * code, and never put the key anywhere in the frontend.
 */
class AiController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        if (! config('services.anthropic.allow_generic_endpoint')) {
            return response()->json([
                'error' => 'The generic AI endpoint is disabled. Use a task-specific endpoint.',
            ], 403);
        }

        $validated = $request->validate([
            'system' => ['nullable', 'string', 'max:'.config('services.anthropic.max_system_chars')],
            'prompt' => ['required', 'string', 'max:'.config('services.anthropic.max_prompt_chars')],
        ]);

        try {
            $text = self::ask($validated['prompt'], $validated['system'] ?? null);
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], $e->getCode() ?: 500);
        }

        return response()->json([
            'text' => $text,
            'ai_mode' => 'live',
            'meta' => ['provider' => 'anthropic', 'model' => config('services.anthropic.model')],
        ]);
    }

    /**
     * Call Claude and return the reply text. Other controllers use this when
     * they build a prompt server-side rather than taking one from the browser.
     *
     * Throws a RuntimeException whose code is the HTTP status to respond with.
     */
    public static function ask(string $prompt, ?string $system = null): string
    {
        // A long generation easily outruns PHP's default 30s max_execution_time,
        // which kills the request mid-call regardless of the HTTP timeout below.
        set_time_limit(180);

        if (config('services.anthropic.demo_mode')) {
            throw new RuntimeException(
                'Live AI is disabled in demo mode. Set AI_DEMO_MODE=false only in a protected environment.',
                503,
            );
        }

        if (mb_strlen($prompt) > config('services.anthropic.max_prompt_chars') ||
            mb_strlen($system ?? '') > config('services.anthropic.max_system_chars')) {
            throw new RuntimeException('AI request exceeds the configured input limit.', 422);
        }

        $dailyLimit = max(1, (int) config('services.anthropic.daily_request_limit'));
        $dailyKey = 'anthropic:daily:'.now()->format('Y-m-d');
        if (RateLimiter::tooManyAttempts($dailyKey, $dailyLimit)) {
            throw new RuntimeException('The daily AI request budget has been reached.', 429);
        }

        $apiKey = config('services.anthropic.key');
        if (! $apiKey || str_starts_with($apiKey, 'sk-ant-your-key')) {
            throw new RuntimeException(
                'Anthropic is not configured. Set ANTHROPIC_API_KEY in .env and clear the configuration cache.',
                503,
            );
        }

        $body = [
            'model' => config('services.anthropic.model'),
            'max_tokens' => max(1, (int) config('services.anthropic.max_tokens')),
            'messages' => [
                ['role' => 'user', 'content' => $prompt],
            ],
        ];
        if (! empty($system)) {
            $body['system'] = $system;
        }

        RateLimiter::hit($dailyKey, max(1, now()->diffInSeconds(now()->endOfDay()) + 1));

        try {
            $response = Http::withHeaders([
                'x-api-key' => $apiKey,
                'anthropic-version' => '2023-06-01',
            ])->timeout(120)->post('https://api.anthropic.com/v1/messages', $body);
        } catch (ConnectionException $e) {
            throw new RuntimeException('Could not reach the Anthropic API: '.$e->getMessage(), 502);
        }

        if ($response->failed()) {
            throw new RuntimeException(
                $response->json('error.message') ?? 'Anthropic API request failed.',
                $response->status(),
            );
        }

        // The response content is a list of blocks; concatenate the text ones.
        return collect($response->json('content'))
            ->where('type', 'text')
            ->pluck('text')
            ->implode('');
    }
}

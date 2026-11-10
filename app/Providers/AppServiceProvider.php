<?php

namespace App\Providers;

use Anthropic\Client;
use App\Ai\ClaudeApiGenerator;
use App\Ai\ClaudeCodeGenerator;
use App\Ai\NullTextGenerator;
use App\Ai\TextGenerator;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(TextGenerator::class, fn (): TextGenerator => $this->textGenerator());
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureRateLimiting();
    }

    /**
     * The TextGenerator chosen by config('gitchronicle.ai_driver'):
     *
     * - "claude-code": the Claude Code CLI with this computer's subscription;
     * - "claude": the Anthropic API, but only when an API key is set (without one the SDK would look
     *   for other credentials on this computer, which must never happen by accident);
     * - anything else ("none"): AI is off, NullTextGenerator throws AiDisabled and features work offline.
     *
     * The API client gets the key explicitly, a 5-minute timeout and no automatic retries
     * (the queued jobs decide when to try again, see AiException::$retryable).
     */
    private function textGenerator(): TextGenerator
    {
        $settings = config('gitchronicle.anthropic');

        return match (config('gitchronicle.ai_driver')) {
            'claude-code' => new ClaudeCodeGenerator(
                binary: config('gitchronicle.claude_code_bin'),
                model: $settings['model'],
                effort: $settings['effort'],
            ),
            'claude' => filled($settings['api_key'])
                ? new ClaudeApiGenerator(
                    client: new Client(apiKey: $settings['api_key'], requestOptions: ['timeout' => 300, 'maxRetries' => 0]),
                    model: $settings['model'],
                    effort: $settings['effort'],
                    maxTokens: $settings['max_tokens'],
                )
                : new NullTextGenerator,
            default => new NullTextGenerator,
        };
    }

    /**
     * "repositories": at most 10 requests per minute for each user, or for each
     * IP address when the visitor is a guest (guests may submit the form too),
     * because every new repository and every „Reîncearcă” starts a clone job.
     * Adding and retrying share the same 10 requests (same limiter, same key).
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('repositories', function (Request $request) {
            return Limit::perMinute(10)->by($request->user()?->id ?: $request->ip());
        });
    }
}

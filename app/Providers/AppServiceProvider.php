<?php

namespace App\Providers;

use Anthropic\Client;
use App\Ai\ClaudeApiGenerator;
use App\Ai\ClaudeCodeGenerator;
use App\Ai\NullTextGenerator;
use App\Ai\TextGenerator;
use App\Media\AudioMixer;
use App\Media\Narrator;
use App\Search\CodeSearch;
use App\Search\LikeCodeSearch;
use App\Search\SqliteCodeSearch;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\DevCommands;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

        // The voice and the sound of the presentation video use the paths of config('gitchronicle.media').
        $this->app->bind(Narrator::class, fn (): Narrator => Narrator::fromConfig());
        $this->app->bind(AudioMixer::class, fn (): AudioMixer => AudioMixer::fromConfig());

        // The assistant's code search (Faza 8): SQLite's full-text index (FTS5) when the database is SQLite,
        // plain LIKE queries on any other database.
        $this->app->bind(CodeSearch::class, fn (): CodeSearch => DB::connection()->getDriverName() === 'sqlite'
            ? new SqliteCodeSearch
            : new LikeCodeSearch);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureRateLimiting();

        // `php artisan dev` (composer run dev) also starts a worker for the "media" queue, next to the
        // default one, so a video being rendered never keeps a repository waiting to be processed.
        if ($this->app->runningInConsole()) {
            DevCommands::artisan('queue:listen --queue='.config('gitchronicle.media.queue').' --tries=1 --timeout=0', 'media');
        }
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
     *
     * "front-door": at most 30 visits per minute for each IP address to {host}/owner/name, because an unknown
     * repository costs one call to the GitHub API (answers are cached for 10 minutes).
     *
     * "assistant": at most 20 questions per hour for each IP address, logged in or not
     * (the assistant is public), because every question can start a call to Claude.
     *
     * "webhooks": at most 60 deliveries per minute for each IP address. GitHub sends one per push,
     * so the limit only stops someone who hammers the address with unsigned requests.
     *
     * "consulting": at most 6 analyses per hour for each user („Rulează analiza”), because each one runs
     * Semgrep, OSV-Scanner and scc on a clone for up to a few minutes.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('repositories', function (Request $request) {
            return Limit::perMinute(10)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('front-door', function (Request $request) {
            return Limit::perMinute(30)->by($request->ip());
        });

        RateLimiter::for('assistant', function (Request $request) {
            return Limit::perHour(20)->by($request->ip());
        });

        RateLimiter::for('webhooks', function (Request $request) {
            return Limit::perMinute(60)->by($request->ip());
        });

        RateLimiter::for('consulting', function (Request $request) {
            return Limit::perHour(6)->by($request->user()?->id ?: $request->ip());
        });
    }
}

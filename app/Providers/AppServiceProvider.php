<?php

namespace App\Providers;

use Anthropic\Client;
use App\Ai\BudgetedTextGenerator;
use App\Ai\ClaudeApiGenerator;
use App\Ai\ClaudeCodeGenerator;
use App\Ai\NullTextGenerator;
use App\Ai\TextGenerator;
use App\Media\AudioMixer;
use App\Search\CodeSearch;
use App\Search\LikeCodeSearch;
use App\Search\SqliteCodeSearch;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\DevCommands;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->turnOffDevelopmentTools();

        // Behind a reverse proxy only the proxies named in TRUSTED_PROXIES may tell the app the visitor's address
        // and whether the request was HTTPS; with the list empty nothing is trusted (security audit WEB-LAYER-05).
        if (config('gitchronicle.http.trusted_proxies') !== []) {
            TrustProxies::at(config('gitchronicle.http.trusted_proxies'));
        }

        $this->app->bind(TextGenerator::class, fn (): TextGenerator => $this->textGenerator());

        // The sound of the presentation video uses the paths of config('gitchronicle.media').
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

        // At least 10 characters with a letter and a number; in production also not one found in a known data
        // leak (that check asks the haveibeenpwned.com range API, so it is off elsewhere: tests and development
        // run offline). Security audit WEB-LAYER-07.
        Password::defaults(function (): Password {
            $rule = Password::min(config('gitchronicle.auth.min_password_length'))->letters()->numbers();

            return $this->app->isProduction() ? $rule->uncompromised() : $rule;
        });

        // `php artisan dev` (composer run dev) also starts a worker for the "media" queue, next to the
        // default one, so a video being rendered never keeps a repository waiting to be processed.
        if ($this->app->runningInConsole()) {
            DevCommands::artisan('queue:listen --queue='.config('gitchronicle.media.queue').' --tries=1 --timeout=0', 'media');
        }
    }

    /**
     * Outside the "local" environment the development tools stay off, whatever APP_DEBUG or the .env say
     * (security audit WEB-LAYER-01, WEB-LAYER-02): Inertia's DevTools keep the props of every visitor's page
     * and serve them without a login, and Laravel Boost opens a public route that writes to a log file.
     * Both read their setting when they boot, which is after every provider's register(), so it is set here.
     */
    private function turnOffDevelopmentTools(): void
    {
        if (! $this->app->environment('local')) {
            config(['boost.enabled' => false, 'inertia.devtools.enabled' => false]);
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
     *
     * A real driver is wrapped in BudgetedTextGenerator, so every Claude call of every feature passes
     * the site's daily budget (config gitchronicle.limits.ai_daily_calls) in this one place.
     */
    private function textGenerator(): TextGenerator
    {
        $settings = config('gitchronicle.anthropic');

        $generator = match (config('gitchronicle.ai_driver')) {
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

        return $generator instanceof NullTextGenerator
            ? $generator
            : new BudgetedTextGenerator($generator, config('gitchronicle.limits.ai_daily_calls'));
    }

    /**
     * "repositories": at most 10 requests per minute for each user, or for each
     * IP address when the visitor is a guest (guests may submit the form too),
     * because every new repository and every „Reîncearcă” starts a clone job. Administrators (users.is_admin)
     * are exempt from this per-user limit; the limit for the whole site still applies to them.
     * Adding and retrying share the same 10 requests (same limiter, same key).
     * Logged-in users also share one limit for the whole site per hour, so many accounts
     * cannot add up to more; guests do not count there, because they never create anything
     * (they are sent to log in first).
     *
     * "front-door": at most 30 visits per minute for each IP address to {host}/owner/name, because an unknown
     * repository costs one call to the GitHub API (answers are cached for 10 minutes).
     *
     * "assistant": at most 20 questions per hour for each IP address, logged in or not
     * (the assistant is public; a signed-in administrator is exempt from the per-IP limit), because every question can start a call to Claude; plus a limit
     * for the whole site per minute and per day, so many addresses cannot add up to more.
     *
     * "webhooks": at most 60 deliveries per minute for each IP address. GitHub sends one per push,
     * so the limit only stops someone who hammers the address with unsigned requests.
     *
     * "consulting": at most 6 analyses per hour for each user („Rulează analiza”), because each one runs
     * Semgrep, OSV-Scanner and scc on a clone for up to a few minutes.
     *
     * Every number is in config/gitchronicle.php ("limits"). The limits of one limiter need different
     * keys ("user:…", "all-hour", …): limits with the same key would share one counter.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('repositories', function (Request $request) {
            $limits = config('gitchronicle.limits.repositories');
            $perVisitor = Limit::perMinute($limits['per_user_per_minute'])
                ->by($request->user() ? 'user:'.$request->user()->id : 'ip:'.$request->ip());

            $global = Limit::perHour($limits['global_per_hour'])->by('all-hour');

            if ($request->user()?->is_admin) {
                return $global;
            }

            return $request->user() === null ? $perVisitor : [$perVisitor, $global];
        });

        // "login": at most 10 attempts per minute from one address for ALL emails; the lock of LoginRequest counts
        // only the failures for one email, so without this one address could try any number of accounts.
        RateLimiter::for('login', function (Request $request) {
            return Limit::perMinute(config('gitchronicle.limits.login.per_ip_per_minute'))->by('ip:'.$request->ip());
        });

        RateLimiter::for('front-door', function (Request $request) {
            return Limit::perMinute(30)->by($request->ip());
        });

        RateLimiter::for('assistant', function (Request $request) {
            $limits = config('gitchronicle.limits.assistant');
            $busy = fn (Request $request, array $headers) => response()->json([
                'message' => __('assistant.busy'),
            ], 429, $headers);

            $global = [
                Limit::perMinute($limits['global_per_minute'])->by('all-minute')->response($busy),
                Limit::perDay($limits['global_per_day'])->by('all-day')->response($busy),
            ];

            return $request->user()?->is_admin
                ? $global
                : [Limit::perHour($limits['per_ip_per_hour'])->by('ip:'.$request->ip()), ...$global];
        });

        RateLimiter::for('webhooks', function (Request $request) {
            return Limit::perMinute(60)->by($request->ip());
        });

        RateLimiter::for('consulting', function (Request $request) {
            return Limit::perHour(6)->by($request->user()?->id ?: $request->ip());
        });
    }
}

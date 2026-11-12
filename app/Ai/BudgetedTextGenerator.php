<?php

namespace App\Ai;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * The daily budget of Claude calls for the whole site: the one place every AI call passes through
 * (AppServiceProvider wraps the real driver in it), whichever feature makes it — documentation pages,
 * flows, the video script or the assistant.
 *
 * A budget of 0 means no cap (the default in the local environment). Each call is counted in the cache under the day's date before it is sent (a failed call counts too:
 * it may have used the subscription). Over config('gitchronicle.limits.ai_daily_calls') the call is not
 * sent and AiException is thrown, which every caller already handles with its offline mode (template
 * pages, no flows, a template video script, the fragments in the assistant). It is retryable, so a
 * documentation run stops asking Claude for the rest of the run instead of trying page after page.
 *
 * Why: the site is public, and without one global budget many accounts or addresses could spend the
 * whole subscription (security audit AI-LAYER-02).
 */
final class BudgetedTextGenerator implements TextGenerator
{
    /**
     * The message when the day's budget is used up. It ends up in the stale reason of the documentation pages
     * written from templates instead, so the owner sees why on the page (and in the assistant's notice).
     */
    public static function message(): string
    {
        return __('ai.budget_reached');
    }

    /**
     * Wrap the real driver with the number of calls allowed per day.
     */
    public function __construct(
        private TextGenerator $generator,
        private int $dailyCalls,
    ) {}

    /**
     * Count the call and send it, or refuse it when the day's budget is used up.
     *
     * @throws AiException when the budget is used up, or whatever the wrapped driver throws
     */
    public function generate(string $system, string $user, ?int $timeoutSeconds = null): AiResult
    {
        if ($this->dailyCalls > 0 && self::countCall() > $this->dailyCalls) {
            Log::warning('The daily AI budget is used up; the call was not sent.', ['daily_calls' => $this->dailyCalls]);

            throw new AiException(self::message(), retryable: true);
        }

        return $this->generator->generate($system, $user, $timeoutSeconds);
    }

    /**
     * How many calls were made today, this one included. add() creates the day's counter only once,
     * and increment() is atomic, so two workers never read the same number.
     */
    private static function countCall(): int
    {
        $key = self::cacheKey();

        Cache::add($key, 0, now()->addDays(2));

        return (int) Cache::increment($key);
    }

    /**
     * The cache key of today's counter, e.g. "ai-calls:2026-11-11".
     */
    private static function cacheKey(): string
    {
        return 'ai-calls:'.now()->toDateString();
    }
}

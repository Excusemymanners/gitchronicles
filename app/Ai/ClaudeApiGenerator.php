<?php

namespace App\Ai;

use Anthropic\Beta\Messages\BetaTextBlock;
use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\InternalServerException;
use Anthropic\Core\Exceptions\RateLimitException;
use Anthropic\EnforcedSpendLimitReachedException;
use Illuminate\Support\Facades\Log;

/**
 * Writes the text with the Anthropic API, through the official PHP SDK (AI_DRIVER=claude).
 * Every call is billed to the API key, so the app uses it only when chosen explicitly.
 *
 * The request (one Messages API call, no streaming):
 *
 * - model, effort and max_tokens come from config/gitchronicle.php ("anthropic");
 * - thinking is not sent: on claude-opus-5-5 it is always on and "effort" sets how deep it goes;
 * - the system prompt is a text block marked for the prompt cache (cacheControl "ephemeral"), so
 *   calls with the same instructions (e.g. every module page of a repository) pay less for it;
 * - fallbacks "default" (beta server-side-fallback-2026-07-01, on the beta endpoint): if the model
 *   declines for policy reasons, the API itself retries on the fallback model it recommends.
 *
 * The answer is read only after checking stop_reason: "refusal" (declined) and "max_tokens" (cut off
 * in the middle) are errors, never half texts saved as documentation.
 *
 * The SDK does not retry by itself (maxRetries 0, see AppServiceProvider): AiException::$retryable tells
 * the queued job whether to try again after its backoff, so retries are decided in one place.
 */
final class ClaudeApiGenerator implements TextGenerator
{
    /**
     * The beta that enables the "fallbacks" parameter on the Messages API.
     */
    private const FALLBACK_BETA = 'server-side-fallback-2026-07-01';

    /**
     * Create a new generator. The client and the values come from AppServiceProvider.
     */
    public function __construct(
        private Client $client,
        private string $model,
        private string $effort,
        private int $maxTokens,
    ) {}

    /**
     * Send one request and return the answer.
     *
     * @throws AiException when the API fails or the answer cannot be used
     */
    public function generate(string $system, string $user): AiResult
    {
        try {
            $message = $this->client->beta->messages->create(
                maxTokens: $this->maxTokens,
                messages: [['role' => 'user', 'content' => $user]],
                model: $this->model,
                fallbacks: 'default',
                outputConfig: ['effort' => $this->effort],
                system: [['type' => 'text', 'text' => $system, 'cacheControl' => ['type' => 'ephemeral']]],
                betas: [self::FALLBACK_BETA],
            );
        } catch (APIException $exception) {
            throw $this->exceptionFor($exception);
        }

        if ($message->stopReason === 'refusal') {
            Log::warning('Claude declined the request.', [
                'model' => $message->model,
                'category' => $message->stopDetails?->category,
            ]);

            throw new AiException(AiException::REFUSED_MESSAGE);
        }

        if ($message->stopReason === 'max_tokens') {
            Log::warning('Claude stopped at max_tokens.', ['model' => $message->model, 'max_tokens' => $this->maxTokens]);

            throw new AiException(AiException::TOO_LONG_MESSAGE);
        }

        $text = collect($message->content)
            ->filter(fn (object $block): bool => $block instanceof BetaTextBlock)
            ->map(fn (BetaTextBlock $block): string => $block->text)
            ->implode('');

        if (trim($text) === '') {
            Log::warning('Claude returned no text.', ['model' => $message->model, 'stop_reason' => $message->stopReason]);

            throw new AiException;
        }

        $usage = $message->usage;

        return new AiResult(
            text: $text,
            inputTokens: $usage->inputTokens + ($usage->cacheCreationInputTokens ?? 0) + ($usage->cacheReadInputTokens ?? 0),
            outputTokens: $usage->outputTokens,
            costUsdEquivalent: null,
            model: $message->model,
            driver: 'claude',
        );
    }

    /**
     * Turn an SDK error into the message for the interface, logging the details (never the prompts):
     *
     * - a spending limit of the organization: wait for a person, not for a retry;
     * - a rate limit, an overloaded or failing API, a lost connection: retryable later;
     * - anything else (a bad request, a wrong key, a missing model): a bug or a setting to fix.
     */
    private function exceptionFor(APIException $exception): AiException
    {
        Log::warning('Anthropic API call failed.', [
            'exception' => $exception::class,
            'status' => $exception->status,
            'type' => $exception instanceof APIStatusException ? $exception->getType() : null,
            'request_id' => $exception->getRequestID(),
        ]);

        return match (true) {
            $exception instanceof EnforcedSpendLimitReachedException => new AiException(AiException::LIMIT_MESSAGE, previous: $exception),
            $exception instanceof RateLimitException => new AiException(AiException::LIMIT_MESSAGE, retryable: true, previous: $exception),
            $exception instanceof InternalServerException,
            $exception instanceof APIConnectionException => new AiException(retryable: true, previous: $exception),
            default => new AiException(previous: $exception),
        };
    }
}

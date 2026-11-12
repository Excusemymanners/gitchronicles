<?php

namespace Tests\Support;

use App\Ai\AiException;
use App\Ai\AiResult;
use App\Ai\TextGenerator;
use PHPUnit\Framework\Assert;

/**
 * A TextGenerator for tests: it never calls Claude. It answers with the texts it was given
 * (one per call, in order; the last one repeats), or throws the exception it was given,
 * and remembers every request so a test can check what would have been sent.
 *
 * Usage: $this->app->instance(TextGenerator::class, $fake = new FakeTextGenerator(['# Text']));
 */
final class FakeTextGenerator implements TextGenerator
{
    /**
     * Every request received, in order.
     *
     * @var list<array{system: string, user: string, timeout: int|null}>
     */
    public array $requests = [];

    /**
     * @param  list<string>  $answers  the texts returned by the calls, in order
     * @param  AiException|null  $exception  thrown by every call instead of answering
     */
    public function __construct(
        private array $answers = ['Text generat în test.'],
        private ?AiException $exception = null,
    ) {}

    /**
     * Remember the request, then answer with the next text (or throw the configured exception).
     */
    public function generate(string $system, string $user, ?int $timeoutSeconds = null): AiResult
    {
        $this->requests[] = ['system' => $system, 'user' => $user, 'timeout' => $timeoutSeconds];

        if ($this->exception !== null) {
            throw $this->exception;
        }

        $text = $this->answers[count($this->requests) - 1] ?? $this->answers[array_key_last($this->answers)];

        return new AiResult(
            text: $text,
            inputTokens: 100,
            outputTokens: 50,
            costUsdEquivalent: null,
            model: 'claude-opus-5-5',
            driver: 'fake',
        );
    }

    /**
     * Assert how many requests were sent.
     */
    public function assertSentCount(int $count): void
    {
        Assert::assertCount($count, $this->requests, 'Unexpected number of AI requests.');
    }
}

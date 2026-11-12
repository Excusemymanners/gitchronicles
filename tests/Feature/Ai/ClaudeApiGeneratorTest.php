<?php

namespace Tests\Feature\Ai;

use Anthropic\Client;
use App\Ai\AiException;
use App\Ai\AiResult;
use App\Ai\ClaudeApiGenerator;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;
use Throwable;

/**
 * No request leaves the computer: the SDK client gets a Guzzle client whose MockHandler answers
 * in place of api.anthropic.com, and the history middleware keeps the requests the SDK built.
 */
class ClaudeApiGeneratorTest extends TestCase
{
    /**
     * The HTTP requests the SDK sent, as recorded by Guzzle's history middleware.
     *
     * @var list<array{request: RequestInterface}>
     */
    private array $sentRequests = [];

    public function test_sends_one_cached_system_prompt_with_effort_and_fallbacks_and_returns_the_text_and_usage(): void
    {
        $generator = $this->generatorAnswering($this->jsonResponse(200, File::get(base_path('tests/Fixtures/ai/anthropic-message.json'))));

        $result = $generator->generate('Scrii documentație tehnică în română.', 'Codul modulului app/Jobs: ...');

        $this->assertEquals(new AiResult(
            text: "## Rol\n\nModulul `app/Jobs` rulează pașii procesării în fundal.",
            inputTokens: 12 + 2048 + 4096,
            outputTokens: 230,
            costUsdEquivalent: null,
            model: 'claude-opus-5-5',
            driver: 'claude',
        ), $result);
        $this->assertCount(1, $this->sentRequests);
        $request = $this->sentRequests[0]['request'];
        $this->assertSame('https://api.anthropic.com/v1/messages', (string) $request->getUri());
        $this->assertSame('Bearer test-key', $request->getHeaderLine('Authorization'));
        $this->assertSame('server-side-fallback-2026-07-01', $request->getHeaderLine('anthropic-beta'));
        $this->assertEquals([
            'max_tokens' => 16000,
            'messages' => [['role' => 'user', 'content' => 'Codul modulului app/Jobs: ...']],
            'model' => 'claude-opus-5-5',
            'fallbacks' => 'default',
            'output_config' => ['effort' => 'medium'],
            'system' => [[
                'type' => 'text',
                'text' => 'Scrii documentație tehnică în română.',
                'cache_control' => ['type' => 'ephemeral'],
            ]],
        ], json_decode((string) $request->getBody(), associative: true));
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function unusableAnswers(): array
    {
        return [
            'refused' => [
                ['stop_reason' => 'refusal', 'stop_details' => ['type' => 'refusal', 'category' => 'cyber', 'explanation' => 'Declined.'], 'content' => []],
                'ai.refused',
            ],
            'cut at max_tokens' => [
                ['stop_reason' => 'max_tokens', 'content' => [['type' => 'text', 'text' => '## Rol']]],
                'ai.too_long',
            ],
            'without text' => [
                ['stop_reason' => 'end_turn', 'content' => []],
                AiException::GENERIC_MESSAGE,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $answer
     */
    #[DataProvider('unusableAnswers')]
    public function test_an_unusable_answer_is_rejected_instead_of_returned(array $answer, string $expectedMessage): void
    {
        $fixture = json_decode(File::get(base_path('tests/Fixtures/ai/anthropic-message.json')), associative: true);
        $generator = $this->generatorAnswering($this->jsonResponse(200, json_encode([...$fixture, ...$answer])));

        $exception = $this->generateExpectingFailure($generator);

        $this->assertSame(__($expectedMessage), $exception->getMessage());
        $this->assertNotSame($expectedMessage, $exception->getMessage());
        $this->assertFalse($exception->retryable);
    }

    /**
     * @return array<string, array{int, string, string, bool}>
     */
    public static function apiErrors(): array
    {
        return [
            'rate limit (429)' => [429, 'rate_limit_error', AiException::LIMIT_MESSAGE, true],
            'overloaded (529)' => [529, 'overloaded_error', AiException::GENERIC_MESSAGE, true],
            'server error (500)' => [500, 'api_error', AiException::GENERIC_MESSAGE, true],
            'bad request (400)' => [400, 'invalid_request_error', AiException::GENERIC_MESSAGE, false],
            'wrong key (401)' => [401, 'authentication_error', AiException::GENERIC_MESSAGE, false],
        ];
    }

    #[DataProvider('apiErrors')]
    public function test_an_api_error_becomes_a_message_for_the_interface_that_says_if_a_retry_can_help(
        int $status,
        string $errorType,
        string $expectedMessage,
        bool $expectedRetryable,
    ): void {
        $generator = $this->generatorAnswering($this->jsonResponse($status, json_encode([
            'type' => 'error',
            'error' => ['type' => $errorType, 'message' => 'Details for the log only.'],
        ])));

        $exception = $this->generateExpectingFailure($generator);

        $this->assertSame(__($expectedMessage), $exception->getMessage());
        $this->assertNotSame($expectedMessage, $exception->getMessage());
        $this->assertSame($expectedRetryable, $exception->retryable);
        $this->assertCount(1, $this->sentRequests, 'The SDK must not retry by itself.');
    }

    public function test_a_lost_connection_is_retryable(): void
    {
        $generator = $this->generatorAnswering(new ConnectException('Connection refused', new Request('POST', 'https://api.anthropic.com/v1/messages')));

        $exception = $this->generateExpectingFailure($generator);

        $this->assertSame(__(AiException::GENERIC_MESSAGE), $exception->getMessage());
        $this->assertTrue($exception->retryable);
    }

    /**
     * A generator whose SDK client talks to a Guzzle MockHandler that gives this answer,
     * with the same client options as the app (no automatic retries).
     */
    private function generatorAnswering(Response|Throwable $answer): ClaudeApiGenerator
    {
        $handler = HandlerStack::create(new MockHandler([$answer]));
        $handler->push(Middleware::history($this->sentRequests));

        $client = new Client(apiKey: 'test-key', requestOptions: [
            'transporter' => new GuzzleClient(['handler' => $handler]),
            'maxRetries' => 0,
        ]);

        return new ClaudeApiGenerator($client, model: 'claude-opus-5-5', effort: 'medium', maxTokens: 16000);
    }

    /**
     * An HTTP response with a JSON body, as the API sends it.
     */
    private function jsonResponse(int $status, string $body): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], $body);
    }

    /**
     * Call the generator and return the AiException it throws (the test fails if it does not throw).
     */
    private function generateExpectingFailure(ClaudeApiGenerator $generator): AiException
    {
        try {
            $generator->generate('Scrii documentație tehnică în română.', 'Codul modulului app/Jobs: ...');
        } catch (AiException $exception) {
            return $exception;
        }

        $this->fail('The generator did not throw an AiException.');
    }
}

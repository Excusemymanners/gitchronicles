<?php

namespace Tests\Feature;

use App\Enums\ProcessingTrigger;
use App\Jobs\ProcessRepository;
use App\Models\ProcessingRun;
use App\Models\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * POST /webhooks/github: only deliveries signed with the configured secret are believed, and only
 * a push to the main branch of a known repository starts an update.
 */
class GitHubWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'secretul-de-test';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.github.webhook_secret' => self::SECRET]);
        Queue::fake();
    }

    public function test_a_signed_push_to_the_main_branch_starts_an_update_with_the_webhook_trigger(): void
    {
        $repository = Repository::factory()->complete()->create(['owner' => 'Acme', 'name' => 'Magazin', 'default_branch' => 'main']);

        $response = $this->deliver('push', ['ref' => 'refs/heads/main', 'repository' => ['full_name' => 'acme/magazin']]);

        $response->assertAccepted()->assertExactJson(['status' => 'queued']);
        Queue::assertPushed(ProcessRepository::class, fn (ProcessRepository $job): bool => $job->repository->is($repository)
            && $job->trigger === ProcessingTrigger::Webhook
            && $job->full === false);
        $this->assertDatabaseHas('repositories', ['id' => $repository->id, 'status' => 'updating']);
    }

    public function test_a_push_for_an_incomplete_repository_starts_a_full_processing(): void
    {
        $repository = Repository::factory()->ready()->create(['owner' => 'acme', 'name' => 'magazin', 'default_branch' => 'main']);

        $this->deliver('push', ['ref' => 'refs/heads/main', 'repository' => ['full_name' => 'acme/magazin']])
            ->assertAccepted()
            ->assertExactJson(['status' => 'queued']);

        Queue::assertPushed(ProcessRepository::class, fn (ProcessRepository $job): bool => $job->repository->is($repository)
            && $job->trigger === ProcessingTrigger::Webhook
            && $job->full === true);
    }

    public function test_ping_is_answered_without_starting_anything(): void
    {
        $this->deliver('ping', ['zen' => 'Keep it logically awesome.'])->assertOk()->assertExactJson(['status' => 'pong']);

        Queue::assertNothingPushed();
    }

    public function test_a_wrong_or_missing_signature_is_refused(): void
    {
        Repository::factory()->ready()->create(['owner' => 'acme', 'name' => 'magazin', 'default_branch' => 'main']);
        $payload = json_encode(['ref' => 'refs/heads/main', 'repository' => ['full_name' => 'acme/magazin']]);

        $wrong = $this->call('POST', '/webhooks/github', server: [
            'HTTP_X_GITHUB_EVENT' => 'push',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $payload, 'alt-secret'),
            'CONTENT_TYPE' => 'application/json',
        ], content: $payload);
        $missing = $this->call('POST', '/webhooks/github', server: ['HTTP_X_GITHUB_EVENT' => 'push', 'CONTENT_TYPE' => 'application/json'], content: $payload);

        $wrong->assertUnauthorized();
        $missing->assertUnauthorized();
        Queue::assertNothingPushed();
    }

    public function test_without_a_configured_secret_the_address_does_not_exist(): void
    {
        config(['services.github.webhook_secret' => null]);

        $this->deliver('ping', [])->assertNotFound();
    }

    public function test_a_push_to_another_branch_is_ignored(): void
    {
        Repository::factory()->ready()->create(['owner' => 'acme', 'name' => 'magazin', 'default_branch' => 'main']);

        $this->deliver('push', ['ref' => 'refs/heads/feature/x', 'repository' => ['full_name' => 'acme/magazin']])
            ->assertAccepted()
            ->assertExactJson(['status' => 'ignored', 'reason' => 'not the main branch']);

        Queue::assertNothingPushed();
    }

    public function test_a_push_to_a_repository_that_was_not_added_is_ignored(): void
    {
        $this->deliver('push', ['ref' => 'refs/heads/main', 'repository' => ['full_name' => 'altcineva/altceva']])
            ->assertAccepted()
            ->assertExactJson(['status' => 'ignored', 'reason' => 'unknown repository']);

        Queue::assertNothingPushed();
    }

    public function test_other_events_and_a_repository_that_is_being_processed_are_ignored(): void
    {
        $repository = Repository::factory()->ready()->create(['owner' => 'acme', 'name' => 'magazin', 'default_branch' => 'main']);
        ProcessingRun::factory()->for($repository)->create();

        $this->deliver('issues', ['action' => 'opened'])->assertAccepted()->assertJson(['status' => 'ignored', 'reason' => 'not a push']);
        $this->deliver('push', ['ref' => 'refs/heads/main', 'repository' => ['full_name' => 'acme/magazin']])
            ->assertAccepted()
            ->assertJson(['status' => 'ignored', 'reason' => 'already processing']);

        Queue::assertNothingPushed();
    }

    public function test_a_repository_that_is_not_ready_is_left_alone(): void
    {
        Repository::factory()->failed()->create(['owner' => 'acme', 'name' => 'magazin', 'default_branch' => 'main']);
        Repository::factory()->create(['owner' => 'acme', 'name' => 'nou', 'status' => 'pending']);

        $this->deliver('push', ['ref' => 'refs/heads/main', 'repository' => ['full_name' => 'acme/magazin']])
            ->assertAccepted()
            ->assertExactJson(['status' => 'ignored', 'reason' => 'repository is failed']);
        $this->deliver('push', ['ref' => 'refs/heads/main', 'repository' => ['full_name' => 'acme/nou', 'default_branch' => 'main']])
            ->assertAccepted()
            ->assertExactJson(['status' => 'ignored', 'reason' => 'repository is pending']);

        Queue::assertNothingPushed();
        $this->assertDatabaseHas('repositories', ['name' => 'nou', 'status' => 'pending']);
    }

    public function test_pushes_in_a_burst_start_one_update_per_interval(): void
    {
        config(['gitchronicle.incremental.min_interval_minutes' => 10]);
        $repository = Repository::factory()->ready()->create(['owner' => 'acme', 'name' => 'magazin', 'default_branch' => 'main']);
        ProcessingRun::factory()->for($repository)->create(['status' => 'succeeded', 'started_at' => now()->subMinutes(4), 'finished_at' => now()->subMinutes(3)]);
        $push = ['ref' => 'refs/heads/main', 'repository' => ['full_name' => 'acme/magazin']];

        $this->deliver('push', $push)->assertAccepted()->assertExactJson(['status' => 'ignored', 'reason' => 'too soon after the last update']);
        Queue::assertNothingPushed();
        $this->assertDatabaseHas('repositories', ['id' => $repository->id, 'status' => 'ready']);

        $this->travel(7)->minutes();

        $this->deliver('push', $push)->assertAccepted()->assertExactJson(['status' => 'queued']);
        Queue::assertPushed(ProcessRepository::class, 1);
    }

    public function test_a_run_that_found_nothing_new_does_not_count_for_the_interval(): void
    {
        config(['gitchronicle.incremental.min_interval_minutes' => 10]);
        $repository = Repository::factory()->ready()->create(['owner' => 'acme', 'name' => 'magazin', 'default_branch' => 'main']);
        ProcessingRun::factory()->for($repository)->create(['status' => 'succeeded', 'started_at' => now()->subHour(), 'finished_at' => now()->subHour()]);
        ProcessingRun::factory()->for($repository)->create(['status' => 'skipped', 'started_at' => now()->subMinute(), 'finished_at' => now()->subMinute()]);

        $this->deliver('push', ['ref' => 'refs/heads/main', 'repository' => ['full_name' => 'acme/magazin']])
            ->assertAccepted()
            ->assertExactJson(['status' => 'queued']);
    }

    public function test_a_form_encoded_delivery_is_read_from_its_payload_field(): void
    {
        $repository = Repository::factory()->ready()->create(['owner' => 'acme', 'name' => 'magazin', 'default_branch' => 'main']);
        $form = ['payload' => json_encode(['ref' => 'refs/heads/main', 'repository' => ['full_name' => 'acme/magazin']])];
        $body = http_build_query($form);

        // PHP reads the form fields from the body; the test passes them as parameters too.
        $this->call('POST', '/webhooks/github', parameters: $form, server: [
            'HTTP_X_GITHUB_EVENT' => 'push',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, self::SECRET),
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
        ], content: $body)->assertAccepted()->assertExactJson(['status' => 'queued']);

        Queue::assertPushed(ProcessRepository::class, fn (ProcessRepository $job): bool => $job->repository->is($repository));
    }

    public function test_the_main_branch_named_by_github_wins_over_the_one_saved_at_the_last_clone(): void
    {
        Repository::factory()->ready()->create(['owner' => 'acme', 'name' => 'magazin', 'default_branch' => 'master']);

        $this->deliver('push', ['ref' => 'refs/heads/main', 'repository' => ['full_name' => 'acme/magazin', 'default_branch' => 'main']])
            ->assertAccepted()
            ->assertExactJson(['status' => 'queued']);
    }

    public function test_the_deliveries_are_rate_limited(): void
    {
        for ($delivery = 1; $delivery <= 60; $delivery++) {
            $this->deliver('ping', [])->assertOk();
        }

        $this->deliver('ping', [])->assertTooManyRequests();
    }

    /**
     * Send a delivery the way GitHub does: the JSON body signed with the secret, and the event in a header.
     * No CSRF token is sent: the address must work without one.
     *
     * @param  array<string, mixed>  $payload
     */
    private function deliver(string $event, array $payload): TestResponse
    {
        $body = json_encode($payload);

        return $this->call('POST', '/webhooks/github', server: [
            'HTTP_X_GITHUB_EVENT' => $event,
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, self::SECRET),
            'CONTENT_TYPE' => 'application/json',
        ], content: $body);
    }
}

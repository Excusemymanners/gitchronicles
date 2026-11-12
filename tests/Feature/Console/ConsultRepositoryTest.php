<?php

namespace Tests\Feature\Console;

use App\Jobs\GenerateConsultingReport;
use App\Models\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\FakesConsultingTools;
use Tests\TestCase;

/**
 * `php artisan repositories:consult {id} {--force} {--now}`: the „Consultanță” report by hand.
 */
class ConsultRepositoryTest extends TestCase
{
    use FakesConsultingTools;
    use RefreshDatabase;

    public function test_an_unknown_or_unready_repository_is_refused(): void
    {
        $this->artisan('repositories:consult', ['repository' => 999])
            ->expectsOutput('Nu există niciun repository cu id-ul 999.')
            ->assertFailed();

        $repository = Repository::factory()->create();

        $this->artisan('repositories:consult', ['repository' => $repository->id])->assertFailed();
    }

    public function test_the_analysis_is_queued_on_the_media_queue(): void
    {
        Queue::fake();
        $repository = Repository::factory()->ready()->create();

        $this->artisan('repositories:consult', ['repository' => $repository->id, '--force' => true])->assertSuccessful();

        Queue::assertPushedOn('media', GenerateConsultingReport::class, fn (GenerateConsultingReport $job): bool => $job->force);
    }

    public function test_now_runs_the_tools_in_the_terminal_and_says_what_each_one_did(): void
    {
        $this->fakeConsultingTools(missing: ['scc']);
        $repository = Repository::factory()->ready()->create();
        $this->makeClone($repository);

        $this->artisan('repositories:consult', ['repository' => $repository->id, '--now' => true])
            ->expectsOutputToContain('scc: nu e instalat (composer consulting:install).')
            ->expectsOutputToContain('OSV-Scanner 2.7.0: a rulat în')
            ->expectsOutputToContain('Semgrep 1.182.1: a rulat în')
            ->expectsOutputToContain('29 de probleme.')
            ->assertSuccessful();

        $this->assertSame('ready', $repository->consultingReports()->sole()->status->value);
    }

    public function test_now_fails_when_no_tool_is_installed(): void
    {
        $this->fakeConsultingTools(missing: ['semgrep', 'osv-scanner', 'scc']);
        $repository = Repository::factory()->ready()->create();
        $this->makeClone($repository);

        $this->artisan('repositories:consult', ['repository' => $repository->id, '--now' => true])
            ->expectsOutput('Raportul nu a putut fi făcut (not_installed).')
            ->assertFailed();
    }
}

<?php

namespace Tests\Feature\Consulting;

use App\Ai\TextGenerator;
use App\Consulting\RulesVersion;
use App\Jobs\GenerateConsultingReport;
use App\Models\ConsultingReport;
use App\Models\Repository;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\FakesConsultingTools;
use Tests\Support\FakeTextGenerator;
use Tests\TestCase;

/**
 * How current a report says it is: the dates of its data sources, its age, the daily refresh of old reports
 * (`repositories:consult --stale`) and the schedule entries. The rules-update script itself is never run here.
 */
class ConsultingFreshnessTest extends TestCase
{
    use FakesConsultingTools;
    use RefreshDatabase;

    public function test_the_page_gets_the_dates_of_every_data_source_the_age_and_the_version_table(): void
    {
        Carbon::setTestNow('2026-11-12 12:00:00');
        $repository = Repository::factory()->ready()->create();
        $report = ConsultingReport::factory()->for($repository)->ready()->withRuntimeVersions()->create(['source_sha' => $repository->last_sha, 'finished_at' => now()->subDays(9)]);
        $tools = $report->tools;
        $tools['semgrep']['details']['rules'] = ['updated_at' => '2026-11-09T03:30:00Z', 'revision' => 'elttam 2442685, 0xdea b994260', 'sources' => 2];
        $tools['osv-scanner']['details']['queried_at'] = '2026-11-03T12:00:00+00:00';
        $report->update(['tools' => $tools]);

        $this->get("/repositories/{$repository->id}/consulting")->assertInertia(fn (Assert $page) => $page
            ->where('consulting.age_days', 9)
            ->where('consulting.stale_after_days', 7)
            ->where('consulting.freshness', [
                'has_rules' => true,
                'rules' => ['updated_at' => '2026-11-09T03:30:00Z', 'revision' => 'elttam 2442685, 0xdea b994260', 'sources' => 2],
                'has_osv' => true,
                'osv_queried_at' => '2026-11-03T12:00:00+00:00',
                'has_runtime' => true,
                'runtime_fetched_at' => '2026-11-12T10:00:00+00:00',
            ])
            ->where('consulting.result.runtime.error', null)
            ->where('consulting.result.runtime.rows.0.status', 'end_of_life')
            ->where('consulting.result.runtime.rows.0.path', 'composer.json')
            ->where('consulting.result.runtime.rows.0.line', 9)
            ->where('consulting.result.runtime.rows.1.status', 'current')
            ->missing('consulting.tools.3.details.versions')
        );
    }

    public function test_rules_that_were_never_refreshed_have_no_date_and_a_failed_version_check_says_why(): void
    {
        $repository = Repository::factory()->ready()->create();
        $report = ConsultingReport::factory()->for($repository)->ready()->withRuntimeVersions()->create(['source_sha' => $repository->last_sha]);
        $tools = $report->tools;
        $tools['runtime']['status'] = 'failed';
        $tools['runtime']['error'] = 'source_unavailable';
        $tools['runtime']['error_value'] = 0;
        $tools['runtime']['details']['fetched_at'] = null;
        $report->update(['tools' => $tools]);

        $this->get("/repositories/{$repository->id}/consulting")->assertInertia(fn (Assert $page) => $page
            ->where('consulting.freshness.rules', null)
            ->where('consulting.freshness.has_runtime', false)
            ->where('consulting.result.runtime.error', 'source_unavailable')
            ->where('consulting.result.runtime.fetched_at', null)
        );
    }

    public function test_the_markdown_download_has_the_version_table_and_the_dates(): void
    {
        $repository = Repository::factory()->ready()->create();
        ConsultingReport::factory()->for($repository)->ready()->withRuntimeVersions()->create(['source_sha' => $repository->last_sha]);

        $markdown = $this->get("/repositories/{$repository->id}/consulting/report.md")->assertOk()->getContent();

        $this->assertStringContainsString('## Runtime and framework versions', $markdown);
        $this->assertStringContainsString('| PHP `7.4` (lowest allowed by `^7.4`) | `composer.json`:9 | end of life | 2022-11-28 | `7.4.33` | `Move to 8.5` |', $markdown);
        $this->assertStringContainsString('## How current this is', $markdown);
        $this->assertStringContainsString('Semgrep rules: as installed, never refreshed.', $markdown);
        $this->assertStringContainsString('fetched 2026-11-12 10:00 UTC', $markdown);
    }

    public function test_the_rules_date_and_revisions_come_from_the_file_the_update_script_writes(): void
    {
        $path = storage_path('framework/testing/rules-version.json');
        File::ensureDirectoryExists(dirname($path));
        config(['gitchronicle.consulting.rules_version_file' => $path]);

        $this->assertNull(RulesVersion::current());

        File::put($path, json_encode(['updated_at' => '2026-11-09T03:30:00Z', 'sources' => [
            ['name' => 'elttam', 'revision' => '2442685abcdef', 'license' => 'MIT'],
            ['name' => '0xdea', 'revision' => 'b9942606d3543e'],
        ]]));

        $this->assertSame(['updated_at' => '2026-11-09T03:30:00Z', 'revision' => 'elttam 2442685, 0xdea b994260', 'sources' => 2], RulesVersion::current());

        File::put($path, 'not json');
        $this->assertNull(RulesVersion::current());
        File::delete($path);
    }

    public function test_stale_queues_the_reports_older_than_the_given_days_and_leaves_the_others(): void
    {
        Queue::fake([GenerateConsultingReport::class]);
        $old = $this->repositoryWithReportAge(8);
        $recent = $this->repositoryWithReportAge(2);
        $working = $this->repositoryWithReportAge(30, 'running');
        Repository::factory()->ready()->create();
        $notReady = Repository::factory()->create();
        ConsultingReport::factory()->for($notReady)->ready()->create(['finished_at' => now()->subDays(20)]);

        $this->artisan('repositories:consult --stale')->expectsOutputToContain('1 report older than 7 days.')->assertSuccessful();

        Queue::assertPushed(GenerateConsultingReport::class, 1);
        Queue::assertPushed(GenerateConsultingReport::class, fn (GenerateConsultingReport $job): bool => $job->repository->is($old) && $job->force);
        $this->assertSame('pending', $old->consultingReports()->sole()->status->value);

        $this->artisan('repositories:consult --stale=1')->assertSuccessful();

        Queue::assertPushed(GenerateConsultingReport::class, fn (GenerateConsultingReport $job): bool => $job->repository->is($recent));
        Queue::assertNotPushed(GenerateConsultingReport::class, fn (GenerateConsultingReport $job): bool => $job->repository->is($working));
    }

    public function test_stale_rejects_a_nonsense_number_and_the_command_still_needs_an_id_without_it(): void
    {
        $this->artisan('repositories:consult --stale=0')->assertFailed();
        $this->artisan('repositories:consult')->expectsOutputToContain('Give the id of a repository')->assertFailed();
    }

    public function test_stale_with_now_reuses_the_stored_clone_and_asks_ai_again_only_when_the_facts_changed(): void
    {
        $this->fakeConsultingTools();
        $this->app->instance(TextGenerator::class, $ai = new FakeTextGenerator([
            'The scan found 3 critical and 8 high findings in this repository.',
            'The scan found 3 critical and 8 high findings in this repository, again.',
        ]));
        $repository = $this->repositoryWithReportAge(10);
        $this->makeClone($repository);
        File::put($repository->clonePath().'/marker.txt', 'the stored clone');

        $this->artisan('repositories:consult --stale --now')->assertSuccessful();

        $report = $repository->consultingReports()->sole();
        $this->assertSame('ready', $report->status->value);
        $this->assertTrue($report->finished_at->isToday());
        $this->assertFileExists($repository->clonePath().'/marker.txt');
        Process::assertNotRan(fn ($process): bool => basename((string) (((array) $process->command)[0] ?? '')) === 'git');
        $ai->assertSentCount(1);

        // The same facts a second time: the earlier summary stays and AI is not asked again.
        $report->update(['finished_at' => now()->subDays(10)]);
        $this->artisan('repositories:consult --stale --now')->assertSuccessful();

        $ai->assertSentCount(1);
        $this->assertSame('The scan found 3 critical and 8 high findings in this repository.', $report->fresh()->summary);
    }

    public function test_the_schedule_refreshes_the_rules_weekly_and_old_reports_daily(): void
    {
        $commands = collect(app(Schedule::class)->events())->map(fn (Event $event): string => (string) $event->command);

        $this->assertTrue($commands->contains(fn (string $command): bool => str_contains($command, 'scanners/update-rules.sh')));
        $this->assertTrue($commands->contains(fn (string $command): bool => str_contains($command, 'repositories:consult --stale')));

        $rules = collect(app(Schedule::class)->events())->first(fn (Event $event): bool => str_contains((string) $event->command, 'update-rules.sh'));
        $this->assertSame('30 3 * * 1', $rules->expression);
        $stale = collect(app(Schedule::class)->events())->first(fn (Event $event): bool => str_contains((string) $event->command, '--stale'));
        $this->assertSame('0 5 * * *', $stale->expression);
    }

    public function test_the_rules_refresh_can_be_turned_off(): void
    {
        config(['gitchronicle.consulting.rules_update_schedule' => false]);
        $rules = collect(app(Schedule::class)->events())->first(fn (Event $event): bool => str_contains((string) $event->command, 'update-rules.sh'));

        $this->assertFalse($rules->filtersPass($this->app));
    }

    private function repositoryWithReportAge(int $days, string $status = 'ready'): Repository
    {
        $repository = Repository::factory()->ready()->create();
        $factory = ConsultingReport::factory()->for($repository);

        $factory->ready()->create(['source_sha' => $repository->last_sha, 'status' => $status, 'finished_at' => now()->subDays($days)]);

        return $repository;
    }
}

<?php

namespace Tests\Feature\Consulting;

use App\Enums\FindingSeverity;
use App\Jobs\GenerateConsultingReport;
use App\Models\Commit;
use App\Models\CommitFile;
use App\Models\ConsultingFinding;
use App\Models\ConsultingReport;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The „Consultanță” page in each state, its actions („Rulează analiza”: guests, users, while working, the
 * limit), the Markdown download and the chapter and card of the workspace.
 */
class ConsultingPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_before_any_analysis_the_page_has_no_report_and_a_user_may_start_one(): void
    {
        $repository = Repository::factory()->ready()->create();

        $this->get("/repositories/{$repository->id}/consulting")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('repositories/consulting')
                ->where('consulting', null)
                ->where('can_run_consulting', false)
                ->where('workspace.elements.consulting', [
                    'href' => "/repositories/{$repository->id}/consulting",
                    'status' => null,
                    'status_label' => null,
                    'counts' => null,
                ])
            );

        $this->actingAs(User::factory()->create())
            ->get("/repositories/{$repository->id}/consulting")
            ->assertInertia(fn (Assert $page) => $page->where('can_run_consulting', true));
    }

    public function test_a_repository_that_is_not_ready_has_no_card_and_no_chapter(): void
    {
        $repository = Repository::factory()->create();

        $this->actingAs(User::factory()->create())
            ->get("/repositories/{$repository->id}/consulting")
            ->assertInertia(fn (Assert $page) => $page
                ->where('consulting', null)
                ->where('can_run_consulting', false)
                ->where('workspace.elements.consulting', null)
                ->where('workspace.chapters', fn ($groups) => collect($groups)->doesntContain('key', 'consulting'))
            );
    }

    public function test_a_waiting_analysis_is_shown_without_results_and_cannot_be_started_twice(): void
    {
        $repository = Repository::factory()->ready()->create();
        ConsultingReport::factory()->for($repository)->create(['status' => 'running', 'source_sha' => $repository->last_sha]);

        $this->actingAs(User::factory()->create())
            ->get("/repositories/{$repository->id}/consulting")
            ->assertInertia(fn (Assert $page) => $page
                ->where('consulting.status', 'running')
                ->where('consulting.status_label', 'Se analizează')
                ->where('consulting.is_working', true)
                ->where('consulting.result', null)
                ->where('can_run_consulting', false)
                ->where('workspace.elements.consulting.status', 'running')
                ->reloadOnly(['consulting', 'can_run_consulting'], fn (Assert $reload) => $reload
                    ->where('consulting.is_working', true)
                    ->missing('workspace')
                )
            );
    }

    public function test_a_failed_analysis_shows_its_reason_code_and_the_tools(): void
    {
        $repository = Repository::factory()->ready()->create();
        ConsultingReport::factory()->for($repository)->create([
            'status' => 'failed',
            'error' => ConsultingReport::ERROR_NO_TOOL_RAN,
            'tools' => ['semgrep' => ['name' => 'Semgrep', 'status' => 'timed_out', 'version' => '1.182.1', 'duration_ms' => 300000, 'finding_count' => 0, 'omitted_count' => 0, 'error' => 'timed_out', 'error_value' => 300, 'details' => []]],
        ]);

        $this->get("/repositories/{$repository->id}/consulting")
            ->assertInertia(fn (Assert $page) => $page
                ->where('consulting.status', 'failed')
                ->where('consulting.error', 'no_tool_ran')
                ->where('consulting.result', null)
                ->where('consulting.tools.0.status_label', 'Timp depășit')
                ->where('consulting.tools.0.error', 'timed_out')
                ->where('consulting.tools.0.error_value', 300)
            );
    }

    public function test_a_ready_report_has_its_counts_findings_by_file_dependencies_and_risk_zones(): void
    {
        $repository = Repository::factory()->ready()->create(['owner' => 'acme', 'name' => 'shop']);
        $sha = $repository->last_sha;
        $report = ConsultingReport::factory()->for($repository)->ready()->create(['source_sha' => $sha, 'finding_count' => 4, 'summary' => 'The scan found 1 high finding.']);
        $shell = ConsultingFinding::factory()->for($report, 'report')->create(['start_line' => 14, 'end_line' => 16]);
        ConsultingFinding::factory()->for($report, 'report')->bug()->create(['path' => 'app/Http/ReportController.php', 'start_line' => 3, 'end_line' => 3]);
        ConsultingFinding::factory()->for($report, 'report')->bug()->create(['path' => 'config/app.php', 'severity' => FindingSeverity::Low, 'start_line' => 1, 'end_line' => 1]);
        $lodash = ConsultingFinding::factory()->for($report, 'report')->dependency()->create();
        Commit::factory()->for($repository)->count(3)->create()->each(fn (Commit $commit) => CommitFile::factory()->for($commit)->create(['path' => 'app/Http/ReportController.php']));

        $this->get("/repositories/{$repository->id}/consulting")
            ->assertInertia(fn (Assert $page) => $page
                ->where('consulting.status', 'ready')
                ->where('consulting.is_working', false)
                ->where('consulting.is_stale', false)
                ->where('consulting.sha', $sha)
                ->where('consulting.tools.0.key', 'scc')
                ->where('consulting.tools.2.key', 'semgrep')
                ->where('consulting.result.summary', 'The scan found 1 high finding.')
                ->where('consulting.result.download_url', "/repositories/{$repository->id}/consulting/report.md")
                ->where('consulting.result.risk.weights', ['critical' => 8, 'high' => 4, 'medium' => 2, 'low' => 1, 'info' => 0])
                ->where('consulting.result.risk.candidate_count', 50)
                ->where('consulting.result.risk.zones.0', [
                    'path' => 'app/Http/ReportController.php',
                    'commit_count' => 3,
                    'finding_count' => 2,
                    'severity_points' => 6,
                    'score' => 18,
                    'worst_severity' => 'high',
                    'worst_severity_label' => 'Ridicată',
                    'url' => "https://github.com/acme/shop/blob/{$sha}/app/Http/ReportController.php",
                ])
                ->where('consulting.result.files.0.path', 'app/Http/ReportController.php')
                ->where('consulting.result.files.0.worst_severity', 'high')
                ->where('consulting.result.files.0.findings.0.rule_id', 'laravel-env-outside-config')
                ->where('consulting.result.files.0.findings.1', [
                    'severity' => 'high',
                    'severity_label' => 'Ridicată',
                    'original_severity' => 'ERROR',
                    'id' => $shell->id,
                    'kind' => 'vulnerability',
                    'kind_label' => 'Vulnerabilitate',
                    'rule_id' => 'php-shell-command',
                    'title' => 'Comandă de shell dintr-o variabilă',
                    'message' => 'O comandă de shell e construită dintr-o variabilă.',
                    'start_line' => 14,
                    'end_line' => 16,
                    'url' => "https://github.com/acme/shop/blob/{$sha}/app/Http/ReportController.php#L14-L16",
                ])
                ->where('consulting.result.files.1.path', 'config/app.php')
                ->where('consulting.result.dependencies', [[
                    'severity' => 'high',
                    'severity_label' => 'Ridicată',
                    'original_severity' => 'CVSS 7.4',
                    'id' => $lodash->id,
                    'package' => 'lodash',
                    'ecosystem' => 'npm',
                    'installed_version' => '4.17.15',
                    'fixed_version' => '4.17.19',
                    'title' => 'Prototype Pollution in lodash',
                    'advisory_id' => 'GHSA-p6mc-m468-83gw',
                    'advisory_url' => 'https://osv.dev/vulnerability/GHSA-p6mc-m468-83gw',
                    'path' => 'package-lock.json',
                ]])
                ->where('consulting.result.code_statistics.languages.0.name', 'PHP')
                ->where('workspace.elements.consulting.counts.high', 0)
                ->where('workspace.chapters', fn ($groups) => collect($groups)->firstWhere('key', 'consulting')['chapters'] === [
                    ['label' => 'Raport', 'href' => "/repositories/{$repository->id}/consulting", 'count' => null],
                    ['label' => 'Zone de risc', 'href' => "/repositories/{$repository->id}/consulting#zone-de-risc", 'count' => null],
                    ['label' => 'Probleme în cod', 'href' => "/repositories/{$repository->id}/consulting#probleme-in-cod", 'count' => 0],
                    ['label' => 'Dependențe vulnerabile', 'href' => "/repositories/{$repository->id}/consulting#dependente-vulnerabile", 'count' => 0],
                    ['label' => 'Statistici despre cod', 'href' => "/repositories/{$repository->id}/consulting#statistici-cod", 'count' => null],
                ])
            );
    }

    public function test_a_report_of_an_older_commit_is_marked_stale(): void
    {
        $repository = Repository::factory()->ready()->create();
        ConsultingReport::factory()->for($repository)->ready()->create(['source_sha' => str_repeat('0', 40)]);

        $this->get("/repositories/{$repository->id}/consulting")
            ->assertInertia(fn (Assert $page) => $page->where('consulting.is_stale', true));
    }

    public function test_guests_cannot_start_an_analysis(): void
    {
        Queue::fake();
        $repository = Repository::factory()->ready()->create();

        $this->post("/repositories/{$repository->id}/consulting")->assertRedirect('/login');

        Queue::assertNothingPushed();
    }

    public function test_a_user_starts_an_analysis_of_the_current_commit(): void
    {
        Queue::fake();
        $repository = Repository::factory()->ready()->create();
        ConsultingReport::factory()->for($repository)->ready()->create(['source_sha' => $repository->last_sha]);

        $this->actingAs(User::factory()->create())
            ->post("/repositories/{$repository->id}/consulting")
            ->assertRedirect("/repositories/{$repository->id}/consulting");

        Queue::assertPushedOn('media', GenerateConsultingReport::class, fn (GenerateConsultingReport $job): bool => $job->force && $job->repository->is($repository));
        $this->assertSame('pending', $repository->consultingReports()->sole()->status->value);
    }

    public function test_an_analysis_cannot_start_while_another_one_waits_or_before_the_repository_is_ready(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $waiting = Repository::factory()->ready()->create();
        ConsultingReport::factory()->for($waiting)->create(['status' => 'pending']);
        $processing = Repository::factory()->create();

        $this->actingAs($user)->post("/repositories/{$waiting->id}/consulting")->assertForbidden();
        $this->actingAs($user)->post("/repositories/{$processing->id}/consulting")->assertForbidden();
        // The rule is about the state of the clone, so it holds for an administrator too.
        $this->actingAs(User::factory()->admin()->create())->post("/repositories/{$waiting->id}/consulting")->assertForbidden();

        Queue::assertNothingPushed();
    }

    public function test_starting_analyses_is_limited_per_user(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $repositories = Repository::factory()->ready()->count(7)->create();

        foreach ($repositories->take(6) as $repository) {
            $this->actingAs($user)->post("/repositories/{$repository->id}/consulting")->assertRedirect();
        }

        $this->actingAs($user)->post("/repositories/{$repositories->last()->id}/consulting")->assertTooManyRequests();
    }

    public function test_the_report_downloads_as_english_markdown_with_untrusted_text_escaped(): void
    {
        $repository = Repository::factory()->ready()->create(['owner' => 'acme', 'name' => 'shop', 'last_sha' => str_repeat('a', 40)]);
        $report = ConsultingReport::factory()->for($repository)->ready()->create(['source_sha' => str_repeat('a', 40), 'summary' => 'The scan found 1 high finding.']);
        ConsultingFinding::factory()->for($report, 'report')->create([
            'title' => 'Shell command built from a variable',
            'message' => 'Look at ![tracker](https://evil.example/pixel.png) now',
        ]);
        ConsultingFinding::factory()->for($report, 'report')->dependency()->create();

        $response = $this->get("/repositories/{$repository->id}/consulting/report.md");

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/markdown; charset=UTF-8');
        $response->assertHeader('Content-Disposition', 'attachment; filename="consulting-acme-shop-aaaaaaa.md"');
        $markdown = $response->getContent();
        $this->assertStringContainsString('# Consulting report: acme/shop', $markdown);
        $this->assertStringContainsString('The scan found 1 high finding.', $markdown);
        $this->assertStringContainsString('## Risk zones', $markdown);
        $this->assertStringContainsString('### `app/Http/ReportController.php`', $markdown);
        $this->assertStringContainsString('([lines 14–14](https://github.com/acme/shop/blob/'.str_repeat('a', 40).'/app/Http/ReportController.php#L14-L14))', $markdown);
        $this->assertStringContainsString('| `lodash` (npm) | `4.17.15` | `4.17.19` | High (CVSS 7.4) | [GHSA-p6mc-m468-83gw](https://osv.dev/vulnerability/GHSA-p6mc-m468-83gw)', $markdown);
        $this->assertStringContainsString('| PHP | 3 | 30 | 0 | 11 | 2 |', $markdown);
        $this->assertStringContainsString('- Semgrep 1.182.1: ran in 2.7 s, 0 findings.', $markdown);
        $this->assertStringNotContainsString('![tracker](', $markdown);
        $this->assertStringContainsString('\\!\\[tracker\\]', $markdown);
    }

    public function test_there_is_nothing_to_download_before_a_report_is_ready(): void
    {
        $repository = Repository::factory()->ready()->create();
        ConsultingReport::factory()->for($repository)->create(['status' => 'running']);

        $this->get("/repositories/{$repository->id}/consulting/report.md")->assertNotFound();
    }
}

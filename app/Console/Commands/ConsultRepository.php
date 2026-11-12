<?php

namespace App\Console\Commands;

use App\Enums\ConsultingStatus;
use App\Enums\RepositoryStatus;
use App\Enums\ScanToolStatus;
use App\Jobs\GenerateConsultingReport;
use App\Models\ConsultingReport;
use App\Models\Repository;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Makes the „Consultanță” report of one repository by hand.
 *
 *     php artisan repositories:consult 1            (queued on "media"; a worker runs it)
 *     php artisan repositories:consult 1 --force    (again, even when the current commit has a report)
 *     php artisan repositories:consult 1 --now      (right here in the terminal, without a worker)
 *     php artisan repositories:consult --stale      (every ready repository whose report is older than 7 days)
 *     php artisan repositories:consult --stale=3    (… older than 3 days; the scheduler runs it daily)
 *
 * A report made again reuses the stored clone at the processed commit (no clone, no download of the repository)
 * and asks AI for a new summary only when the facts of the report changed (ConsultingScanner, SummaryWriter).
 *
 * The worker of the "media" queue: php artisan queue:work --queue=media
 */
#[Signature('repositories:consult
    {repository? : The id of the repository (not needed with --stale)}
    {--stale=7 : Make the report again for every ready repository whose report is older than this many days}
    {--force : Analyze again, even when the current commit already has a report}
    {--now : Run the tools here in the terminal instead of putting the job in the queue}')]
#[Description('Make the consulting report (bugs, vulnerabilities, code statistics) of a ready repository')]
class ConsultRepository extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if ($this->input->hasParameterOption('--stale')) {
            return $this->handleStale();
        }

        if ($this->argument('repository') === null) {
            $this->error('Give the id of a repository, or use --stale.');

            return self::FAILURE;
        }

        $repository = Repository::find($this->argument('repository'));

        if ($repository === null) {
            $this->error("There is no repository with id {$this->argument('repository')}.");

            return self::FAILURE;
        }

        $name = "#{$repository->id} {$repository->owner}/{$repository->name}";

        if ($repository->status !== RepositoryStatus::Ready) {
            $this->error(sprintf('%s is not ready yet (status: %s). The analysis runs after processing.', $name, $repository->status->label()));

            return self::FAILURE;
        }

        $force = (bool) $this->option('force');

        if (! $this->option('now')) {
            GenerateConsultingReport::queueFor($repository, $force);
            $this->info(sprintf('%1$s: queued the analysis on the "%2$s" queue. A worker runs it: php artisan queue:work --queue=%2$s', $name, config('gitchronicle.consulting.queue')));

            return self::SUCCESS;
        }

        $this->line(sprintf('%s: running scc, OSV-Scanner and Semgrep on the clone…', $name));
        GenerateConsultingReport::dispatchSync($repository, $force);

        /** @var ConsultingReport|null $report */
        $report = $repository->consultingReports()->where('source_sha', $repository->last_sha)->first();

        foreach ($report?->tools ?? [] as $tool) {
            $this->toolLine($tool);
        }

        if ($report === null || $report->status !== ConsultingStatus::Ready) {
            $this->error(sprintf('The report could not be made (%s).', $report->error ?? ConsultingReport::ERROR_UNEXPECTED));

            return self::FAILURE;
        }

        $this->info(sprintf('Done in %s s: %s.', number_format($report->started_at->diffInSeconds($report->finished_at), 1), $this->findings($report->finding_count)));

        return self::SUCCESS;
    }

    /**
     * `--stale[=days]`: queue (or, with --now, run) the report again for every ready repository whose newest finished report
     * is older than the given number of days, 7 by default. A report that is waiting or running, and a repository
     * that never had a report, are left alone.
     */
    private function handleStale(): int
    {
        $days = $this->option('stale') === null ? (int) config('gitchronicle.consulting.stale_after_days') : (int) $this->option('stale');

        if ($days < 1) {
            $this->error('--stale needs a number of days, 1 or more.');

            return self::FAILURE;
        }

        $cutoff = now()->subDays($days);
        $repositories = Repository::query()
            ->where('status', RepositoryStatus::Ready)
            ->whereHas('consultingReports')
            ->with(['consultingReports' => fn ($query) => $query->latest('id')->limit(1)])
            ->get()
            ->filter(function (Repository $repository) use ($cutoff): bool {
                $report = $repository->consultingReports->first();

                return ! $report->status->isWorking() && ($report->finished_at ?? $report->updated_at)->lt($cutoff);
            });

        foreach ($repositories as $repository) {
            $name = "#{$repository->id} {$repository->owner}/{$repository->name}";

            if ($this->option('now')) {
                GenerateConsultingReport::dispatchSync($repository, force: true);
                $this->info("{$name}: report made again.");
            } else {
                GenerateConsultingReport::queueFor($repository, force: true);
                $this->info("{$name}: queued.");
            }
        }

        $this->line(sprintf('%d %s older than %d %s.', $repositories->count(), $repositories->count() === 1 ? 'report' : 'reports', $days, $days === 1 ? 'day' : 'days'));

        return self::SUCCESS;
    }

    /**
     * One line about what a tool did, e.g. "Semgrep 1.182.1: ran in 2.7 s, 17 findings.".
     *
     * @param  array<string, mixed>  $tool
     */
    private function toolLine(array $tool): void
    {
        match (ScanToolStatus::from($tool['status'])) {
            ScanToolStatus::Ran => $this->info(sprintf('%s %s: ran in %s s, %s (%d left out by the limit).', $tool['name'], $tool['version'], number_format($tool['duration_ms'] / 1000, 1), $this->findings($tool['finding_count']), $tool['omitted_count'])),
            ScanToolStatus::NotInstalled => $this->warn(sprintf('%s: not installed (composer consulting:install).', $tool['name'])),
            ScanToolStatus::TimedOut => $this->warn(sprintf('%s: stopped after %d s.', $tool['name'], $tool['error_value'])),
            ScanToolStatus::Failed => $this->warn(sprintf('%s: error (%s, %s).', $tool['name'], $tool['error'], $tool['error_value'])),
        };
    }

    /**
     * "1 finding", "17 findings".
     */
    private function findings(int $count): string
    {
        return $count === 1 ? '1 finding' : "{$count} findings";
    }
}

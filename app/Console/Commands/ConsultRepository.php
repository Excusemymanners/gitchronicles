<?php

namespace App\Console\Commands;

use App\Enums\ConsultingStatus;
use App\Enums\RepositoryStatus;
use App\Enums\ScanToolStatus;
use App\Jobs\GenerateConsultingReport;
use App\Media\RomanianText;
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
 *
 * The worker of the "media" queue: php artisan queue:work --queue=media
 */
#[Signature('repositories:consult
    {repository : The id of the repository}
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
        $repository = Repository::find($this->argument('repository'));

        if ($repository === null) {
            $this->error("Nu există niciun repository cu id-ul {$this->argument('repository')}.");

            return self::FAILURE;
        }

        $name = "#{$repository->id} {$repository->owner}/{$repository->name}";

        if ($repository->status !== RepositoryStatus::Ready) {
            $this->error(sprintf('%s nu este încă gata (status: %s). Analiza se face după procesare.', $name, $repository->status->label()));

            return self::FAILURE;
        }

        $force = (bool) $this->option('force');

        if (! $this->option('now')) {
            GenerateConsultingReport::queueFor($repository, $force);
            $this->info(sprintf('%1$s: am trimis analiza în coada „%2$s”. O face un worker: php artisan queue:work --queue=%2$s', $name, config('gitchronicle.consulting.queue')));

            return self::SUCCESS;
        }

        $this->line(sprintf('%s: rulez scc, OSV-Scanner și Semgrep pe clonă…', $name));
        GenerateConsultingReport::dispatchSync($repository, $force);

        /** @var ConsultingReport|null $report */
        $report = $repository->consultingReports()->where('source_sha', $repository->last_sha)->first();

        foreach ($report?->tools ?? [] as $tool) {
            $this->toolLine($tool);
        }

        if ($report === null || $report->status !== ConsultingStatus::Ready) {
            $this->error(sprintf('Raportul nu a putut fi făcut (%s).', $report->error ?? ConsultingReport::ERROR_UNEXPECTED));

            return self::FAILURE;
        }

        $this->info(sprintf('Gata în %s s: %s.', number_format($report->started_at->diffInSeconds($report->finished_at), 1, ',', ''), $this->findings($report->finding_count)));

        return self::SUCCESS;
    }

    /**
     * One line about what a tool did, e.g. „Semgrep 1.182.1: a rulat în 2,7 s, 17 probleme.”.
     *
     * @param  array<string, mixed>  $tool
     */
    private function toolLine(array $tool): void
    {
        match (ScanToolStatus::from($tool['status'])) {
            ScanToolStatus::Ran => $this->info(sprintf('%s %s: a rulat în %s s, %s (%d lăsate deoparte de limită).', $tool['name'], $tool['version'], number_format($tool['duration_ms'] / 1000, 1, ',', ''), $this->findings($tool['finding_count']), $tool['omitted_count'])),
            ScanToolStatus::NotInstalled => $this->warn(sprintf('%s: nu e instalat (composer consulting:install).', $tool['name'])),
            ScanToolStatus::TimedOut => $this->warn(sprintf('%s: oprit după %d s.', $tool['name'], $tool['error_value'])),
            ScanToolStatus::Failed => $this->warn(sprintf('%s: eroare (%s, %s).', $tool['name'], $tool['error'], $tool['error_value'])),
        };
    }

    /**
     * „1 problemă”, „17 probleme”, „20 de probleme”.
     */
    private function findings(int $count): string
    {
        return RomanianText::count($count, 'problemă', 'probleme');
    }
}

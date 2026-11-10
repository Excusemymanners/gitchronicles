<?php

namespace App\Console\Commands;

use App\Enums\RepositoryStatus;
use App\Jobs\GenerateMedia;
use App\Models\MediaAsset;
use App\Models\Repository;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Makes the presentation video and the slides of one repository by hand.
 *
 *     php artisan repositories:media 1            (queued on "media"; a worker renders them)
 *     php artisan repositories:media 1 --force    (again, even when they exist for the current commit)
 *     php artisan repositories:media 1 --now      (right here in the terminal, without a worker)
 *
 * Without --force nothing is made again for a commit whose video and slides already exist.
 * The worker of the "media" queue: php artisan queue:work --queue=media
 */
#[Signature('repositories:media
    {repository : The id of the repository}
    {--force : Make the video and the slides again, even when they exist for the current commit}
    {--now : Render here in the terminal instead of putting the jobs in the queue}')]
#[Description('Make the presentation video and the slides of a ready repository')]
class GenerateRepositoryMedia extends Command
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
            $this->error("{$name} nu este încă gata (status: {$repository->status->label()}). Video-ul și slide-urile se fac după procesare.");

            return self::FAILURE;
        }

        $force = (bool) $this->option('force');

        if (! $this->option('now')) {
            GenerateMedia::dispatch($repository, $force);
            $this->info("{$name}: am trimis video-ul și slide-urile în coada „media”. Le face un worker: php artisan queue:work --queue=media");

            return self::SUCCESS;
        }

        $this->line("{$name}: scriu scenariul, apoi randez video-ul și slide-urile (durează în jur de un minut)…");
        GenerateMedia::dispatchSync($repository, $force, renderNow: true);

        $failed = false;

        foreach ($repository->mediaAssets()->orderBy('kind')->get() as $asset) {
            /** @var MediaAsset $asset */
            $line = "{$asset->kind->value}: {$asset->status->label()}".($asset->path === null ? '' : " — storage/app/private/{$asset->path}");

            if ($asset->error !== null) {
                $this->error("{$line} ({$asset->error})");
                $failed = true;
            } else {
                $this->info($line);
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Enums\RepositoryStatus;
use App\Jobs\GenerateMedia;
use App\Models\MediaAsset;
use App\Models\Repository;
use App\Support\StoredMessage;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Makes the presentation videos (one per language) and the slides of one repository by hand.
 *
 *     php artisan repositories:media 1            (queued on "media"; a worker renders them)
 *     php artisan repositories:media 1 --force    (again, even when they exist for the current commit)
 *     php artisan repositories:media 1 --now      (right here in the terminal, without a worker)
 *     php artisan repositories:media 1 --force --rewrite   (also ask the AI for new texts)
 *
 * Without --force nothing is made again for a commit whose videos and slides already exist. Without
 * --rewrite the texts the AI already wrote are used again while the repository's sources are unchanged.
 * The worker of the "media" queue: php artisan queue:work --queue=media
 */
#[Signature('repositories:media
    {repository : The id of the repository}
    {--force : Make the video and the slides again, even when they exist for the current commit}
    {--now : Render here in the terminal instead of putting the jobs in the queue}
    {--rewrite : Ask the AI for new texts even when the sources of the repository did not change}')]
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
            $this->error("There is no repository with id {$this->argument('repository')}.");

            return self::FAILURE;
        }

        $name = "#{$repository->id} {$repository->owner}/{$repository->name}";

        if ($repository->status !== RepositoryStatus::Ready) {
            $this->error("{$name} is not ready yet (status: {$repository->status->label()}). The video and slides are made after processing.");

            return self::FAILURE;
        }

        $force = (bool) $this->option('force');
        $rewrite = (bool) $this->option('rewrite');

        if (! $this->option('now')) {
            GenerateMedia::dispatch($repository, $force, rewrite: $rewrite);
            $this->info("{$name}: queued the video and slides on the media queue. A worker makes them: php artisan queue:work --queue=media");

            return self::SUCCESS;
        }

        $this->line("{$name}: capturing the GitHub page, writing the script, then rendering the video in each language and the slides (takes a few minutes)…");
        GenerateMedia::dispatchSync($repository, $force, renderNow: true, rewrite: $rewrite);

        $failed = false;

        foreach ($repository->mediaAssets()->orderBy('kind')->orderBy('language')->get() as $asset) {
            /** @var MediaAsset $asset */
            $line = "{$asset->kind->value} ({$asset->language->value}): {$asset->status->label()}".($asset->path === null ? '' : " — storage/app/private/{$asset->path}");

            if ($asset->error !== null) {
                $this->error("{$line} (".StoredMessage::render($asset->error).')');
                $failed = true;
            } else {
                $this->info($line);
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}

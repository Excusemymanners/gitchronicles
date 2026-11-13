<?php

namespace App\Console\Commands;

use App\Documentation\ReadmeShowcase;
use App\Models\Repository;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Copies the README's pictures and sections of repositories that were processed before this feature existed
 * (or whose pictures failed to download), so their documentation pages show them without a new run.
 *
 *     php artisan repositories:readme-pictures 3
 *     php artisan repositories:readme-pictures --all
 */
#[Signature('repositories:readme-pictures
    {repository? : The id of the repository}
    {--all : Collect for every repository that has a clone}')]
#[Description("Copy the README's own pictures and descriptive sections of a repository")]
class CollectReadmePicturesCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(ReadmeShowcase $showcase): int
    {
        if (($this->argument('repository') === null) === (! $this->option('all'))) {
            $this->error('Give the id of a repository, or --all.');

            return self::FAILURE;
        }

        $repositories = $this->option('all') ? Repository::orderBy('id')->get() : Repository::whereKey($this->argument('repository'))->get();

        if ($repositories->isEmpty()) {
            $this->error('There is no such repository.');

            return self::FAILURE;
        }

        foreach ($repositories as $repository) {
            $manifest = $showcase->collect($repository);

            $this->line(sprintf(
                '#%d %s/%s: %s',
                $repository->id,
                $repository->owner,
                $repository->name,
                $manifest === null ? 'no clone or README, nothing collected.' : count($manifest['pictures']).' pictures, '.count($manifest['sections']).' sections.',
            ));
        }

        return self::SUCCESS;
    }
}

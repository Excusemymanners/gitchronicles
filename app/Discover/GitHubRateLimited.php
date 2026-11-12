<?php

namespace App\Discover;

use Illuminate\Support\Carbon;

/**
 * GitHub's rate limit is used up (or GitHub said so with a 403/429). `discover:refresh` stops politely:
 * it sends no other request and keeps what it collected until then.
 */
final class GitHubRateLimited extends GitHubRequestFailed
{
    /**
     * @param  int|null  $resetAt  When GitHub allows requests again (Unix time), when it said so.
     */
    public function __construct(public readonly ?int $resetAt = null)
    {
        $until = $resetAt === null ? '' : ' until '.Carbon::createFromTimestamp($resetAt)->format('H:i:s').' UTC';

        parent::__construct("The GitHub API request limit is reached{$until}.");
    }
}

<?php

namespace App\Policies;

use App\Enums\RepositoryStatus;
use App\Models\Repository;
use App\Models\User;

/**
 * Repositories are public, so there is no "view" rule: everyone may open them.
 * Only the user who added a repository may delete it.
 */
class RepositoryPolicy
{
    /**
     * Determine whether the user can delete the repository.
     *
     * is() compares the repository's user_id with the user's id. When user_id is null
     * (the user who added it deleted their account), nobody may delete it.
     */
    public function delete(User $user, Repository $repository): bool
    {
        return $repository->user()->is($user);
    }

    /**
     * Determine whether the user can start the processing again („Reîncearcă”).
     *
     * Any logged-in user may, not only the one who added it: the repository is public and
     * a new clone harms nothing. It is allowed only after a failure, so a pipeline that is
     * waiting or running is never started twice.
     */
    public function retry(User $user, Repository $repository): bool
    {
        return $repository->status === RepositoryStatus::Failed;
    }
}

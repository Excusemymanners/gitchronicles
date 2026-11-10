<?php

namespace App\Policies;

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
}

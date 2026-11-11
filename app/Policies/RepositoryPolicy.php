<?php

namespace App\Policies;

use App\Enums\RepositoryStatus;
use App\Models\Repository;
use App\Models\User;

/**
 * Repositories are public, so there is no "view" rule: everyone may open them.
 * Only the user who added a repository may delete it, except an administrator (see before()).
 */
class RepositoryPolicy
{
    /**
     * The abilities whose rule is about the repository's state, not about who asks: they keep two runs of
     * the pipeline from working on the same clone (or a run from being started next to a waiting one).
     * Any logged-in user already has them when the state allows it, and an admin is no exception.
     *
     * @var list<string>
     */
    private const STATE_RULES = ['retry', 'checkUpdates', 'regenerate'];

    /**
     * An administrator (users.is_admin) may do on every repository what its owner may do, for example
     * delete a repository someone else added.
     *
     * It is a before() of this policy, not a Gate::before() for the whole application, so the role reaches
     * only the repository actions and nothing added to the application later gets it by accident. The rules
     * about the state (STATE_RULES) still apply to an admin: no role makes two runs on one clone safe.
     * Returning null lets the ability's own method decide.
     */
    public function before(User $user, string $ability): ?bool
    {
        return $user->is_admin && ! in_array($ability, self::STATE_RULES, true) ? true : null;
    }

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

    /**
     * Determine whether the user can ask for an update („Verifică actualizări”, Faza 9).
     *
     * Any logged-in user may, like „Reîncearcă”: the repository is public and an update only brings
     * its documentation up to date. It is allowed only while the repository is ready, so it never
     * starts next to a run that is waiting or working; after a failure „Reîncearcă” is the button.
     */
    public function checkUpdates(User $user, Repository $repository): bool
    {
        return $repository->status === RepositoryStatus::Ready;
    }

    /**
     * Determine whether the user can process the repository again in full („Regenerează tot”).
     *
     * Any logged-in user may, like „Reîncearcă” and „Verifică actualizări”: the repository is public and
     * a full processing only makes its chapters again. It is allowed once the repository is ready or has
     * failed, so it never starts next to a run that is waiting or working.
     */
    public function regenerate(User $user, Repository $repository): bool
    {
        return in_array($repository->status, [RepositoryStatus::Ready, RepositoryStatus::Failed], true);
    }
}

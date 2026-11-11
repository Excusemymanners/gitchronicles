<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Gives the administrator role to an existing account, or takes it back. The terminal is the only way:
 * there is no page for it, so nobody can make themselves an admin from the browser.
 *
 *     php artisan users:admin ana@example.com
 *     php artisan users:admin ana@example.com --revoke
 *
 * An admin may do on every repository what its owner may do, for example delete it (RepositoryPolicy::before()).
 */
#[Signature('users:admin
    {email : The email of the account}
    {--revoke : Take the role back instead of giving it}')]
#[Description('Give the administrator role to an account (or take it back with --revoke)')]
class GrantAdminRole extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $email = (string) $this->argument('email');
        $user = User::firstWhere('email', $email);

        if ($user === null) {
            $this->error("Nu există niciun cont cu adresa {$email}.");

            return self::FAILURE;
        }

        $isAdmin = ! $this->option('revoke');

        // is_admin is not fillable (a form must never set it), so it is set here directly.
        $user->is_admin = $isAdmin;
        $user->save();

        $this->info($isAdmin
            ? "{$user->name} ({$email}) este acum administrator."
            : "{$user->name} ({$email}) nu mai este administrator.");

        return self::SUCCESS;
    }
}

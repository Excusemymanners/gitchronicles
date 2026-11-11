<?php

namespace Tests\Feature;

use App\Models\Repository;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The administrator role: users.is_admin, `php artisan users:admin` and RepositoryPolicy::before().
 */
class AdminRoleTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_account_starts_as_a_normal_user(): void
    {
        DB::table('users')->insert(['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'x']);

        $this->assertFalse(User::firstWhere('email', 'ana@example.com')->is_admin);
        $this->assertFalse(User::factory()->create()->fresh()->is_admin);
    }

    public function test_the_registration_form_cannot_make_an_admin(): void
    {
        $this->post('/register', [
            'name' => 'Ana',
            'email' => 'ana@example.com',
            'password' => 'parola-lunga-123',
            'password_confirmation' => 'parola-lunga-123',
            'is_admin' => true,
        ]);

        $this->assertFalse(User::firstWhere('email', 'ana@example.com')->is_admin);
    }

    public function test_the_command_grants_and_revokes_the_role(): void
    {
        $user = User::factory()->create(['name' => 'Ana', 'email' => 'ana@example.com']);

        $this->artisan('users:admin', ['email' => 'ana@example.com'])
            ->expectsOutput('Ana (ana@example.com) este acum administrator.')
            ->assertSuccessful();
        $this->assertTrue($user->fresh()->is_admin);

        $this->artisan('users:admin', ['email' => 'ana@example.com', '--revoke' => true])
            ->expectsOutput('Ana (ana@example.com) nu mai este administrator.')
            ->assertSuccessful();
        $this->assertFalse($user->fresh()->is_admin);
    }

    public function test_the_command_fails_for_an_unknown_email(): void
    {
        $this->artisan('users:admin', ['email' => 'nimeni@example.com'])
            ->expectsOutput('Nu există niciun cont cu adresa nimeni@example.com.')
            ->assertFailed();
    }

    public function test_an_admin_deletes_a_repository_added_by_someone_else(): void
    {
        $repository = Repository::factory()->ready()->create();

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('repositories.show', $repository))
            ->assertInertia(fn (Assert $page) => $page->where('can_delete', true));

        $this->delete(route('repositories.destroy', $repository))->assertRedirect(route('home'));

        $this->assertModelMissing($repository);
    }

    public function test_a_normal_user_still_cannot_delete_a_repository_added_by_someone_else(): void
    {
        $repository = Repository::factory()->ready()->create();

        $this->actingAs(User::factory()->create())->delete(route('repositories.destroy', $repository))->assertForbidden();

        $this->assertModelExists($repository);
    }

    public function test_the_rules_about_the_state_still_apply_to_an_admin(): void
    {
        $admin = User::factory()->admin()->create();
        $processing = Repository::factory()->ready()->create(['status' => 'cloning']);

        $this->assertFalse($admin->can('retry', $processing));
        $this->assertFalse($admin->can('checkUpdates', $processing));
        $this->assertFalse($admin->can('regenerate', $processing));
        $this->assertTrue($admin->can('regenerate', Repository::factory()->ready()->create()));
    }

    public function test_every_page_says_whether_the_user_is_an_admin(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('home'))
            ->assertInertia(fn (Assert $page) => $page->where('auth.user.is_admin', true));

        $this->actingAs(User::factory()->create())
            ->get(route('home'))
            ->assertInertia(fn (Assert $page) => $page->where('auth.user.is_admin', false));
    }
}

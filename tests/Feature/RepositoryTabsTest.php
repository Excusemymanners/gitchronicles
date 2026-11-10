<?php

namespace Tests\Feature;

use App\Models\Repository;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The pages of a repository (once tabs, now chapters of the workspace): every page is public and gets
 * the same props (RendersRepositoryPage), which WorkspaceLayout shows around the page's content.
 */
class RepositoryTabsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{string, string}>
     */
    public static function tabs(): array
    {
        return [
            'Prezentare' => ['', 'repositories/show'],
            'Documentație' => ['/docs', 'repositories/docs'],
            'Diagrame' => ['/diagrams', 'repositories/diagrams'],
            'Asistent' => ['/assistant', 'repositories/assistant'],
            'Istoric' => ['/timeline', 'repositories/timeline'],
            'Video' => ['/media', 'repositories/media'],
        ];
    }

    #[DataProvider('tabs')]
    public function test_guest_opens_every_tab_with_the_repository_and_without_owner_actions(string $path, string $component): void
    {
        $repository = Repository::factory()->ready()->create();

        $response = $this->get("/repositories/{$repository->id}{$path}");

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component($component)
            ->where('repository.id', $repository->id)
            ->where('repository.full_name', "{$repository->owner}/{$repository->name}")
            ->where('can_delete', false)
            ->where('can_retry', false)
        );
    }

    #[DataProvider('tabs')]
    public function test_every_tab_offers_delete_and_retry_to_the_user_who_added_a_failed_repository(string $path, string $component): void
    {
        $user = User::factory()->create();
        $repository = Repository::factory()->for($user)->failed()->create();

        $response = $this->actingAs($user)->get("/repositories/{$repository->id}{$path}");

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component($component)
            ->where('can_delete', true)
            ->where('can_retry', true)
        );
    }

    #[DataProvider('tabs')]
    public function test_a_tab_of_a_missing_repository_returns_404(string $path, string $component): void
    {
        $response = $this->get("/repositories/999{$path}");

        $response->assertNotFound();
    }

    public function test_overview_hides_every_section_whose_data_does_not_exist_yet(): void
    {
        $repository = Repository::factory()->ready()->create();

        $response = $this->get("/repositories/{$repository->id}");

        $response->assertInertia(fn (Assert $page) => $page
            ->component('repositories/show')
            ->where('overview', null)
            ->where('activity', null)
            ->where('hotspots', null)
            ->where('diagram_preview', null)
        );
    }
}

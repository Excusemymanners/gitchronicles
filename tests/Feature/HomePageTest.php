<?php

namespace Tests\Feature;

use App\Models\Repository;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class HomePageTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_page_is_rendered_with_inertia(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('home')
            ->has('mesaj')
        );
    }

    public function test_home_page_lists_the_repositories_of_every_user_newest_first(): void
    {
        $ana = User::factory()->create();
        $ion = User::factory()->create();
        $this->travelTo('2026-11-01 10:00:00');
        $older = Repository::factory()->for($ana)->create();
        $this->travelTo('2026-11-02 10:00:00');
        $newer = Repository::factory()->for($ion)->create();

        $response = $this->get('/');

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('home')
            ->has('repositories', 2)
            ->where('repositories.0.id', $newer->id)
            ->where('repositories.1.id', $older->id)
        );
    }

    public function test_home_page_sends_each_repository_as_a_repository_item_without_its_user(): void
    {
        $this->travelTo('2026-11-09 10:00:00');
        $repository = Repository::factory()->create([
            'url' => 'https://github.com/laravel/framework',
            'owner' => 'laravel',
            'name' => 'framework',
        ]);

        $response = $this->get('/');

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('home')
            ->where('repositories', [[
                'id' => $repository->id,
                'url' => 'https://github.com/laravel/framework',
                'owner' => 'laravel',
                'name' => 'framework',
                'full_name' => 'laravel/framework',
                'description' => null,
                'status' => 'pending',
                'status_label' => 'În așteptare',
                'is_processing' => true,
                'default_branch' => null,
                'last_sha' => null,
                'error' => null,
                'cloned_at' => null,
                'analyzed_at' => null,
                'documented_at' => null,
                'created_at' => '2026-11-09T10:00:00+00:00',
            ]])
        );
    }
}

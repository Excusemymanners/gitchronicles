<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The „Documentație” tab (DocsController), the README download (DownloadReadmeController) and the
 * „Despre proiect” section of „Prezentare”. All public, like every repository page.
 */
class DocsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_sees_the_overview_and_the_chapters_in_menu_order(): void
    {
        $repository = Repository::factory()->ready()->create();
        Document::factory()->for($repository)->create(['slug' => 'app-models', 'title' => 'app/Models']);
        Document::factory()->for($repository)->readme()->create();
        Document::factory()->for($repository)->create(['slug' => 'radacina', 'title' => '/']);
        Document::factory()->for($repository)->create(['slug' => 'app-http', 'title' => 'app/Http']);
        Document::factory()->for($repository)->overview()->writtenByAi()->create();

        $response = $this->get("/repositories/{$repository->id}/docs");

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('repositories/docs')
            ->where('document.slug', 'overview')
            ->where('document.kind', 'overview')
            ->where('document.writer', 'ai')
            ->where('document.model', 'claude-opus-5-5')
            ->where('document.ai_driver', 'claude-code')
            ->where('document.input_tokens', 4200)
            ->where('document.download_url', null)
            ->where('navigation', [
                $this->chapter($repository, 'overview', 'Overview', 'overview', null),
                $this->chapter($repository, 'readme', 'Generated README', 'readme', null),
                $this->chapter($repository, 'radacina', 'Project root', 'module', null),
                $this->chapter($repository, 'app-http', 'app/Http', 'module', 'app'),
                $this->chapter($repository, 'app-models', 'app/Models', 'module', 'app'),
            ])
        );
    }

    public function test_guest_opens_a_module_page_by_its_slug(): void
    {
        $repository = Repository::factory()->ready()->create();
        Document::factory()->for($repository)->stale()->create(['slug' => 'app-http', 'title' => 'app/Http', 'markdown' => '# Modulul app/Http']);

        $response = $this->get("/repositories/{$repository->id}/docs/app-http");

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->where('document.slug', 'app-http')
            ->where('document.title', 'app/Http')
            ->where('document.group', 'app')
            ->where('document.markdown', '# Modulul app/Http')
            ->where('document.is_stale', true)
            ->where('document.stale_reason', 'Claude nu a putut scrie această pagină: Limita abonamentului Claude a fost atinsă; încearcă mai târziu.')
        );
    }

    public function test_an_unknown_page_returns_404(): void
    {
        $repository = Repository::factory()->ready()->create();
        Document::factory()->for($repository)->overview()->create();

        $response = $this->get("/repositories/{$repository->id}/docs/nu-exista");

        $response->assertNotFound();
    }

    public function test_a_page_of_another_repository_returns_404(): void
    {
        $repository = Repository::factory()->ready()->create();
        Document::factory()->create(['slug' => 'app-http']);

        $response = $this->get("/repositories/{$repository->id}/docs/app-http");

        $response->assertNotFound();
    }

    public function test_docs_without_pages_show_the_empty_state(): void
    {
        $repository = Repository::factory()->ready()->create();

        $response = $this->get("/repositories/{$repository->id}/docs");

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->where('document', null)
            ->where('navigation', [])
        );
    }

    public function test_the_readme_page_offers_its_download(): void
    {
        $repository = Repository::factory()->ready()->create();
        Document::factory()->for($repository)->readme()->create();

        $response = $this->get("/repositories/{$repository->id}/docs/readme");

        $response->assertInertia(fn (Assert $page) => $page
            ->where('document.kind', 'readme')
            ->where('document.download_url', "/repositories/{$repository->id}/docs/readme.md")
        );
    }

    public function test_guest_downloads_the_generated_readme_as_a_markdown_file(): void
    {
        $repository = Repository::factory()->ready()->create();
        Document::factory()->for($repository)->readme()->create(['markdown' => "# Magazin\n\nUn magazin mic.\n"]);

        $response = $this->get("/repositories/{$repository->id}/docs/readme.md");

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/markdown; charset=UTF-8');
        $response->assertDownload('README.md');
        $this->assertSame("# Magazin\n\nUn magazin mic.\n", $response->getContent());
    }

    public function test_the_readme_download_returns_404_until_the_readme_is_written(): void
    {
        $repository = Repository::factory()->ready()->create();

        $response = $this->get("/repositories/{$repository->id}/docs/readme.md");

        $response->assertNotFound();
    }

    public function test_prezentare_shows_the_first_two_sections_of_the_overview(): void
    {
        $repository = Repository::factory()->ready()->create();
        Document::factory()->for($repository)->overview()->create([
            'markdown' => "# acme/magazin\n\n## Ce face proiectul\n\nUn magazin.\n\n```bash\n# nu e un titlu\n```\n\n## Tehnologii\n\n- PHP\n\n## Arhitectură\n\nTrei module.\n",
        ]);

        $response = $this->get("/repositories/{$repository->id}");

        $response->assertInertia(fn (Assert $page) => $page
            ->where('overview', [
                'markdown' => "### Ce face proiectul\n\nUn magazin.\n\n```bash\n# nu e un titlu\n```\n\n### Tehnologii\n\n- PHP",
                'url' => "/repositories/{$repository->id}/docs",
                'is_stale' => false,
            ])
        );
    }

    /**
     * One chapter of the menu, as DocumentNavigation sends it.
     *
     * @return array{slug: string, title: string, kind: string, group: string|null, is_stale: bool, href: string}
     */
    private function chapter(Repository $repository, string $slug, string $title, string $kind, ?string $group): array
    {
        return [
            'slug' => $slug,
            'title' => $title,
            'kind' => $kind,
            'group' => $group,
            'is_stale' => false,
            'href' => $slug === 'overview' ? "/repositories/{$repository->id}/docs" : "/repositories/{$repository->id}/docs/{$slug}",
        ];
    }
}

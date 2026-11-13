<?php

namespace Tests\Feature;

use App\Assistant\AnswerQuestion;
use App\Http\Resources\WorkspaceResource;
use App\Models\Diagram;
use App\Models\Document;
use App\Models\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\SplFileInfo;
use Tests\Concerns\ProcessesFixtureProject;
use Tests\TestCase;

/**
 * Everything GitChronicle writes without AI is in the content language (English): the whole pipeline runs
 * on the fixture project with AI off, and every deterministic text it produced (the template documentation,
 * each architecture diagram with its title, caption and excluded-files note, the retrieval-only answer, the
 * "nothing found" answer and the starter questions) may contain a Romanian diacritic only inside a word that
 * comes from the repository's own files (the fixture's README and code have some on purpose).
 */
class GeneratedContentLanguageTest extends TestCase
{
    use ProcessesFixtureProject;
    use RefreshDatabase;

    /**
     * A word with a Romanian letter (ă â î ș ț, also the cedilla forms ş ţ), in either case.
     */
    private const ROMANIAN_WORD = '/\p{L}*[ăâîșțşţĂÂÎȘȚŞŢ]\p{L}*/u';

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessAnalyzerIsInstalled();
    }

    public function test_the_deterministic_generators_write_no_romanian_of_their_own(): void
    {
        $repository = $this->processedFixture();
        $answers = app(AnswerQuestion::class);
        $starters = WorkspaceResource::make($repository)->resolve()['assistant']['starters'];

        $texts = [
            ...Document::whereBelongsTo($repository)->pluck('markdown', 'slug')->mapWithKeys(fn (string $markdown, string $slug): array => ["document {$slug}" => $markdown])->all(),
            ...Diagram::whereBelongsTo($repository)->get()->mapWithKeys(fn (Diagram $diagram): array => [
                "diagram {$diagram->type->value}" => $diagram->mermaid."\n".json_encode($diagram->meta, JSON_UNESCAPED_UNICODE),
            ])->all(),
            'document titles' => Document::whereBelongsTo($repository)->pluck('title')->implode("\n"),
            'retrieval answer' => json_encode($answers->handle($repository, 'Where is the price of a product formatted?'), JSON_UNESCAPED_UNICODE),
            'nothing found' => json_encode($answers->handle($repository, 'zzqx'), JSON_UNESCAPED_UNICODE),
            'starter questions' => implode("\n", $starters),
        ];

        $this->assertSame(['component', 'container', 'context', 'layers', 'overview'], Diagram::whereBelongsTo($repository)->orderBy('type')->pluck('type')->map->value->all());
        $this->assertCount(3, $starters);
        $repositoryWords = $this->repositoryWords();

        foreach ($texts as $source => $text) {
            preg_match_all(self::ROMANIAN_WORD, $text, $matches);
            $foreignWords = array_values(array_unique(array_diff($matches[0], $repositoryWords)));

            $this->assertSame([], $foreignWords, "{$source} contains Romanian words that are not in the repository's own files.");
        }
    }

    /**
     * The fixture project with a few manifests, so every architecture view has something to draw,
     * processed once with AI off.
     */
    private function processedFixture(): Repository
    {
        $repository = $this->repositoryWithFixtureOrigin();
        $this->pushToOrigin([
            'composer.json' => json_encode(['name' => 'acme/shop', 'require' => ['php' => '^8.3', 'stripe/stripe-php' => '^16.0']]),
            'package.json' => json_encode(['scripts' => ['dev' => 'vite'], 'dependencies' => ['react' => '^19.1.0'], 'devDependencies' => ['typescript' => '^5.6.0']]),
            '.env.example' => "DB_CONNECTION=pgsql\nCACHE_STORE=redis\nSESSION_DRIVER=redis\nMAIL_MAILER=smtp\n",
        ]);

        $run = $this->process($repository);
        $this->assertSame('succeeded', $run->status->value);

        return $repository->fresh();
    }

    /**
     * Every word written in the fixture project's own files.
     *
     * @return list<string>
     */
    private function repositoryWords(): array
    {
        $text = collect(File::allFiles(base_path('tests/Fixtures/analyzer-project'), hidden: true))
            ->map(fn (SplFileInfo $file): string => $file->getContents())
            ->implode("\n");

        preg_match_all('/\p{L}+/u', $text, $matches);

        return array_values(array_unique($matches[0]));
    }
}

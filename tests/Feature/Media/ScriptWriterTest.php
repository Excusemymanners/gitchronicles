<?php

namespace Tests\Feature\Media;

use App\Ai\AiException;
use App\Ai\NullTextGenerator;
use App\Ai\TextGenerator;
use App\Media\PresentationFacts;
use App\Media\ScriptWriter;
use Tests\Support\FakeTextGenerator;
use Tests\TestCase;

/**
 * The script of the video: Claude's JSON (always FakeTextGenerator here) is kept only where every scene
 * quotes the context it received; everything else falls back to the scenes built from the facts.
 */
class ScriptWriterTest extends TestCase
{
    private const README = "# Magazin\n\nUn magazin mic, cu coș de cumpărături și plăți online.\n\n"
        ."- **Coș** care ține minte produsele între vizite.\n- Plăți cu cardul, fără cont.\n- Facturi PDF trimise pe e-mail.\n\n"
        ."```bash\ncomposer install\n```\n";

    public function test_scenes_whose_quotes_are_in_the_context_become_the_script(): void
    {
        $this->app->instance(TextGenerator::class, $ai = new FakeTextGenerator(["```json\n".json_encode($this->answer(), JSON_UNESCAPED_UNICODE)."\n```"]));

        $script = app(ScriptWriter::class)->write($this->facts());

        $ai->assertSentCount(1);
        $this->assertStringContainsString('Repository: acme/magazin', $ai->requests[0]['user']);
        $this->assertStringContainsString("### README.md\n\n# Magazin", $ai->requests[0]['user']);
        $this->assertStringContainsString('"source"', $ai->requests[0]['system']);
        $this->assertSame('ai', $script->writer);
        $this->assertSame('claude-opus-5-5', $script->model);
        $this->assertSame([], $script->rejected);
        $this->assertSame(['hook', 'problem', 'feature', 'feature', 'start', 'proof', 'cta'], array_column($script->scenes, 'key'));

        [$hook, $problem, $first, $second, $start, $proof, $cta] = $script->scenes;
        $this->assertSame(['acme /', 'magazin', 'Magazinul mic care ține minte coșul.', 'ține minte'], [$hook['kicker'], $hook['title'], $hook['text'], $hook['accent']]);
        $this->assertSame($hook['text'], $hook['narration']);
        $this->assertSame(['label' => 'README.md', 'quote' => 'Un magazin mic, cu coș de cumpărături și plăți online.', 'url' => 'https://github.com/acme/magazin/blob/abc/README.md'], $hook['source']);
        $this->assertSame('Ce rezolvă', $problem['label']);
        $this->assertSame([1, 2, 'Coș care nu uită', 'Plăți fără cont'], [$first['index'], $first['count'], $first['title'], $second['title']]);
        $this->assertSame('Coș care nu uită. Ține minte produsele între vizite.', $first['narration']);
        $this->assertSame('composer install', $start['command']);
        $this->assertSame('README.md', $start['source']['label']);
        $this->assertSame(1234, $proof['stars']);
        $this->assertSame('github.com/acme/magazin', $cta['url']);
        $this->assertSame('Încearcă magazinul chiar azi.', $cta['text']);
    }

    public function test_a_scene_with_an_invented_quote_or_a_long_text_is_dropped(): void
    {
        $answer = $this->answer();
        $answer['problem']['source'] = 'Cel mai rapid magazin din lume, folosit de milioane.';
        $answer['features'][0]['text'] = str_repeat('foarte lung ', 10);
        $answer['features'][] = ['title' => 'Facturi', 'text' => 'Facturi trimise pe e-mail.', 'source' => 'Facturi PDF trimise pe e-mail.'];
        $this->app->instance(TextGenerator::class, new FakeTextGenerator([json_encode($answer)]));

        $script = app(ScriptWriter::class)->write($this->facts());

        $this->assertSame(['hook', 'feature', 'feature', 'start', 'proof', 'cta'], array_column($script->scenes, 'key'));
        $this->assertSame(['Plăți fără cont', 'Facturi'], [$script->scenes[1]['title'], $script->scenes[2]['title']]);
        $this->assertSame([1, 2], [$script->scenes[1]['index'], $script->scenes[2]['index']]);
        $this->assertSame(['problem', 'feature 1'], array_column($script->rejected, 'scene'));
        $this->assertSame('Citatul-sursă nu apare în textele trimise.', $script->rejected[0]['reason']);
    }

    public function test_an_unusable_opening_closing_or_command_is_replaced_by_the_template_one(): void
    {
        $answer = $this->answer();
        $answer['hook']['source'] = 'scurt';
        $answer['cta'] = null;
        $answer['start']['command'] = 'rm -rf /';
        $this->app->instance(TextGenerator::class, new FakeTextGenerator([json_encode($answer)]));

        $script = app(ScriptWriter::class)->write($this->facts());

        $scenes = collect($script->scenes)->keyBy('key');
        $this->assertSame('Un magazin mic, cu coș de cumpărături și plăți online.', $scenes['hook']['text']);
        $this->assertSame('Vezi proiectul pe GitHub.', $scenes['cta']['text']);
        $this->assertSame('composer install', $scenes['start']['command']);
        $this->assertSame('Pornești cu o comandă din README.', $scenes['start']['text']);
        $this->assertSame(['hook', 'cta', 'start'], array_column($script->rejected, 'scene'));
    }

    public function test_texts_get_comma_below_letters_and_lose_emoji_and_a_foreign_accent(): void
    {
        $answer = $this->answer();
        $answer['hook']['text'] = 'Coşul care ţine minte 🚀';
        $answer['hook']['accent'] = 'altceva';
        $this->app->instance(TextGenerator::class, new FakeTextGenerator([json_encode($answer)]));

        $hook = app(ScriptWriter::class)->write($this->facts())->scenes[0];

        $this->assertSame('Coșul care ține minte', $hook['text']);
        $this->assertNull($hook['accent']);
    }

    public function test_an_answer_that_is_not_json_gives_the_template_script(): void
    {
        $this->app->instance(TextGenerator::class, new FakeTextGenerator(['Iată scenariul: hook, problemă, final.']));

        $script = app(ScriptWriter::class)->write($this->facts());

        $this->assertSame('template', $script->writer);
        $this->assertSame(['hook', 'start', 'proof', 'cta'], array_column($script->scenes, 'key'));
        $this->assertNotNull($script->note);
    }

    public function test_without_ai_the_script_comes_from_the_description_and_the_numbers(): void
    {
        $this->app->instance(TextGenerator::class, new NullTextGenerator);

        $script = app(ScriptWriter::class)->write($this->facts());

        $this->assertSame('template', $script->writer);
        $this->assertNull($script->model);
        [$hook, $start, $proof, $cta] = $script->scenes;
        $this->assertSame('Un magazin mic, cu coș de cumpărături și plăți online.', $hook['text']);
        $this->assertSame($hook['text'], $hook['narration']);
        $this->assertSame('README.md', $hook['source']['label']);
        $this->assertSame('composer install', $start['command']);
        $this->assertSame(['Pe GitHub', 1234, ['PHP', 'TypeScript', 'MIT']], [$proof['label'], $proof['stars'], $proof['chips']]);
        $this->assertSame('Are 1234 de stele pe GitHub, e scris în PHP și TypeScript și are licența MIT.', $proof['narration']);
        $this->assertSame('12 fișiere și 3.400 de linii de cod, analizate de GitChronicle', $proof['note']);
        $this->assertSame('Îl găsești pe GitHub, la adresa de pe ecran.', $cta['narration']);
    }

    public function test_a_failing_ai_gives_the_template_script_with_its_reason(): void
    {
        $this->app->instance(TextGenerator::class, new FakeTextGenerator(exception: new AiException(AiException::LIMIT_MESSAGE, retryable: true)));

        $script = app(ScriptWriter::class)->write($this->facts());

        $this->assertSame('template', $script->writer);
        $this->assertSame(AiException::LIMIT_MESSAGE, $script->note);
    }

    public function test_an_english_description_is_shown_but_the_romanian_voice_only_says_the_name(): void
    {
        $this->app->instance(TextGenerator::class, new NullTextGenerator);
        $description = 'A small shop with a shopping cart: it remembers the products between visits and it sends the invoices by e-mail to every single customer.';

        $hook = app(ScriptWriter::class)->write($this->facts(description: $description))->scenes[0];

        $this->assertSame('A small shop with a shopping cart.', $hook['text']);
        $this->assertSame('Acesta este magazin.', $hook['narration']);
    }

    public function test_a_repository_with_nothing_to_quote_never_calls_the_ai_and_shows_only_what_is_known(): void
    {
        $this->app->instance(TextGenerator::class, $ai = new FakeTextGenerator);
        $facts = new PresentationFacts(
            owner: 'acme', name: 'gol', url: 'https://github.com/acme/gol', sha: null, description: null, sources: [],
            github: null, license: null, languages: [], modules: [], stats: ['files' => 0, 'lines' => 0, 'modules' => 0],
            history: null, startCommand: null, commands: [], technologies: [], diagram: null,
        );

        $script = app(ScriptWriter::class)->write($facts);

        $ai->assertSentCount(0);
        $this->assertSame(['hook', 'cta'], array_column($script->scenes, 'key'));
        $this->assertNull($script->scenes[0]['text']);
        $this->assertNull($script->scenes[0]['source']);
    }

    /**
     * A valid answer of Claude for the facts below.
     *
     * @return array<string, mixed>
     */
    private function answer(): array
    {
        return [
            'hook' => ['text' => 'Magazinul mic care ține minte coșul.', 'accent' => 'ține minte', 'source' => 'Un magazin mic, cu coș de cumpărături și plăți online.'],
            'problem' => ['text' => 'Cumperi fără să îți faci cont.', 'accent' => 'fără', 'source' => 'Plăți cu cardul, fără cont.'],
            'features' => [
                ['title' => 'Coș care nu uită', 'text' => 'Ține minte produsele între vizite.', 'accent' => 'nu uită', 'source' => 'Coș care ține minte produsele între vizite.'],
                ['title' => 'Plăți fără cont', 'text' => 'Plătești cu cardul, direct.', 'accent' => null, 'source' => 'plăți cu cardul, fără cont'],
            ],
            'start' => ['text' => 'Îl pornești cu o singură comandă.', 'command' => 'composer install', 'source' => 'composer install'],
            'cta' => ['text' => 'Încearcă magazinul chiar azi.', 'accent' => 'chiar azi', 'source' => 'Un magazin mic, cu coș de cumpărături'],
        ];
    }

    /**
     * The facts of a small shop with a README, 1234 stars and an MIT license.
     */
    private function facts(string $description = 'Un magazin mic, cu coș de cumpărături și plăți online.'): PresentationFacts
    {
        return new PresentationFacts(
            owner: 'acme',
            name: 'magazin',
            url: 'https://github.com/acme/magazin',
            sha: 'abc',
            description: $description,
            sources: [
                'README.md' => ['text' => self::README, 'url' => 'https://github.com/acme/magazin/blob/abc/README.md'],
                'GitHub' => ['text' => "Stele: 1234\nLimbaj principal: PHP\nLicență: MIT", 'url' => 'https://github.com/acme/magazin'],
            ],
            github: ['stars' => 1234, 'forks' => 5, 'language' => 'PHP', 'license' => 'MIT', 'topics' => [], 'homepage' => null, 'description' => null],
            license: 'MIT',
            languages: [
                ['name' => 'PHP', 'files' => 9, 'lines' => 2800, 'percent' => 82],
                ['name' => 'TypeScript', 'files' => 3, 'lines' => 600, 'percent' => 18],
            ],
            modules: [['path' => 'src/Models', 'files' => 2, 'lines' => 14]],
            stats: ['files' => 12, 'lines' => 3400, 'modules' => 3],
            history: null,
            startCommand: ['command' => 'composer install', 'url' => 'https://github.com/acme/magazin/blob/abc/README.md'],
            commands: [],
            technologies: [],
            diagram: null,
        );
    }
}

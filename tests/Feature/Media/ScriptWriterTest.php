<?php

namespace Tests\Feature\Media;

use App\Ai\AiException;
use App\Ai\NullTextGenerator;
use App\Ai\TextGenerator;
use App\Enums\MediaLanguage;
use App\Media\PresentationFacts;
use App\Media\ScriptWriter;
use App\Support\StoredMessage;
use Tests\Support\FakeTextGenerator;
use Tests\TestCase;

/**
 * The script of the video: Claude's JSON (always FakeTextGenerator here) is kept only where every scene
 * quotes the context it received, in both languages from one call; everything else comes from the facts.
 */
class ScriptWriterTest extends TestCase
{
    private const README = "# Magazin\n\nA small shop with a cart that remembers 3 products and online payments.\n\n"
        ."## Features\n\n- **Cart** that remembers the products between visits.\n- Card payments, without an account.\n\n"
        ."## Install\n\n```bash\ncomposer install\n```\n";

    public function test_one_call_gives_the_story_in_both_languages_with_verified_anchors(): void
    {
        $this->app->instance(TextGenerator::class, $ai = new FakeTextGenerator(["```json\n".json_encode($this->answer(), JSON_UNESCAPED_UNICODE)."\n```"]));

        $script = app(ScriptWriter::class)->write($this->facts());

        $ai->assertSentCount(1);
        $this->assertStringContainsString('Repository: acme/magazin', $ai->requests[0]['user']);
        $this->assertStringContainsString("### README.md\n\n# Magazin", $ai->requests[0]['user']);
        $this->assertStringContainsString('"quote"', $ai->requests[0]['system']);
        $this->assertSame(['ai', 'claude-opus-5-5', []], [$script->writer, $script->model, $script->rejected]);
        $this->assertSame(['hook', 'problem', 'feature', 'feature', 'built', 'proof', 'start', 'cta'], array_column($script->scenes, 'key'));

        $english = $script->scenesFor(MediaLanguage::En);
        $romanian = $script->scenesFor(MediaLanguage::Ro);

        $this->assertSame(['What it is', 'A small shop that remembers your cart.'], [$english[0]['label'], $english[0]['narration']]);
        $this->assertSame(['Ce este', 'Un magazin mic care îți ține minte coșul.'], [$romanian[0]['label'], $romanian[0]['narration']]);
        $this->assertSame(['label' => 'README.md', 'quote' => 'A small shop with a cart', 'url' => 'https://github.com/acme/magazin/blob/abc/README.md'], $english[0]['source']);
        $this->assertSame(['Features', 1, 2, 'A cart that remembers', 'Coș care nu uită'], [$english[2]['heading'], $english[2]['index'], $english[2]['count'], $english[2]['title'], $romanian[2]['title']]);
        // An unknown heading is forgotten, the scene stays.
        $this->assertNull($english[3]['heading']);
        $this->assertSame(['composer install', 'Install it with Composer.'], [$english[6]['command'], $english[6]['narration']]);
        // The last line is the AI's ending; the address stays on the screen.
        $this->assertSame(['Data viitoare, coșul te așteaptă.', 'github.com/acme/magazin'], [$romanian[7]['narration'], $romanian[7]['text']]);
        $this->assertArrayNotHasKey('en', $english[0]);
        $this->assertSame($this->answer(), $script->answer);
        $this->assertSame(ScriptWriter::contextHash($this->facts()), $script->contextHash);
    }

    public function test_a_scene_with_an_invented_quote_a_long_text_or_its_own_number_is_dropped(): void
    {
        $answer = $this->answer();
        $answer['problem']['quote'] = 'The fastest shop in the world, used by millions.';
        $answer['features'][0]['ro'] = str_repeat('foarte lung ', 20);
        $answer['features'][1]['en'] = 'Pay by card in 5 seconds.';
        $answer['features'][] = ['heading' => null, 'title_en' => 'Three products', 'title_ro' => 'Trei produse', 'en' => 'It remembers 3 products.', 'ro' => 'Ține minte 3 produse.', 'quote' => 'a cart that remembers 3 products'];
        $answer['start']['command'] = 'npm install';
        $this->app->instance(TextGenerator::class, new FakeTextGenerator([json_encode($answer)]));

        $script = app(ScriptWriter::class)->write($this->facts());

        $this->assertSame(['hook', 'feature', 'built', 'proof', 'start', 'cta'], array_column($script->scenes, 'key'));
        $this->assertSame(['problem', 'feature 1', 'feature 2', 'start'], array_column($script->rejected, 'scene'));
        $this->assertSame(StoredMessage::make('media.script.invented_number', ['number' => '5']), $script->rejected[2]['reason']);
        // The number 3 is in the quote, so the scene that says it stays; the README's own command replaces the invented one.
        $english = $script->scenesFor(MediaLanguage::En);
        $this->assertSame(['It remembers 3 products.', 1, 1], [$english[1]['narration'], $english[1]['index'], $english[1]['count']]);
        $this->assertSame(['composer install', 'Want to try it? The first step in the README is the command on screen.'], [$english[4]['command'], $english[4]['narration']]);
    }

    public function test_without_ai_the_script_is_built_from_the_facts_in_both_languages(): void
    {
        $this->app->instance(TextGenerator::class, new NullTextGenerator);

        $script = app(ScriptWriter::class)->write($this->facts());

        $this->assertSame('template', $script->writer);
        $this->assertSame(StoredMessage::make('media.script.ai_off'), $script->note);
        $this->assertSame(['hook', 'feature', 'built', 'proof', 'start', 'cta'], array_column($script->scenes, 'key'));

        $english = $script->scenesFor(MediaLanguage::En);
        $romanian = $script->scenesFor(MediaLanguage::Ro);

        // The English description is read by the English voice only; the Romanian one says the name.
        $this->assertSame('This is magazin, by acme. A small shop with online payments.', $english[0]['narration']);
        $this->assertSame('Acesta e magazin, de la acme.', $romanian[0]['narration']);
        $this->assertSame('A small shop with online payments.', $romanian[0]['text']);
        $this->assertSame([['Features', 'Install'], "Open the README and you'll find Features and Install."], [$english[1]['headings'], $english[1]['narration']]);
        $this->assertSame("Under the hood, it's mostly PHP and TypeScript, split into 12 modules.", $english[2]['narration']);
        $this->assertSame('Pe dinăuntru e scris mai ales în PHP și TypeScript, împărțit în 12 module.', $romanian[2]['narration']);
        $this->assertSame("It's picked up over 1 thousand stars and 56 forks on GitHub. 214 commits so far — the latest in November 2026. 3 contributors have worked on it.", $english[3]['narration']);
        $this->assertSame('Pe GitHub a strâns peste o mie de stele și 56 de forkuri. Până acum, 214 commituri — ultimul în noiembrie 2026. La el au lucrat 3 contribuitori.', $romanian[3]['narration']);
        $this->assertSame([['value' => '214', 'label' => 'commits'], ['value' => '3', 'label' => 'contributors'], ['value' => 'November 2026', 'label' => 'latest commit']], $english[3]['stats']);
        $this->assertSame('github.com/acme/magazin', $english[5]['url']);
        // The video is about the repository: GitChronicle is only a small credit on the screen, never said.
        $this->assertStringNotContainsString('GitChronicle', $english[5]['narration']);
        $this->assertSame('Made with GitChronicle', $english[5]['credit']);
    }

    public function test_the_same_sources_reuse_the_answer_without_a_new_call(): void
    {
        $this->app->instance(TextGenerator::class, $ai = new FakeTextGenerator([json_encode($this->answer())]));
        $writer = app(ScriptWriter::class);
        $first = $writer->write($this->facts());

        $again = $writer->write($this->facts(stars: 99999), $first->toArray());

        $ai->assertSentCount(1);
        $this->assertSame('ai', $again->writer);
        $this->assertSame('A small shop that remembers your cart.', $again->scenesFor(MediaLanguage::En)[0]['narration']);
        $this->assertStringContainsString('over 99 thousand stars', $again->scenesFor(MediaLanguage::En)[5]['narration']);

        // Other sources: the old answer is not trusted any more, Claude is asked again.
        $writer->write($this->facts(readme: self::README."\nNew paragraph.\n"), $first->toArray());
        $ai->assertSentCount(2);
    }

    public function test_a_failed_call_or_an_unusable_answer_falls_back_to_the_template(): void
    {
        $this->app->instance(TextGenerator::class, new FakeTextGenerator(['I cannot answer with JSON.']));
        $this->assertSame('template', app(ScriptWriter::class)->write($this->facts())->writer);

        $this->app->instance(TextGenerator::class, new FakeTextGenerator(exception: new AiException('Claude nu a răspuns la timp.')));
        $script = app(ScriptWriter::class)->write($this->facts());
        $this->assertSame(['template', 'Claude nu a răspuns la timp.'], [$script->writer, $script->note]);
    }

    /**
     * A good answer: every quote is a piece of the README above.
     *
     * @return array<string, mixed>
     */
    private function answer(): array
    {
        return [
            'hook' => ['en' => 'A small shop that remembers your cart.', 'ro' => 'Un magazin mic care îţi ține minte coșul.', 'quote' => 'A small shop with a cart'],
            'problem' => ['en' => 'Carts are usually lost between visits.', 'ro' => 'De obicei, coșul se pierde între vizite.', 'quote' => 'remembers the products between visits'],
            'features' => [
                ['heading' => 'Features', 'title_en' => 'A cart that remembers', 'title_ro' => 'Coș care nu uită', 'en' => 'The cart keeps your products between visits.', 'ro' => 'Coșul îți păstrează produsele între vizite.', 'quote' => '**Cart** that remembers the products between visits.'],
                ['heading' => 'Pricing', 'title_en' => 'No account needed', 'title_ro' => 'Fără cont', 'en' => 'Pay by card without an account.', 'ro' => 'Plătești cu cardul, fără cont.', 'quote' => 'Card payments, without an account.'],
            ],
            'start' => ['en' => 'Install it with Composer.', 'ro' => 'Îl instalezi cu Composer.', 'command' => 'composer install'],
            'ending' => ['en' => 'Next visit, your cart is still there.', 'ro' => 'Data viitoare, coșul te așteaptă.', 'quote' => 'remembers the products between visits'],
        ];
    }

    /**
     * The facts of a small shop repository.
     */
    private function facts(int $stars = 1234, string $readme = self::README): PresentationFacts
    {
        $url = 'https://github.com/acme/magazin/blob/abc/README.md';

        return new PresentationFacts(
            owner: 'acme',
            name: 'magazin',
            url: 'https://github.com/acme/magazin',
            sha: 'abc',
            description: 'A small shop with online payments.',
            sources: ['README.md' => ['text' => $readme, 'url' => $url]],
            github: ['stars' => $stars, 'forks' => 56, 'language' => 'PHP', 'license' => 'MIT', 'topics' => [], 'homepage' => null, 'description' => 'A small shop with online payments.'],
            license: 'MIT',
            languages: [['name' => 'PHP', 'files' => 40, 'lines' => 3000, 'percent' => 70], ['name' => 'TypeScript', 'files' => 10, 'lines' => 900, 'percent' => 21], ['name' => 'Markdown', 'files' => 3, 'lines' => 400, 'percent' => 9]],
            modules: [['path' => 'app/Cart', 'files' => 9, 'lines' => 700]],
            stats: ['files' => 53, 'lines' => 4300, 'modules' => 12],
            history: ['commit_count' => 214, 'contributor_count' => 3, 'first_commit_at' => '2026-01-05T10:00:00+00:00', 'last_commit_at' => '2026-11-02T10:00:00+00:00', 'contributors' => ['Ana', 'Bogdan']],
            startCommand: ['command' => 'composer install', 'url' => $url],
            commands: [],
            technologies: ['Laravel 13'],
            diagram: null,
            headings: ['Magazin', 'Features', 'Install'],
        );
    }
}

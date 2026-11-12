<?php

namespace Tests\Feature\Media;

use App\Enums\MediaLanguage;
use App\Jobs\GenerateMedia;
use App\Media\GitHubCapture;
use App\Media\PageCapture;
use App\Media\PresentationFacts;
use App\Media\SceneDirector;
use App\Models\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\FakesMediaTools;
use Tests\TestCase;

/**
 * The photo of the GitHub page: how it is made and kept (the browser script is faked and writes the
 * landmarks recorded from one real capture of excusemymanners/gitchronicles), what the map of the page
 * answers, and which real elements the camera gets for every scene.
 */
class PageCaptureTest extends TestCase
{
    use FakesMediaTools;
    use RefreshDatabase;

    public function test_the_capture_is_made_once_per_commit_with_an_environment_without_secrets(): void
    {
        $this->fakeMediaTools();
        putenv('SECRET_FOR_TEST=hunter2');
        $repository = Repository::factory()->ready()->create(['owner' => 'excusemymanners', 'name' => 'gitchronicles', 'last_sha' => str_repeat('a', 40)]);

        $capture = app(GitHubCapture::class)->capture($repository);
        app(GitHubCapture::class)->capture($repository);

        putenv('SECRET_FOR_TEST');
        $this->assertTrue($capture->isAvailable());
        $this->assertCount(1, $this->commandsOf('capture'));
        [$command] = $this->commandsOf('capture');
        $folder = "media/{$repository->id}/capture/".str_repeat('a', 40);
        $this->assertSame(['excusemymanners', 'gitchronicles', Storage::disk('local')->path("{$folder}.new"), '20000'], array_slice($command, 2));
        Storage::disk('local')->assertExists(["{$folder}/landmarks.json", "{$folder}/page-00.png"]);

        $environment = $this->toolEnvironments[0];
        $this->assertFalse($environment['SECRET_FOR_TEST']);
        $this->assertArrayNotHasKey('PATH', $environment);
        $this->assertSame('/usr/bin/google-chrome', $environment['CHROME_PATH']);

        // A regeneration asked by a person takes a new photo; a new commit replaces the old folder.
        app(GitHubCapture::class)->capture($repository, fresh: true);
        $repository->update(['last_sha' => str_repeat('b', 40)]);
        app(GitHubCapture::class)->capture($repository);
        $this->assertCount(3, $this->commandsOf('capture'));
        $this->assertSame(["media/{$repository->id}/capture/".str_repeat('b', 40)], Storage::disk('local')->directories("media/{$repository->id}/capture"));
    }

    public function test_a_photo_of_an_older_version_is_taken_again(): void
    {
        $this->fakeMediaTools();
        $repository = Repository::factory()->ready()->create(['last_sha' => str_repeat('a', 40)]);
        $folder = "media/{$repository->id}/capture/".str_repeat('a', 40);
        $older = [...$this->data(), 'version' => 1, 'viewport' => ['width' => 432, 'scale' => 3]];
        Storage::disk('local')->put("{$folder}/landmarks.json", json_encode($older));
        Storage::disk('local')->put("{$folder}/page-00.png", 'png');

        $this->assertFalse(app(GitHubCapture::class)->stored($repository)->isAvailable());

        $capture = app(GitHubCapture::class)->capture($repository);

        $this->assertCount(1, $this->commandsOf('capture'));
        $this->assertSame(2.7, $capture->forComposition('capture')['scale']);
    }

    public function test_a_failed_or_disabled_capture_is_missing_with_its_reason_and_never_a_stale_photo(): void
    {
        $this->fakeMediaTools(failing: ['capture']);
        $repository = Repository::factory()->ready()->create(['last_sha' => str_repeat('a', 40)]);

        $capture = app(GitHubCapture::class)->capture($repository);

        $this->assertFalse($capture->isAvailable());
        $this->assertSame('GitHub nu a putut fi contactat când s-a generat video-ul.', $capture->reason);
        $this->assertSame('none', $capture->fingerprint());
        $this->assertNull($capture->forComposition('capture'));
        $this->assertSame([], Storage::disk('local')->directories("media/{$repository->id}/capture"));

        config(['gitchronicle.media.capture.enabled' => false]);
        $this->assertSame(GitHubCapture::DISABLED_REASON, app(GitHubCapture::class)->capture($repository)->reason);
        $this->assertCount(1, $this->commandsOf('capture'));
    }

    public function test_the_map_finds_landmarks_headings_quotes_and_code_lines(): void
    {
        $capture = $this->capture();

        $this->assertSame('gitchronicles', $capture->landmark('name')['text']);
        $this->assertNull($capture->landmark('about'));
        $this->assertSame(1631, $capture->heading('ce face')['y']);
        $this->assertNull($capture->heading('Nu există'));

        // A quote written with Markdown marks is found in the list item the page shows.
        $quote = $capture->locate('**Asistent pe cod**: răspunde la întrebări despre repository');
        $this->assertSame(['li', 1837], [$quote['kind'], $quote['y']]);
        // A command is found on its own line of the code block.
        $command = $capture->locate('composer install');
        $this->assertSame(['code', 'composer install', 8467], [$command['kind'], $command['text'], $command['y']]);
        $this->assertNull($capture->locate('a sentence the page does not have at all'));
        $this->assertNull($capture->locate('și'));

        $composition = $capture->forComposition('capture');
        // 400 CSS pixels photographed 2.7 times finer: exactly the 1080 pixels of the video, shown 1:1.
        $this->assertSame([400, 2.7, 'capture/page-00.png'], [$composition['width'], $composition['scale'], $composition['tiles'][0]['file']]);
        $this->assertNull($composition['social']);
        $this->assertNull($composition['logo']);
    }

    public function test_the_fingerprint_ignores_positions_and_changes_with_the_text(): void
    {
        $data = $this->data();
        $moved = $data;
        $moved['headings'][1]['y'] += 180;
        $moved['captured_at'] = '2027-01-01T00:00:00Z';
        $changed = $data;
        $changed['landmarks']['stars']['text'] = '1 star';

        $this->assertSame(PageCapture::from('/tmp', $data)->fingerprint(), PageCapture::from('/tmp', $moved)->fingerprint());
        $this->assertNotSame(PageCapture::from('/tmp', $data)->fingerprint(), PageCapture::from('/tmp', $changed)->fingerprint());
    }

    public function test_the_camera_gets_only_real_elements_for_every_scene(): void
    {
        $scenes = SceneDirector::direct([
            ['key' => 'hook', 'quote' => 'Asistent AI care documentează automat un repository GitHub', 'heading' => null],
            ['key' => 'feature', 'heading' => 'Ce face', 'quote' => 'o pagină pentru fiecare modul, cu navigare și căutare'],
            ['key' => 'feature', 'heading' => 'Ce face', 'quote' => 'citează fișierul și liniile exacte'],
            ['key' => 'feature', 'heading' => 'Ce face', 'headings' => ['Ce face', 'Stare'], 'quote' => null, 'narration' => 'Its README walks you through Ce face and Stare.'],
            ['key' => 'built', 'heading' => null, 'quote' => null],
            ['key' => 'proof', 'heading' => null, 'quote' => null, 'stars' => null, 'has_forks' => false, 'has_history' => true],
            ['key' => 'start', 'heading' => null, 'quote' => 'composer install'],
            ['key' => 'problem', 'heading' => null, 'quote' => 'something that is not on the page'],
            ['key' => 'cta', 'heading' => null, 'quote' => null],
        ], $this->capture());

        $kinds = array_map(fn (array $scene): array => array_column($scene['shots'], 'kind'), $scenes);

        // The repository has no description on GitHub: the opening shows the README paragraph it quotes.
        $this->assertSame(['name', 'about'], $kinds[0]);
        $this->assertSame(1331, $scenes[0]['shots'][1]['y']);
        $this->assertSame(['heading', 'quote'], $kinds[1]);
        // The second feature of the same section goes straight to its quote.
        $this->assertSame(['quote'], $kinds[2]);
        $this->assertSame(['heading', 'heading'], $kinds[3]);
        $this->assertEqualsWithDelta(0.617, $scenes[3]['shots'][0]['at'], 0.01);
        $this->assertSame(['files', 'languages'], $kinds[4]);
        $this->assertSame(SceneDirector::FILE_LIST_HEIGHT, $scenes[4]['shots'][0]['h']);
        // No stars are said, so the star counter is not shown; the latest commit is, as a whole row.
        $this->assertSame(['commit'], $kinds[5]);
        $this->assertSame([16, 400], [$scenes[5]['shots'][0]['x'], $scenes[5]['shots'][0]['w']]);
        $this->assertSame(['command'], $kinds[6]);
        // A quote the page does not have: the top of the README, without a frame.
        $this->assertSame([['page'], false], [$kinds[7], $scenes[7]['shots'][0]['framed']]);
        $this->assertSame(['top'], $kinds[8]);

        $withoutPhoto = SceneDirector::direct([['key' => 'hook', 'quote' => null]], PageCapture::missing('nu'));
        $this->assertSame([], $withoutPhoto[0]['shots']);
    }

    public function test_the_input_hash_changes_only_with_what_the_video_shows(): void
    {
        $facts = fn (int $stars, int $commits) => new PresentationFacts('acme', 'magazin', 'https://github.com/acme/magazin', 'abc', null, [], ['stars' => $stars, 'forks' => 2, 'language' => 'PHP', 'license' => null, 'topics' => [], 'homepage' => null, 'description' => null], null, [], [], ['files' => 1, 'lines' => 1, 'modules' => 1], ['commit_count' => $commits, 'contributor_count' => 4, 'first_commit_at' => '2026-01-01T00:00:00+00:00', 'last_commit_at' => '2026-11-02T00:00:00+00:00', 'contributors' => []], null, [], [], null);
        $languages = MediaLanguage::configured();
        $hash = GenerateMedia::inputHash($facts(81962, 3412), $this->capture(), $languages);

        // 81,962 → 81,990 stars and 3,412 → 3,413 commits are said the same way ("over 81 thousand", "over 3 thousand").
        $this->assertSame($hash, GenerateMedia::inputHash($facts(81990, 3413), $this->capture(), $languages));
        $this->assertNotSame($hash, GenerateMedia::inputHash($facts(82100, 3412), $this->capture(), $languages));
        $this->assertNotSame($hash, GenerateMedia::inputHash($facts(81962, 3412), PageCapture::missing('x'), $languages));
        $this->assertNotSame($hash, GenerateMedia::inputHash($facts(81962, 3412), $this->capture(), [MediaLanguage::Ro]));
    }

    /**
     * The landmarks recorded from the real capture.
     *
     * @return array<string, mixed>
     */
    private function data(): array
    {
        return json_decode((string) file_get_contents(base_path('tests/Fixtures/media/capture/landmarks.json')), true);
    }

    private function capture(): PageCapture
    {
        return PageCapture::from(base_path('tests/Fixtures/media/capture'), $this->data());
    }
}

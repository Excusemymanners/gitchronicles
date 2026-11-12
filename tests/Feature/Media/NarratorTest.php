<?php

namespace Tests\Feature\Media;

use App\Enums\MediaLanguage;
use App\Media\Narrator;
use App\Media\Speech\ChatterboxVoice;
use App\Media\Speech\EdgeVoice;
use App\Media\Speech\KokoroVoice;
use App\Media\Speech\PiperVoice;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\FakesMediaTools;
use Tests\TestCase;

/**
 * Who reads the video: the engine chosen per language by one line of configuration, with the language's
 * Piper model as the fallback. The engines are fakes: no Python, model or network is used.
 */
class NarratorTest extends TestCase
{
    use FakesMediaTools;

    public function test_a_neural_voice_reads_every_sentence_in_one_process_with_the_spoken_spelling(): void
    {
        $this->fakeMediaTools();
        config(['gitchronicle.media.voice.engines.en' => 'kokoro:am_michael']);
        $clips = $this->clips(['The README explains it.', 'It runs with npm.']);

        $narrator = Narrator::for(MediaLanguage::En);
        $narrator->speak($clips);

        $this->assertCount(1, $this->commandsOf('speak'));
        $this->assertCount(0, $this->commandsOf('piper'));
        [$command] = $this->commandsOf('speak');
        $this->assertSame(['kokoro', '--model'], array_slice($command, 2, 2));
        $this->assertSame('am_michael', $this->argumentAfter($command, '--voice'));
        // The voice gets the respelled text; the captions keep the real one.
        $this->assertSame(['The read me explains it.', 'It runs with N P M.'], array_column(json_decode($this->toolInputs['speech'], true), 'text'));
        $this->assertSame('Kokoro · am_michael', $narrator->label());

        foreach (array_keys($clips) as $path) {
            $this->assertFileExists($path);
        }
    }

    public function test_the_shape_of_each_sentence_reaches_the_engine_with_its_text(): void
    {
        $this->fakeMediaTools();
        config(['gitchronicle.media.voice.engines.en' => 'edge:en-US-AndrewMultilingualNeural']);
        $clips = $this->clips(['First.', 'Second.']);
        $paths = array_keys($clips);

        Narrator::for(MediaLanguage::En)->speak($clips, [$paths[0] => ['rate' => -0.05, 'pitch' => 0.06]]);

        $sent = json_decode($this->toolInputs['speech'], true);
        $this->assertSame([-0.05, 0.06], [$sent[0]['rate'], $sent[0]['pitch']]);
        $this->assertEquals([0.0, 0.0], [$sent[1]['rate'], $sent[1]['pitch']]);
    }

    public function test_when_the_online_voice_fails_piper_reads_the_whole_video(): void
    {
        $this->fakeMediaTools(failing: ['speak']);
        config(['gitchronicle.media.voice.engines.ro' => 'edge:ro-RO-EmilNeural']);

        $narrator = Narrator::for(MediaLanguage::Ro);
        $narrator->speak($this->clips(['Prima frază.', 'A doua frază.']));

        $this->assertCount(1, $this->commandsOf('speak'));
        $this->assertCount(2, $this->commandsOf('piper'));
        $this->assertSame('Piper · ro', $narrator->label());
    }

    public function test_the_setting_names_the_engine_and_the_voice(): void
    {
        $config = config('gitchronicle.media.voice');

        $this->assertInstanceOf(KokoroVoice::class, Narrator::voice('kokoro:bm_george', MediaLanguage::En, $config));
        $this->assertInstanceOf(ChatterboxVoice::class, Narrator::voice('chatterbox:0.7', MediaLanguage::En, $config));
        $this->assertSame('Edge (online) · en-US-AndrewMultilingualNeural', Narrator::voice('edge:en-US-AndrewMultilingualNeural', MediaLanguage::En, $config)->label());
        $this->assertNull(Narrator::voice('piper', MediaLanguage::Ro, $config));

        $piper = Narrator::voice('piper:en_US-ryan-high', MediaLanguage::En, $config);
        $this->assertInstanceOf(PiperVoice::class, $piper);
        $this->assertSame('Piper · en_US-ryan-high', $piper->label());
        $this->assertNotInstanceOf(EdgeVoice::class, $piper);
    }

    /**
     * One WAV path per sentence, in a folder of the fake disk.
     *
     * @param  list<string>  $sentences
     * @return array<string, string>
     */
    private function clips(array $sentences): array
    {
        Storage::disk('local')->makeDirectory('work');
        $clips = [];

        foreach ($sentences as $number => $sentence) {
            $clips[Storage::disk('local')->path("work/voice-0-{$number}.wav")] = $sentence;
        }

        return $clips;
    }
}

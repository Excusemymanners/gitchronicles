<?php

namespace Tests\Feature\Media;

use App\Media\AudioMixer;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * How the final picture is encoded: the configured encoder, or for "auto" the best one this FFmpeg has,
 * and the quality HyperFrames renders its master at.
 */
class AudioMixerTest extends TestCase
{
    public function test_auto_picks_libx264_when_ffmpeg_has_it_and_renders_an_almost_lossless_master(): void
    {
        Process::fake(['*' => Process::result(output: " V....D libx264              libx264 H.264 / AVC\n V....D h264_nvenc           NVIDIA NVENC H.264 encoder\n")]);
        $mixer = new AudioMixer('/usr/bin/ffmpeg', '/usr/bin/ffprobe', 'auto', 20);

        $this->assertSame('libx264', $mixer->encoder());
        $this->assertSame(['-c:v', 'libx264', '-preset', 'slow', '-crf', '20'], array_slice($mixer->videoArguments(), 0, 6));
        $this->assertSame(AudioMixer::MASTER_QUALITY, $mixer->masterQuality());
        // FFmpeg is asked once per mixer.
        Process::assertRanTimes(fn ($process): bool => in_array('-encoders', (array) $process->command, true), 1);
    }

    public function test_without_any_known_encoder_the_master_is_the_final_file_at_the_final_quality(): void
    {
        Process::fake(['*' => Process::result(output: " V....D libopenh264          OpenH264 H.264\n")]);
        $mixer = new AudioMixer('/usr/bin/ffmpeg', '/usr/bin/ffprobe', 'auto', 22);

        $this->assertSame('copy', $mixer->encoder());
        $this->assertSame(['-c:v', 'copy'], $mixer->videoArguments());
        $this->assertSame(22, $mixer->masterQuality());
    }

    public function test_the_nvidia_encoder_keeps_constant_quality_with_the_slowest_preset(): void
    {
        Process::fake();
        $arguments = (new AudioMixer('/usr/bin/ffmpeg', '/usr/bin/ffprobe', 'h264_nvenc', 19))->videoArguments();

        $this->assertSame(['-c:v', 'h264_nvenc', '-preset', 'p7'], array_slice($arguments, 0, 4));
        $this->assertSame('19', $arguments[array_search('-cq', $arguments, true) + 1]);
        $this->assertContains('yuv420p', $arguments);
        Process::assertNothingRan();
    }

    public function test_the_mix_has_no_music_input_unless_a_music_file_is_given(): void
    {
        $mixer = new AudioMixer('/usr/bin/ffmpeg', '/usr/bin/ffprobe', 'copy');
        $clips = [['path' => '/w/a.wav', 'at' => 0.35]];

        $voiceOnly = $mixer->command('/w/silent.mp4', $clips, null, 5.0, '/w/out.mp4');
        $this->assertCount(2, array_keys($voiceOnly, '-i', true));
        $this->assertStringNotContainsString('sidechaincompress', implode(' ', $voiceOnly));
        $this->assertStringContainsString('alimiter', implode(' ', $voiceOnly));

        $withMusic = $mixer->command('/w/silent.mp4', $clips, '/w/music.wav', 5.0, '/w/out.mp4');
        $this->assertCount(3, array_keys($withMusic, '-i', true));
        $this->assertStringContainsString('sidechaincompress', implode(' ', $withMusic));

        $silent = $mixer->command('/w/silent.mp4', [], null, 5.0, '/w/out.mp4');
        $this->assertStringContainsString('anullsrc', implode(' ', $silent));
    }

    public function test_a_voice_clip_is_trimmed_at_both_ends_and_left_alone_when_ffmpeg_fails(): void
    {
        Process::fake(['*' => Process::result(errorOutput: 'boom', exitCode: 1)]);
        $path = tempnam(sys_get_temp_dir(), 'clip');
        file_put_contents($path, 'RIFF');

        (new AudioMixer('/usr/bin/ffmpeg', '/usr/bin/ffprobe'))->trimSilence($path);

        Process::assertRan(fn ($process): bool => str_contains(implode(' ', (array) $process->command), 'silenceremove') && str_contains(implode(' ', (array) $process->command), 'areverse'));
        $this->assertSame('RIFF', file_get_contents($path));
        unlink($path);
    }
}

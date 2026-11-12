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
}

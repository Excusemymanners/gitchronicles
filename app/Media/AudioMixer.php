<?php

namespace App\Media;

use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * The sound of the video, made with FFmpeg in one command and put on the silent video from HyperFrames.
 * The same command encodes the final picture (videoArguments()): HyperFrames writes a "master" of almost
 * lossless quality and the final file is encoded here once, with settings chosen for screen content (sharp
 * text on flat colours), so we decide the quality instead of the renderer's defaults:
 *
 * 1. every voice clip is moved to its moment (adelay), the clips are mixed into one voice track and the
 *    track is brought to the same loudness whatever the voice (voiceGain(): each Piper model speaks at
 *    its own level, and the English and the Romanian video must sound equally loud);
 * 2. the voice is raised by FINAL_GAIN and kept under 0 dB by a limiter. By default that is the whole sound:
 *    the voice alone, silence between the sentences (the owner does not want a music bed);
 * 3. only when a music file is given (config gitchronicle.media.music, off by default): the music
 *    (renderers/audio/music.mjs) is turned down to MUSIC_VOLUME and "ducked" under the voice (a compressor
 *    listens to the voice, the sidechain, and lowers the music while somebody speaks), then mixed in;
 * 4. the result is encoded as AAC next to the picture, with the index at the start of the file (faststart),
 *    so the browser can play and seek before downloading everything.
 */
final class AudioMixer
{
    /**
     * The level of the music under the voice (1 = as generated).
     */
    public const MUSIC_VOLUME = 0.25;

    /**
     * The gain of the final mix, before the limiter (about +3.5 dB, which brings the video to roughly
     * -14 LUFS, the loudness social platforms play at).
     */
    public const FINAL_GAIN = 1.5;

    /**
     * The loudness every voice is brought to before the mix, in LUFS, and the largest correction in dB.
     */
    public const VOICE_LUFS = -17.0;

    public const MAX_VOICE_GAIN_DB = 9.0;

    /**
     * The quality of HyperFrames' master when the picture is encoded again here (CRF / NVENC CQ 10: about
     * 20 Mbit/s, visually identical to Chrome's frames). It is a work file, deleted after the mix.
     */
    public const MASTER_QUALITY = 10;

    /**
     * The encoder picked for "auto" (asking FFmpeg once per mixer).
     */
    private ?string $detectedEncoder = null;

    /**
     * Create the mixer with the paths from config('gitchronicle.media').
     */
    public function __construct(
        private string $ffmpeg,
        private string $ffprobe,
        private string $videoEncoder = 'copy',
        private int $videoQuality = 20,
    ) {}

    /**
     * Make the mixer from the configuration.
     */
    public static function fromConfig(): self
    {
        return new self(
            config('gitchronicle.media.ffmpeg_path'),
            config('gitchronicle.media.ffprobe_path'),
            (string) config('gitchronicle.media.video.encoder', 'auto'),
            (int) config('gitchronicle.media.video.quality', 20),
        );
    }

    /**
     * The encoder of the final picture: the configured one, or for "auto" the best this FFmpeg has —
     * libx264 (the reference H.264 encoder), else NVENC when an NVIDIA GPU is present (Fedora's FFmpeg is
     * built without libx264), else "copy" (HyperFrames' own file is the final one).
     */
    public function encoder(): string
    {
        if ($this->videoEncoder !== 'auto') {
            return $this->videoEncoder;
        }

        if ($this->detectedEncoder === null) {
            $encoders = Process::timeout(30)->run([$this->ffmpeg, '-hide_banner', '-encoders'])->output();

            $this->detectedEncoder = match (true) {
                preg_match('/\slibx264\s/', $encoders) === 1 => 'libx264',
                preg_match('/\sh264_nvenc\s/', $encoders) === 1 && file_exists('/dev/nvidiactl') => 'h264_nvenc',
                default => 'copy',
            };
        }

        return $this->detectedEncoder;
    }

    /**
     * The quality HyperFrames renders at: almost lossless when the picture is encoded again here, the final
     * quality when its file is kept as it is.
     */
    public function masterQuality(): int
    {
        return $this->encoder() === 'copy' ? $this->videoQuality : self::MASTER_QUALITY;
    }

    /**
     * The FFmpeg options of the final picture. Both encoders aim at constant visual quality (CRF / CQ =
     * config gitchronicle.media.video.quality, 20 by default: measured indistinguishable from the master on
     * text), H.264 High profile, yuv420p (plays in every browser) and a keyframe every 2 seconds (seeking).
     *
     * @return list<string>
     */
    public function videoArguments(): array
    {
        $quality = (string) $this->videoQuality;

        return match ($this->encoder()) {
            'libx264' => ['-c:v', 'libx264', '-preset', 'slow', '-crf', $quality, '-profile:v', 'high', '-pix_fmt', 'yuv420p', '-g', '60'],
            'h264_nvenc' => [
                '-c:v', 'h264_nvenc', '-preset', 'p7', '-tune', 'hq', '-rc', 'vbr', '-cq', $quality, '-b:v', '0',
                '-spatial-aq', '1', '-temporal-aq', '1', '-rc-lookahead', '32', '-profile:v', 'high', '-pix_fmt', 'yuv420p', '-g', '60',
            ],
            default => ['-c:v', 'copy'],
        };
    }

    /**
     * The length of an audio or video file, in seconds.
     *
     * @throws RuntimeException when ffprobe cannot read it
     */
    public function duration(string $path): float
    {
        $result = Process::timeout(30)->run([
            $this->ffprobe, '-v', 'error', '-show_entries', 'format=duration', '-of', 'default=noprint_wrappers=1:nokey=1', $path,
        ]);

        $seconds = (float) trim($result->output());

        if ($result->failed() || $seconds <= 0) {
            throw new RuntimeException("ffprobe could not read the length of {$path}.");
        }

        return $seconds;
    }

    /**
     * Cut the silence at the start and at the end of a voice clip (each engine leaves its own, from 0.05 to
     * 0.3 s), keeping a few hundredths of a second so no word is clipped. The pauses of the video are then
     * exactly the ones the timeline plans (VideoTimeline::SENTENCE_GAP, VOICE_TAIL), whatever the voice.
     * The clip is left as it was when FFmpeg fails.
     */
    public function trimSilence(string $path): void
    {
        $edge = 'silenceremove=start_periods=1:start_threshold=-45dB:start_silence=%s';
        $temporary = $path.'.trim.wav';
        $result = Process::timeout(30)->run([
            $this->ffmpeg, '-v', 'error', '-y', '-i', $path,
            '-af', sprintf($edge, '0.03').',areverse,'.sprintf($edge, '0.05').',areverse', $temporary,
        ]);

        if ($result->successful() && is_file($temporary) && filesize($temporary) > 1000) {
            rename($temporary, $path);
        } elseif (is_file($temporary)) {
            unlink($temporary);
        }
    }

    /**
     * How many dB the voice clips must be raised (or lowered) to reach VOICE_LUFS: FFmpeg measures the
     * loudness of all the clips played one after another (EBU R128, the "loudnorm" filter in measuring
     * mode). 0 when it cannot be measured.
     *
     * @param  list<string>  $clipPaths
     */
    public function voiceGain(array $clipPaths): float
    {
        if ($clipPaths === []) {
            return 0.0;
        }

        $inputs = [];
        $labels = '';

        foreach ($clipPaths as $position => $path) {
            array_push($inputs, '-i', $path);
            $labels .= "[{$position}:a]";
        }

        $result = Process::timeout(60)->run([
            $this->ffmpeg, '-hide_banner', '-nostats', ...$inputs,
            '-filter_complex', $labels.'concat=n='.count($clipPaths).':v=0:a=1,loudnorm=print_format=json[out]',
            '-map', '[out]', '-f', 'null', '-',
        ]);

        if ($result->failed() || preg_match('/"input_i"\s*:\s*"(-?[0-9.]+)"/', $result->errorOutput(), $match) !== 1) {
            return 0.0;
        }

        return round(max(-self::MAX_VOICE_GAIN_DB, min(self::MAX_VOICE_GAIN_DB, self::VOICE_LUFS - (float) $match[1])), 2);
    }

    /**
     * Put the voice clips (and, if one is given, the music) on the silent video.
     *
     * @param  list<array{path: string, at: float}>  $clips  each voice clip and the second it starts at
     * @param  string|null  $music  the music file, or null for the voice alone (the default)
     * @param  float  $voiceGainDb  the correction of the voice's loudness (voiceGain())
     *
     * @throws RuntimeException when FFmpeg fails
     */
    public function mix(string $silentVideo, array $clips, ?string $music, float $totalSeconds, string $output, float $voiceGainDb = 0.0): void
    {
        $result = Process::timeout(600)->run($this->command($silentVideo, $clips, $music, $totalSeconds, $output, $voiceGainDb));

        if ($result->failed()) {
            throw new RuntimeException('FFmpeg could not mix the sound: '.mb_substr(trim($result->errorOutput()), -800));
        }
    }

    /**
     * The FFmpeg command line, as an array (no shell).
     *
     * @param  list<array{path: string, at: float}>  $clips
     * @return list<string>
     */
    public function command(string $silentVideo, array $clips, ?string $music, float $totalSeconds, string $output, float $voiceGainDb = 0.0): array
    {
        $inputs = ['-i', $silentVideo];

        foreach ($clips as $clip) {
            array_push($inputs, '-i', $clip['path']);
        }

        $filters = [];

        if ($clips === []) {
            // Nothing to say: the music alone, or silence.
            array_push($inputs, ...($music === null ? ['-f', 'lavfi', '-i', 'anullsrc=r=48000:cl=stereo'] : ['-i', $music]));
            $filters[] = '[1:a]aresample=48000'.($music === null ? '' : ',volume='.self::MUSIC_VOLUME).',volume='.self::FINAL_GAIN.',alimiter=limit=0.9:level=false[out]';
        } else {
            $music === null || array_push($inputs, '-i', $music);
            $labels = '';

            foreach ($clips as $position => $clip) {
                $delay = (int) round($clip['at'] * 1000);
                $input = $position + 1;
                $filters[] = "[{$input}:a]aresample=48000,aformat=channel_layouts=stereo,adelay={$delay}:all=1[v{$position}]";
                $labels .= "[v{$position}]";
            }

            $voice = $labels.'amix=inputs='.count($clips).':normalize=0:duration=longest,volume='.number_format($voiceGainDb, 2, '.', '').'dB,apad';

            if ($music === null) {
                $filters[] = $voice.',volume='.self::FINAL_GAIN.',alimiter=limit=0.9:level=false[out]';
            } else {
                $musicInput = count($clips) + 1;
                $filters[] = $voice.'[voice]';
                $filters[] = '[voice]asplit=2[voicekey][voicemix]';
                $filters[] = "[{$musicInput}:a]aresample=48000,volume=".self::MUSIC_VOLUME.'[music]';
                $filters[] = '[music][voicekey]sidechaincompress=threshold=0.015:ratio=8:attack=15:release=500[ducked]';
                $filters[] = '[ducked][voicemix]amix=inputs=2:normalize=0:duration=first,volume='.self::FINAL_GAIN.',alimiter=limit=0.9:level=false[out]';
            }
        }

        return [
            $this->ffmpeg, '-v', 'error', '-y',
            ...$inputs,
            '-filter_complex', implode(';', $filters),
            '-map', '0:v', '-map', '[out]',
            ...$this->videoArguments(),
            '-c:a', 'aac', '-b:a', '160k',
            '-t', number_format($totalSeconds, 3, '.', ''),
            '-movflags', '+faststart',
            $output,
        ];
    }

    /**
     * Save one frame of the video as a JPEG (the poster shown before the video plays).
     *
     * @throws RuntimeException when FFmpeg fails
     */
    public function poster(string $video, float $atSeconds, string $output): void
    {
        $result = Process::timeout(60)->run([
            $this->ffmpeg, '-v', 'error', '-y', '-ss', number_format($atSeconds, 3, '.', ''), '-i', $video,
            '-frames:v', '1', '-q:v', '3', $output,
        ]);

        if ($result->failed()) {
            throw new RuntimeException('FFmpeg could not save the poster: '.trim($result->errorOutput()));
        }
    }
}

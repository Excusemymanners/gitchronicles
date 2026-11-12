<?php

namespace App\Media;

use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * The sound of the video, made with FFmpeg in one command and put on the silent video from HyperFrames
 * (the picture is copied as it is, not encoded again):
 *
 * 1. every voice clip is moved to its moment (adelay), the clips are mixed into one voice track and the
 *    track is brought to the same loudness whatever the voice (voiceGain(): each Piper model speaks at
 *    its own level, and the English and the Romanian video must sound equally loud);
 * 2. the music (renderers/audio/music.mjs) is turned down to MUSIC_VOLUME;
 * 3. the music is "ducked" under the voice: a compressor listens to the voice (sidechain) and lowers
 *    the music while somebody speaks, then lets it come back;
 * 4. voice and music are mixed, raised by FINAL_GAIN and kept under 0 dB by a limiter;
 * 5. the result is encoded as AAC next to the video, with the index at the start of the file (faststart),
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
     * Create the mixer with the paths from config('gitchronicle.media').
     */
    public function __construct(
        private string $ffmpeg,
        private string $ffprobe,
    ) {}

    /**
     * Make the mixer from the configuration.
     */
    public static function fromConfig(): self
    {
        return new self(config('gitchronicle.media.ffmpeg_path'), config('gitchronicle.media.ffprobe_path'));
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
     * Put the voice clips and the music on the silent video.
     *
     * @param  list<array{path: string, at: float}>  $clips  each voice clip and the second it starts at
     * @param  float  $voiceGainDb  the correction of the voice's loudness (voiceGain())
     *
     * @throws RuntimeException when FFmpeg fails
     */
    public function mix(string $silentVideo, array $clips, string $music, float $totalSeconds, string $output, float $voiceGainDb = 0.0): void
    {
        $result = Process::timeout(180)->run($this->command($silentVideo, $clips, $music, $totalSeconds, $output, $voiceGainDb));

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
    public function command(string $silentVideo, array $clips, string $music, float $totalSeconds, string $output, float $voiceGainDb = 0.0): array
    {
        $inputs = ['-i', $silentVideo];

        foreach ($clips as $clip) {
            array_push($inputs, '-i', $clip['path']);
        }

        array_push($inputs, '-i', $music);
        $musicInput = count($clips) + 1;
        $filters = [];

        if ($clips === []) {
            $filters[] = "[{$musicInput}:a]aresample=48000,volume=".self::MUSIC_VOLUME.',volume='.self::FINAL_GAIN.',alimiter=limit=0.9:level=false[out]';
        } else {
            $labels = '';

            foreach ($clips as $position => $clip) {
                $delay = (int) round($clip['at'] * 1000);
                $input = $position + 1;
                $filters[] = "[{$input}:a]aresample=48000,aformat=channel_layouts=stereo,adelay={$delay}:all=1[v{$position}]";
                $labels .= "[v{$position}]";
            }

            $filters[] = $labels.'amix=inputs='.count($clips).':normalize=0:duration=longest,volume='.number_format($voiceGainDb, 2, '.', '').'dB,apad[voice]';
            $filters[] = '[voice]asplit=2[voicekey][voicemix]';
            $filters[] = "[{$musicInput}:a]aresample=48000,volume=".self::MUSIC_VOLUME.'[music]';
            $filters[] = '[music][voicekey]sidechaincompress=threshold=0.015:ratio=8:attack=15:release=500[ducked]';
            $filters[] = '[ducked][voicemix]amix=inputs=2:normalize=0:duration=first,volume='.self::FINAL_GAIN.',alimiter=limit=0.9:level=false[out]';
        }

        return [
            $this->ffmpeg, '-v', 'error', '-y',
            ...$inputs,
            '-filter_complex', implode(';', $filters),
            '-map', '0:v', '-map', '[out]',
            '-c:v', 'copy', '-c:a', 'aac', '-b:a', '160k',
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

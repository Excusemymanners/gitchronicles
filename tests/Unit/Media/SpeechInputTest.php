<?php

namespace Tests\Unit\Media;

use App\Media\Speech\KokoroVoice;
use App\Media\Speech\PiperVoice;
use App\Media\Speech\SpokenInput;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * The text given to a speech engine comes from a repository: it goes on the standard input (never on the command
 * line, so "--model /etc/passwd" in a sentence is only words), is cut to a sane length, and the engine runs
 * without the application's keys and passwords.
 */
class SpeechInputTest extends TestCase
{
    public function test_a_sentence_is_cut_and_loses_its_control_characters(): void
    {
        $text = "Hello\x00 world\x1b[31m".str_repeat('a', 5000);

        $limited = SpokenInput::limit($text);

        $this->assertSame(SpokenInput::MAX_CHARACTERS, mb_strlen($limited));
        $this->assertStringNotContainsString("\x00", $limited);
        $this->assertStringNotContainsString("\x1b", $limited);
        $this->assertStringStartsWith('Hello  world', $limited);
    }

    public function test_the_text_never_reaches_the_command_line_and_the_engine_does_not_see_the_keys_of_the_application(): void
    {
        $_ENV['APP_KEY'] = 'base64:secret';
        $_ENV['DB_PASSWORD'] = 'hunter2';
        $directory = sys_get_temp_dir().'/gc-voice-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($directory);
        $python = "{$directory}/python";
        File::put($python, '');
        $hostile = '--model /etc/passwd; $(touch /tmp/gc-pwned)';
        Process::fake(function (PendingProcess $process) {
            if (is_string($process->input) && str_contains($process->input, '"path"')) {
                foreach (json_decode($process->input, true) as $clip) {
                    File::put($clip['path'], 'RIFF');
                }
            }

            $output = array_search('--output-file', $process->command, true);

            if ($output !== false) {
                File::put($process->command[$output + 1], 'RIFF');
            }

            return Process::result();
        });

        try {
            (new KokoroVoice($python, "{$directory}/speak.py", "{$directory}/m", "{$directory}/v", 'am_michael'))
                ->speak(["{$directory}/a.wav" => $hostile]);
            (new PiperVoice($python, "{$directory}/p.onnx"))->speak(["{$directory}/b.wav" => $hostile]);
        } finally {
            unset($_ENV['APP_KEY'], $_ENV['DB_PASSWORD']);
            File::deleteDirectory($directory);
        }

        Process::assertRanTimes(fn (PendingProcess $process): bool => ! str_contains(implode(' ', $process->command), 'passwd')
            && $process->environment['APP_KEY'] === false
            && $process->environment['DB_PASSWORD'] === false
            && str_contains((string) $process->input, 'passwd'), 2);
    }
}

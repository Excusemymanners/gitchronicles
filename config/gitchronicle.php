<?php

return [

    /*
    |--------------------------------------------------------------------------
    | AI driver
    |--------------------------------------------------------------------------
    |
    | Who writes the AI texts (documentation, chat answers):
    |
    | - "claude-code": the Claude Code CLI on this computer, logged in with a Claude
    |   subscription (no API credits are spent);
    | - "claude": the Anthropic API through the official PHP SDK (needs ANTHROPIC_API_KEY);
    | - "none": no AI at all; every feature falls back to its offline mode.
    |
    | Tests always run with "none" (phpunit.xml).
    |
    */

    'ai_driver' => env('AI_DRIVER', 'none'),

    /*
    |--------------------------------------------------------------------------
    | Content language
    |--------------------------------------------------------------------------
    |
    | The language of everything generated for a repository (documentation, diagram labels and
    | flows, assistant answers), independent of the interface language. Only "en" is supported
    | for now; see App\Support\ContentLanguage for how a second language would be added.
    |
    */

    'content_language' => env('CONTENT_LANGUAGE', 'en'),

    /*
    | The Claude Code executable used by the "claude-code" driver.
    */

    'claude_code_bin' => env('CLAUDE_CODE_BIN', 'claude'),

    /*
    |--------------------------------------------------------------------------
    | Claude model
    |--------------------------------------------------------------------------
    |
    | Used by both Claude drivers. "effort" sets how deeply the model thinks
    | (low, medium, high, xhigh, max); max_tokens caps one answer of the API driver.
    |
    */

    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ANTHROPIC_MODEL', 'claude-opus-5-5'),
        'effort' => env('ANTHROPIC_EFFORT', 'medium'),
        'max_tokens' => 16000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Generated documentation (Faza 6)
    |--------------------------------------------------------------------------
    |
    | module_context_characters: how much code (in characters) one module page may send
    | to the writer; the most important files go first and the omitted ones are listed.
    | max_module_pages: at most this many module pages per repository (the biggest modules);
    | DOCS_MAX_MODULE_PAGES lowers it locally, where each page is one Claude call on the subscription.
    |
    */

    'docs' => [
        'module_context_characters' => 60000,
        'max_module_pages' => (int) env('DOCS_MAX_MODULE_PAGES', 40),
    ],

    /*
    |--------------------------------------------------------------------------
    | Incremental update (Faza 9)
    |--------------------------------------------------------------------------
    |
    | full_threshold: when more than this share of the tracked files changed since the last
    | processed commit (0.40 = 40%), the run processes everything again instead of only the changes.
    | overview_share: the overview and the generated README are written again when at least this
    | share of the modules has changed files (they are also written again when the list of modules
    | or a file of the root folder changes).
    | check_cron: when `repositories:check-updates` runs by itself (a cron expression, by default
    | every 15 minutes); an empty value turns the scheduled check off.
    | min_interval_minutes: an update started by the webhook or by the scheduled check waits until this
    | many minutes passed since the repository's last run that did some work, so a burst of pushes
    | cannot spend AI calls again and again (0 turns the limit off). A commit pushed in between is
    | picked up by the next scheduled check, or by the next push after the interval.
    |
    */

    'incremental' => [
        'full_threshold' => (float) env('INCREMENTAL_FULL_THRESHOLD', 0.40),
        'overview_share' => (float) env('INCREMENTAL_OVERVIEW_SHARE', 0.25),
        'check_cron' => env('UPDATES_CHECK_CRON', '*/15 * * * *'),
        'min_interval_minutes' => (int) env('UPDATES_MIN_INTERVAL_MINUTES', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Presentation video and slides (bonus)
    |--------------------------------------------------------------------------
    |
    | Made on the "media" queue after a repository is ready (GenerateMedia), with the Node tools of
    | renderers/ (composer media:install): HyperFrames for the video, Marp for the slides, Piper
    | for the voices. Chrome and FFmpeg come from this computer (paths below).
    |
    | - auto_generate: start the media after every processing run (tests: false, phpunit.xml);
    | - hyperframes_home: where HyperFrames and Chrome keep their cache and settings (it becomes
    |   HOME for them), so nothing is written to the home folder of the user running the worker;
    | - workers: how many Chrome pages capture the frames in parallel;
    | - languages: the languages the video is narrated in ("en", "ro"); the first is the default
    |   shown on the page. Each one needs its Piper voice model (voice.models);
    | - max_seconds: the longest video; when the narration is longer, the last features are left out;
    | - capture: the photo of the repository's real GitHub page that the video scrolls through
    |   (renderers/capture/github.mjs). Off, or when GitHub cannot be reached, the video falls back
    |   to typographic scenes. max_height is how much of the page is photographed, in CSS pixels;
    | - voice: who reads the video, one line per language, "engine:voice" (App\Media\Narrator):
    |   piper:<model>, kokoro:<voice> (English, offline), edge:<voice> (online, unofficial — optional) or
    |   chatterbox:<exaggeration> (English, GPU, optional; chatterbox.python is the Python of its own environment).
    |   Piper with the language's own model (models, length_scale below 1 speaks faster) is the
    |   fallback when the chosen engine is missing or fails. renderers/tts/install.sh installs them.
    |   The speeds (Kokoro speed, Edge rate, Piper length_scale) are chosen so the narration runs at
    |   about 150-170 words per minute (measured on the same passage, 2026-11-12): natural, never rushed;
    | - video: how the final picture is encoded (AudioMixer). "auto" picks libx264 when FFmpeg has it,
    |   else NVENC on an NVIDIA GPU, else keeps HyperFrames' file; quality is the CRF / CQ (lower = sharper
    |   and larger; 20 is visually lossless for the page's text).
    |
    */

    'media' => [
        'auto_generate' => (bool) env('MEDIA_AUTO_GENERATE', true),
        'queue' => env('MEDIA_QUEUE', 'media'),
        'renderers_path' => base_path('renderers'),
        'node_bin' => env('MEDIA_NODE_BIN', 'node'),
        'browser_path' => env('MEDIA_BROWSER_PATH', '/usr/bin/google-chrome'),
        'ffmpeg_path' => env('MEDIA_FFMPEG_PATH', '/usr/bin/ffmpeg'),
        'ffprobe_path' => env('MEDIA_FFPROBE_PATH', '/usr/bin/ffprobe'),
        'hyperframes_home' => env('MEDIA_HYPERFRAMES_HOME', storage_path('app/hyperframes')),
        'workers' => (int) env('MEDIA_RENDER_WORKERS', 4),
        'languages' => array_values(array_filter(array_map('trim', explode(',', (string) env('MEDIA_LANGUAGES', 'en,ro'))))),
        'max_seconds' => (float) env('MEDIA_MAX_SECONDS', 60),
        'music' => (bool) env('MEDIA_MUSIC', false),
        'capture' => [
            'enabled' => (bool) env('MEDIA_CAPTURE', true),
            'max_height' => (int) env('MEDIA_CAPTURE_MAX_HEIGHT', 20000),
            'timeout' => (int) env('MEDIA_CAPTURE_TIMEOUT', 100),
        ],
        'voice' => [
            'engines' => [
                'en' => env('MEDIA_VOICE_EN', 'kokoro:am_fenrir'),
                'ro' => env('MEDIA_VOICE_RO', 'edge:ro-RO-EmilNeural'),
            ],
            'python' => env('MEDIA_PIPER_PYTHON', base_path('renderers/tts/.venv/bin/python')),
            'script' => base_path('renderers/tts/speak.py'),
            'voices_path' => base_path('renderers/tts/voices'),
            'models' => [
                'ro' => env('MEDIA_PIPER_MODEL', base_path('renderers/tts/voices/ro_RO-mihai-medium.onnx')),
                'en' => env('MEDIA_PIPER_MODEL_EN', base_path('renderers/tts/voices/en_US-joe-medium.onnx')),
            ],
            'length_scale' => [
                'ro' => (float) env('MEDIA_VOICE_LENGTH_SCALE', 1.13),
                'en' => (float) env('MEDIA_VOICE_LENGTH_SCALE_EN', 0.92),
            ],
            'kokoro' => [
                'model' => base_path('renderers/tts/voices/kokoro-v1.0.onnx'),
                'voices' => base_path('renderers/tts/voices/voices-v1.0.bin'),
                'speed' => (float) env('MEDIA_KOKORO_SPEED', 0.85),
            ],
            'chatterbox' => [
                'python' => env('MEDIA_CHATTERBOX_PYTHON', ''),
            ],
            'edge' => [
                'rate' => env('MEDIA_EDGE_RATE', '-14%'),
            ],
        ],
        'video' => [
            'encoder' => env('MEDIA_VIDEO_ENCODER', 'auto'),
            'quality' => (int) env('MEDIA_VIDEO_QUALITY', 20),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | „Consultanță”: bugs, vulnerabilities and code statistics (bonus)
    |--------------------------------------------------------------------------
    |
    | Made after a repository is ready (GenerateConsultingReport) by three tools installed in scanners/
    | (composer consulting:install): Semgrep with the rules of scanners/rules, OSV-Scanner and scc.
    |
    | - auto_run: analyze after every processing run (tests: false, phpunit.xml);
    | - queue: the "media" queue by default, so the scan and the video share one worker: both are slow,
    |   optional work after "ready", and one heavy job at a time keeps a laptop responsive;
    | - home: HOME and the cache folders of the tools, so nothing is written to the worker's home folder;
    | - timeouts (seconds), max_output_bytes and max_findings (per tool): a hostile repository cannot keep
    |   a worker busy forever, fill its memory or flood the database; what the cap leaves out is counted.
    |
    */

    'consulting' => [
        'auto_run' => (bool) env('CONSULTING_AUTO_RUN', true),
        'queue' => env('CONSULTING_QUEUE', 'media'),
        'semgrep_path' => env('CONSULTING_SEMGREP_PATH', base_path('scanners/.venv/bin/semgrep')),
        'osv_scanner_path' => env('CONSULTING_OSV_SCANNER_PATH', base_path('scanners/bin/osv-scanner')),
        'scc_path' => env('CONSULTING_SCC_PATH', base_path('scanners/bin/scc')),
        'rules_path' => base_path('scanners/rules'),
        'home' => env('CONSULTING_HOME', storage_path('app/scanners')),
        'timeouts' => [
            'semgrep' => (int) env('CONSULTING_SEMGREP_TIMEOUT', 300),
            'osv-scanner' => 120,
            'scc' => 60,
        ],
        'max_output_bytes' => 50 * 1024 * 1024,
        'max_findings' => 500,
    ],

    /*
    |--------------------------------------------------------------------------
    | „Descoperă” leaderboard (bonus)
    |--------------------------------------------------------------------------
    |
    | `php artisan discover:refresh` asks the GitHub Search API for interesting repositories and
    | writes down today's stars of every watched repository (App\Discover\StarCollector).
    |
    | - schedule_enabled / schedule_time: the daily run of the Laravel Scheduler (routes/console.php);
    | - languages: one extra search per language (new repositories of that language);
    | - per_page / max_pages: how many results one search asks for, and on how many pages;
    | - refresh_limit: at most this many watched repositories that no search returned today are asked
    |   for one by one (GitHub allows 60 such requests per hour without a token, 5000 with one).
    |
    | The optional GitHub token is in config/services.php (GITHUB_TOKEN).
    |
    */

    'discover' => [
        'schedule_enabled' => (bool) env('DISCOVER_SCHEDULE_ENABLED', true),
        'schedule_time' => env('DISCOVER_SCHEDULE_TIME', '03:00'),
        'languages' => ['TypeScript', 'Python', 'Rust', 'Go', 'PHP', 'Java'],
        'per_page' => 50,
        'max_pages' => 2,
        'refresh_limit' => (int) env('DISCOVER_REFRESH_LIMIT', 40),
    ],

];

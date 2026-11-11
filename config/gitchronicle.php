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
    | for the Romanian voice. Chrome and FFmpeg come from this computer (paths below).
    |
    | - auto_generate: start the media after every processing run (tests: false, phpunit.xml);
    | - hyperframes_home: where HyperFrames and Chrome keep their cache and settings (it becomes
    |   HOME for them), so nothing is written to the home folder of the user running the worker;
    | - workers: how many Chrome pages capture the frames in parallel;
    | - voice: the Piper model and its speed (length_scale below 1 speaks faster).
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
        'voice' => [
            'python' => env('MEDIA_PIPER_PYTHON', base_path('renderers/tts/.venv/bin/python')),
            'model' => env('MEDIA_PIPER_MODEL', base_path('renderers/tts/voices/ro_RO-mihai-medium.onnx')),
            'length_scale' => (float) env('MEDIA_VOICE_LENGTH_SCALE', 0.85),
        ],
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

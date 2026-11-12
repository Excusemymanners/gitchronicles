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
    | - runtime and framework versions (App\Consulting\Runtime): support_data are the public sources compared with
    |   (endoflife.date, Node.js's release index, php.net), kept cache_hours; only a product name is ever sent;
    | - stale_after_days: a report older than this shows a notice, and `repositories:consult --stale` makes it
    |   again (daily at stale_schedule_time); rules_update_schedule turns the weekly refresh of the Semgrep
    |   rules (scanners/update-rules.sh) on or off; rules_version_file is where that script records the date;
    | - timeouts (seconds), max_output_bytes and max_findings (per tool): a hostile repository cannot keep
    |   a worker busy forever, fill its memory or flood the database; what the cap leaves out is counted.
    |   An answer past max_output_bytes (CONSULTING_MAX_OUTPUT_MB, 8 MB) is never decoded: the tool is stopped
    |   and the report shows it as not finished ("output_too_large"), while the other tools still count.
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
        // json_decode needs several times the size of a JSON text in memory, so the limit is far below PHP's
        // 128 MB (Semgrep has no option that caps the number of findings, so the size is capped here).
        'max_output_bytes' => (int) env('CONSULTING_MAX_OUTPUT_MB', 8) * 1024 * 1024,
        'max_findings' => 500,
        'stale_after_days' => (int) env('CONSULTING_STALE_AFTER_DAYS', 7),
        'rules_update_schedule' => (bool) env('CONSULTING_RULES_UPDATE_SCHEDULE', true),
        'stale_schedule_time' => env('CONSULTING_STALE_SCHEDULE_TIME', '05:00'),
        'rules_version_file' => base_path('scanners/rules-version.json'),
        'support_data' => [
            'endoflife_url' => 'https://endoflife.date/api/v1/products',
            'node_index_url' => 'https://nodejs.org/dist/index.json',
            'php_releases_url' => 'https://www.php.net/releases/index.php',
            'cache_hours' => 24,
            'timeout' => 10,
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

    /*
    |--------------------------------------------------------------------------
    | Abuse and cost limits
    |--------------------------------------------------------------------------
    |
    | The site is public, so every action that starts a clone or a Claude call has a limit for one
    | visitor AND a limit for the whole site: many addresses or throwaway accounts cannot add up to more.
    |
    | - ai_daily_calls: Claude calls per day for the whole site, all features together
    |   (App\Ai\BudgetedTextGenerator); over it, every feature uses its offline mode until the next day;
    | - repositories: adding a repository, „Reîncearcă” and the other actions that start a clone, per user
    |   (per IP for guests) per minute and for all logged-in users together per hour; per_user_per_day is how
    |   many new repositories one account may add in 24 hours (App\Actions\AddRepository);
    | - assistant: questions per IP per hour, and for the whole site per minute and per day; ai_timeout is
    |   the longest one AI answer may keep a web request waiting (documentation pages, in the queue, may take
    |   300 s); ai_slots is how many AI answers may be written at the same time (the other questions get the
    |   code fragments without AI, so the web workers are never all waiting for Claude).
    |
    */

    'limits' => [
        'login' => [
            'per_ip_per_minute' => (int) env('LOGIN_PER_IP_PER_MINUTE', 10),
        ],

        // 0 = no cap: the default while APP_ENV is local (the owner tests with many full regenerations), 200 elsewhere.
        'ai_daily_calls' => (int) env('AI_DAILY_CALLS', env('APP_ENV', 'production') === 'local' ? 0 : 200),

        'repositories' => [
            'per_user_per_minute' => (int) env('REPOSITORIES_PER_MINUTE', 10),
            'global_per_hour' => (int) env('REPOSITORIES_GLOBAL_PER_HOUR', 30),
            'per_user_per_day' => (int) env('REPOSITORIES_PER_USER_PER_DAY', 5),
        ],

        'assistant' => [
            'per_ip_per_hour' => (int) env('ASSISTANT_PER_IP_PER_HOUR', 20),
            'global_per_minute' => (int) env('ASSISTANT_GLOBAL_PER_MINUTE', 6),
            'global_per_day' => (int) env('ASSISTANT_GLOBAL_PER_DAY', 300),
            'ai_timeout' => (int) env('ASSISTANT_AI_TIMEOUT', 60),
            'ai_slots' => (int) env('ASSISTANT_AI_SLOTS', 2),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Accounts
    |--------------------------------------------------------------------------
    |
    | - min_password_length: the shortest password a new account may have (it must also have a letter and a
    |   number; in production it must not be in a known data leak);
    | - registration_open: whether anyone may create an account (REGISTRATION_OPEN). Without email verification
    |   a visitor can find out whether an address has an account (the registration says it is taken), so a
    |   public deployment may prefer to close registration and create accounts by hand. false answers 404.
    |
    */

    'auth' => [
        'min_password_length' => (int) env('MIN_PASSWORD_LENGTH', 10),
        'registration_open' => (bool) env('REGISTRATION_OPEN', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Proxies, hosts and HTTPS
    |--------------------------------------------------------------------------
    |
    | Nothing is trusted by default: the address of a visitor is the one that reaches PHP.
    | Behind a reverse proxy, a CDN or a Kubernetes ingress name it here, never "*", and make it overwrite
    | X-Forwarded-For; otherwise every visitor looks like the proxy and the limits above count them together.
    |
    | - trusted_proxies: comma separated addresses or CIDR ranges of the proxies (TRUSTED_PROXIES);
    | - trusted_hosts: comma separated host names the app answers to (TRUSTED_HOSTS); empty = any host.
    |   Requests with another Host header get a 404;
    | - hsts_seconds: Strict-Transport-Security max-age, sent only on HTTPS requests; 0 turns it off.
    |
    */

    'http' => [
        'trusted_proxies' => array_values(array_filter(array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', ''))))),
        'trusted_hosts' => array_values(array_filter(array_map('trim', explode(',', (string) env('TRUSTED_HOSTS', ''))))),
        'hsts_seconds' => (int) env('HSTS_SECONDS', 31536000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Hostile repositories (git and the clone)
    |--------------------------------------------------------------------------
    |
    | Every repository is written by a stranger. These limits keep a small one from filling the disk
    | or stopping the worker; a repository over a limit fails with a clear message (repositories.errors).
    |
    | - protocols: the only git protocols that may be used (comma separated); tests add "file" to clone
    |   a local repository;
    | - max_files, max_bytes: checked on the file list of the newest commit BEFORE the files are written
    |   to disk (git ls-tree), and again on the real folder after the checkout;
    | - max_disk_bytes: the most one clone folder (history included) may use; watched while git downloads;
    | - max_path_bytes: a longer file path is ignored;
    | - storage_budget_bytes: the most that all clones together may use; 0 = no limit;
    | - clone_seconds: git is stopped after this long.
    |
    */

    'git' => [
        'protocols' => env('GIT_PROTOCOLS', 'https'),
        'max_files' => (int) env('REPO_MAX_FILES', 20000),
        'max_bytes' => (int) env('REPO_MAX_BYTES', 300 * 1024 * 1024),
        'max_disk_bytes' => (int) env('REPO_MAX_DISK_BYTES', 800 * 1024 * 1024),
        'max_path_bytes' => (int) env('REPO_MAX_PATH_BYTES', 1024),
        'storage_budget_bytes' => (int) env('REPO_STORAGE_BUDGET_BYTES', 20 * 1024 * 1024 * 1024),
        'clone_seconds' => (int) env('REPO_CLONE_SECONDS', 240),
    ],

    /*
    |--------------------------------------------------------------------------
    | Limits of the code analysis
    |--------------------------------------------------------------------------
    |
    | A hostile repository can be tiny and still ask for a lot: millions of symbols, thousands of files,
    | a file that makes the parser run for minutes. When a limit is passed, the repository is still analyzed
    | with what fits, and the run says what was left out (processing_runs.change_summary.left_out).
    |
    | - max_files: files analyzed (the rest are left out, counted);
    | - max_symbols_per_file, max_imports_per_file, max_symbols_total: kept per file and per repository;
    | - seconds_per_file, seconds_total: time limits of the analyzer (analyzer/analyze.py); the PHP process
    |   timeout (RepositoryAnalyzer::TIMEOUT_SECONDS) is longer, so the analyzer ends first and cleanly;
    | - max_file_bytes: bigger files are not read (RepositoryAnalyzer::MAX_FILE_BYTES is the default).
    |
    */

    'analysis' => [
        'max_files' => (int) env('ANALYSIS_MAX_FILES', 20000),
        'max_symbols_per_file' => (int) env('ANALYSIS_MAX_SYMBOLS_PER_FILE', 2000),
        'max_imports_per_file' => (int) env('ANALYSIS_MAX_IMPORTS_PER_FILE', 500),
        'max_symbols_total' => (int) env('ANALYSIS_MAX_SYMBOLS_TOTAL', 60000),
        'seconds_per_file' => (float) env('ANALYSIS_SECONDS_PER_FILE', 10),
        'seconds_total' => (float) env('ANALYSIS_SECONDS_TOTAL', 150),
    ],

    /*
    |--------------------------------------------------------------------------
    | Limits of the git history import
    |--------------------------------------------------------------------------
    |
    | - max_files_per_commit: the paths kept for one commit (its counters still count every file);
    | - max_log_bytes: the most `git log --numstat` may print; the newest commits that fit are kept.
    |
    */

    'history' => [
        'max_files_per_commit' => (int) env('HISTORY_MAX_FILES_PER_COMMIT', 300),
        'max_log_bytes' => (int) env('HISTORY_MAX_LOG_BYTES', 32 * 1024 * 1024),
    ],

    /*
    |--------------------------------------------------------------------------
    | The code index of the assistant
    |--------------------------------------------------------------------------
    |
    | - max_chunks: the most pieces of code (code_chunks rows) kept for one repository; the files that do not
    |   fit are left out and counted (CodeIndexer); 0 = no limit.
    |
    */

    'search' => [
        'max_chunks' => (int) env('SEARCH_MAX_CHUNKS', 100000),
    ],

];

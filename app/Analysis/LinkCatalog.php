<?php

namespace App\Analysis;

use App\Diagrams\TechnologyCatalog;
use App\Enums\TechnologyRole;

/**
 * What LinkScanner recognizes outside the code, and the plain name of each target it stores:
 * - "tool:<program>": a program the code starts (git, ffmpeg, the Claude CLI, a browser, a scanner, ...);
 * - "service:<key>": a system called over the network, from a URL host in the code or an SDK it imports
 *   (the key is a TechnologyCatalog key when the catalog knows it, otherwise the host itself);
 * - "store:<kind>": where the data is kept or the work waits: database, cache, queue, files.
 * Only what is listed here is recognized, so a diagram never shows a guess.
 */
final class LinkCatalog
{
    /**
     * Programs a project starts, by the name of their executable => the name a newcomer knows.
     * Interpreters (python, node, php, ...) are not here: what they run is the script after them.
     *
     * @var array<string, string>
     */
    public const TOOLS = [
        'git' => 'Git',
        'ffmpeg' => 'FFmpeg',
        'ffprobe' => 'FFmpeg',
        'claude' => 'Claude Code CLI',
        'google-chrome' => 'Chrome (headless)',
        'google-chrome-stable' => 'Chrome (headless)',
        'chromium' => 'Chrome (headless)',
        'chromium-browser' => 'Chrome (headless)',
        'chrome' => 'Chrome (headless)',
        'piper' => 'Piper speech',
        'edge-tts' => 'Edge TTS',
        'espeak' => 'eSpeak',
        'espeak-ng' => 'eSpeak',
        'semgrep' => 'Semgrep',
        'gitleaks' => 'Gitleaks',
        'osv-scanner' => 'OSV-Scanner',
        'trivy' => 'Trivy',
        'docker' => 'Docker',
        'kubectl' => 'kubectl',
        'helm' => 'Helm',
        'curl' => 'curl',
        'wget' => 'wget',
        'ssh' => 'SSH',
        'rsync' => 'rsync',
        'pandoc' => 'Pandoc',
        'magick' => 'ImageMagick',
        'convert' => 'ImageMagick',
        'tesseract' => 'Tesseract',
        'ocrmypdf' => 'OCRmyPDF',
        'soffice' => 'LibreOffice',
        'libreoffice' => 'LibreOffice',
        'yt-dlp' => 'yt-dlp',
        'ollama' => 'Ollama',
        'systemctl' => 'systemd',
        'xdotool' => 'xdotool',
    ];

    /**
     * Executables that are another name of the same tool => the name stored.
     *
     * @var array<string, string>
     */
    public const SAME_TOOL = [
        'google-chrome' => 'chrome', 'google-chrome-stable' => 'chrome', 'chromium' => 'chrome', 'chromium-browser' => 'chrome',
        'ffprobe' => 'ffmpeg', 'espeak-ng' => 'espeak', 'convert' => 'magick', 'libreoffice' => 'soffice',
    ];

    /**
     * Interpreters: they are not shown as tools, but the script path after them is a "runs" link.
     */
    public const INTERPRETERS = ['python', 'python3', 'node', 'php', 'bash', 'sh', 'zsh', 'ruby', 'perl', 'deno', 'bun', 'npx', 'tsx'];

    /**
     * URL host (or the end of it) => the service, as a TechnologyCatalog key or a plain name.
     *
     * @var array<string, string>
     */
    public const HOSTS = [
        'api.github.com' => 'github',
        'github.com' => 'github',
        'raw.githubusercontent.com' => 'github',
        'githubusercontent.com' => 'github',
        'codeload.github.com' => 'github',
        'api.anthropic.com' => 'anthropic',
        'api.openai.com' => 'openai',
        'api.stripe.com' => 'stripe',
        'amazonaws.com' => 'aws',
        'sentry.io' => 'sentry',
        'api.twilio.com' => 'twilio',
        'api.resend.com' => 'resend',
        'firebaseio.com' => 'firebase',
        'googleapis.com' => 'Google APIs',
        'api.osv.dev' => 'OSV database',
        'osv.dev' => 'OSV database',
        'nodejs.org' => 'Node.js downloads',
        'iojs.org' => 'Node.js downloads',
        'registry.npmjs.org' => 'npm registry',
        'pypi.org' => 'PyPI',
        'huggingface.co' => 'Hugging Face',
    ];

    /**
     * Hosts that are never a service the code calls (examples, specifications, the local machine).
     */
    public const IGNORED_HOSTS = ['localhost', '127.0.0.1', '0.0.0.0', 'example.com', 'example.org', 'www.w3.org', 'w3.org', 'schema.org', 'json-schema.org', 'xmlns.com', 'purl.org', 'opensource.org', 'creativecommons.org', 'semver.org', 'www.apache.org', 'spdx.org', 'img.shields.io', 'shields.io', 'badge.fury.io'];

    /**
     * Libraries that keep data, by the start of the import => store kind (JavaScript, Python, Rust, Go).
     *
     * @var array<string, string>
     */
    public const STORE_LIBRARIES = [
        'pg' => 'database', 'mysql' => 'database', 'mysql2' => 'database', 'mongoose' => 'database', 'mongodb' => 'database',
        '@prisma/client' => 'database', 'drizzle-orm' => 'database', 'better-sqlite3' => 'database', 'sqlite3' => 'database',
        'knex' => 'database', 'typeorm' => 'database', 'sequelize' => 'database', 'sqlalchemy' => 'database',
        'psycopg' => 'database', 'psycopg2' => 'database', 'pymongo' => 'database', 'django.db' => 'database',
        'sqlx' => 'database', 'rusqlite' => 'database', 'diesel' => 'database', 'database/sql' => 'database',
        'gorm.io/gorm' => 'database', 'redis' => 'cache', 'ioredis' => 'cache', 'github.com/redis/go-redis' => 'cache',
        'bullmq' => 'queue', 'bull' => 'queue', 'amqplib' => 'queue', 'celery' => 'queue', 'kafkajs' => 'queue',
    ];

    /**
     * PHP namespaces of service SDKs => TechnologyCatalog key.
     *
     * @var array<string, string>
     */
    public const PHP_NAMESPACES = [
        'Anthropic\\' => 'anthropic',
        'OpenAI\\' => 'openai',
        'Stripe\\' => 'stripe',
        'Aws\\' => 'aws',
        'Github\\' => 'github',
        'Sentry\\' => 'sentry',
        'Pusher\\' => 'pusher',
        'Twilio\\' => 'twilio',
        'Resend\\' => 'resend',
    ];

    /**
     * The plain name of the stores.
     *
     * @var array<string, string>
     */
    public const STORES = [
        'database' => 'Database',
        'cache' => 'Cache',
        'queue' => 'Job queue',
        'files' => 'File storage',
    ];

    /**
     * The tool started by a command word ("/usr/bin/ffmpeg" and "ffmpeg -i" are FFmpeg), or null.
     */
    public static function tool(string $command): ?string
    {
        $program = basename(strtok(trim($command), " \t") ?: '');

        return isset(self::TOOLS[$program]) ? (self::SAME_TOOL[$program] ?? $program) : null;
    }

    /**
     * Whether the command word is an interpreter (its next argument is the script it runs).
     */
    public static function isInterpreter(string $command): bool
    {
        return in_array(preg_replace('/[\d.]+$/', '', basename(trim($command))), self::INTERPRETERS, true);
    }

    /**
     * The service key of a URL host ("api.github.com" → "github"), the host itself for one we do not
     * know, or null for a host that is never a service (localhost, example.com, specifications).
     */
    public static function service(string $host): ?string
    {
        $host = strtolower(rtrim($host, '.'));

        if (in_array($host, self::IGNORED_HOSTS, true) || ! str_contains($host, '.') || preg_match('/\A[\d.]+\z|(^|\.)(example|test|invalid|local|localhost)(\.|$)/', $host) === 1) {
            return null;
        }

        foreach (self::HOSTS as $known => $service) {
            if ($host === $known || str_ends_with($host, ".{$known}")) {
                return $service;
            }
        }

        return $host;
    }

    /**
     * The service key of an imported package (an SDK), from the technology catalog, or null.
     */
    public static function sdk(string $import, string $language): ?string
    {
        $key = match ($language) {
            'PHP' => self::phpNamespace($import),
            'JavaScript', 'TypeScript' => TechnologyCatalog::npmPackage(self::packageName($import)),
            'Python' => TechnologyCatalog::PYTHON_PACKAGES[strtolower(strtok($import, '.') ?: '')] ?? null,
            'Go' => TechnologyCatalog::goModule($import),
            default => null,
        };

        if ($key === null || $key === 'github' || $key === 'github-api') {
            return $key === null ? null : 'github';
        }

        return (TechnologyCatalog::TECHNOLOGIES[$key][1] ?? null) === TechnologyRole::External ? $key : null;
    }

    /**
     * The store kind of an imported library, or null.
     */
    public static function storeLibrary(string $import): ?string
    {
        foreach (self::STORE_LIBRARIES as $library => $store) {
            if ($import === $library || str_starts_with($import, "{$library}/") || str_starts_with($import, "{$library}.") || str_starts_with($import, "{$library}::")) {
                return $store;
            }
        }

        return null;
    }

    /**
     * The plain name of a stored target: "tool:git" → "Git", "service:github" → "GitHub",
     * "service:nodejs.org" → "nodejs.org", "store:queue" → "Job queue".
     */
    public static function name(string $target): string
    {
        [$type, $key] = explode(':', $target, 2) + [1 => ''];

        return match ($type) {
            'tool' => self::TOOLS[$key] ?? $key,
            'store' => self::STORES[$key] ?? ucfirst($key),
            default => match (true) {
                $key === 'github' => 'GitHub',
                isset(TechnologyCatalog::TECHNOLOGIES[$key]) => TechnologyCatalog::TECHNOLOGIES[$key][0],
                default => $key,
            },
        };
    }

    /**
     * The npm package of an import ("@scope/name/sub" → "@scope/name", "name/sub" → "name").
     */
    private static function packageName(string $import): string
    {
        $parts = explode('/', $import);

        return str_starts_with($import, '@') ? implode('/', array_slice($parts, 0, 2)) : $parts[0];
    }

    /**
     * The service key of a PHP class name from an SDK namespace, or null.
     */
    private static function phpNamespace(string $className): ?string
    {
        foreach (self::PHP_NAMESPACES as $namespace => $key) {
            if (str_starts_with(ltrim($className, '\\'), $namespace)) {
                return $key;
            }
        }

        return null;
    }
}

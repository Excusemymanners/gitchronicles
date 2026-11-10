<?php

namespace App\Diagrams;

use App\Enums\TechnologyRole;

/**
 * The technologies GitChronicle recognizes and where it recognizes them from: a package name in
 * composer.json, package.json, pyproject.toml / requirements.txt or go.mod, a driver in .env.example,
 * or an image in docker-compose / Kubernetes files. Only what is listed here can appear in a diagram,
 * so a diagram never shows a technology the repository does not really use.
 *
 * The icons are names from the Iconify "logos" pack (CC0); the page bundles exactly these icons
 * (resources/js/lib/diagram-icons.json, checked by a test). null = no logo in the pack.
 */
final class TechnologyCatalog
{
    /**
     * key => [name, role, icon].
     *
     * @var array<string, array{string, TechnologyRole, string|null}>
     */
    public const TECHNOLOGIES = [
        // Languages
        'php' => ['PHP', TechnologyRole::Language, 'logos:php'],
        'nodejs' => ['Node.js', TechnologyRole::Language, 'logos:nodejs-icon'],
        'typescript' => ['TypeScript', TechnologyRole::Language, 'logos:typescript-icon'],
        'javascript' => ['JavaScript', TechnologyRole::Language, 'logos:javascript'],
        'python' => ['Python', TechnologyRole::Language, 'logos:python'],
        'go' => ['Go', TechnologyRole::Language, 'logos:go'],

        // Web frameworks on the server
        'laravel' => ['Laravel', TechnologyRole::Backend, 'logos:laravel'],
        'symfony' => ['Symfony', TechnologyRole::Backend, 'logos:symfony'],
        'slim' => ['Slim', TechnologyRole::Backend, null],
        'cakephp' => ['CakePHP', TechnologyRole::Backend, 'logos:cakephp-icon'],
        'express' => ['Express', TechnologyRole::Backend, 'logos:express'],
        'fastify' => ['Fastify', TechnologyRole::Backend, 'logos:fastify-icon'],
        'nestjs' => ['NestJS', TechnologyRole::Backend, 'logos:nestjs'],
        'koa' => ['Koa', TechnologyRole::Backend, 'logos:koa'],
        'hono' => ['Hono', TechnologyRole::Backend, 'logos:hono'],
        'django' => ['Django', TechnologyRole::Backend, 'logos:django-icon'],
        'flask' => ['Flask', TechnologyRole::Backend, 'logos:flask'],
        'fastapi' => ['FastAPI', TechnologyRole::Backend, 'logos:fastapi-icon'],
        'gin' => ['Gin', TechnologyRole::Backend, 'logos:gin'],
        'echo' => ['Echo', TechnologyRole::Backend, null],
        'fiber' => ['Fiber', TechnologyRole::Backend, null],
        'chi' => ['chi', TechnologyRole::Backend, null],

        // Server and pages in one framework
        'nextjs' => ['Next.js', TechnologyRole::Fullstack, 'logos:nextjs-icon'],
        'nuxt' => ['Nuxt', TechnologyRole::Fullstack, 'logos:nuxt-icon'],
        'sveltekit' => ['SvelteKit', TechnologyRole::Fullstack, 'logos:svelte-icon'],
        'astro' => ['Astro', TechnologyRole::Fullstack, 'logos:astro-icon'],
        'remix' => ['Remix', TechnologyRole::Fullstack, 'logos:remix-icon'],

        // Pages in the browser
        'react' => ['React', TechnologyRole::Ui, 'logos:react'],
        'vue' => ['Vue.js', TechnologyRole::Ui, 'logos:vue'],
        'svelte' => ['Svelte', TechnologyRole::Ui, 'logos:svelte-icon'],
        'angular' => ['Angular', TechnologyRole::Ui, 'logos:angular-icon'],
        'solid' => ['SolidJS', TechnologyRole::Ui, 'logos:solidjs-icon'],
        'preact' => ['Preact', TechnologyRole::Ui, 'logos:preact'],
        'electron' => ['Electron', TechnologyRole::Ui, 'logos:electron'],
        'inertia' => ['Inertia', TechnologyRole::Bridge, null],
        'livewire' => ['Livewire', TechnologyRole::Bridge, null],

        // Tools and notable libraries
        'vite' => ['Vite', TechnologyRole::Tool, 'logos:vitejs'],
        'tailwindcss' => ['Tailwind CSS', TechnologyRole::Tool, 'logos:tailwindcss-icon'],
        'prisma' => ['Prisma', TechnologyRole::Tool, 'logos:prisma'],
        'tree-sitter' => ['tree-sitter', TechnologyRole::Tool, null],
        'mermaid' => ['Mermaid', TechnologyRole::Tool, 'logos:mermaid'],

        // Background workers
        'celery' => ['Celery', TechnologyRole::Worker, null],

        // Where the data is stored
        'sqlite' => ['SQLite', TechnologyRole::Database, 'logos:sqlite'],
        'postgresql' => ['PostgreSQL', TechnologyRole::Database, 'logos:postgresql'],
        'mysql' => ['MySQL', TechnologyRole::Database, 'logos:mysql-icon'],
        'mariadb' => ['MariaDB', TechnologyRole::Database, 'logos:mariadb-icon'],
        'sqlserver' => ['SQL Server', TechnologyRole::Database, null],
        'mongodb' => ['MongoDB', TechnologyRole::Database, 'logos:mongodb-icon'],
        'minio' => ['MinIO', TechnologyRole::Database, null],

        // Cache, queue and search servers
        'redis' => ['Redis', TechnologyRole::Cache, 'logos:redis'],
        'memcached' => ['Memcached', TechnologyRole::Cache, 'logos:memcached'],
        'rabbitmq' => ['RabbitMQ', TechnologyRole::Cache, 'logos:rabbitmq-icon'],
        'beanstalkd' => ['Beanstalkd', TechnologyRole::Cache, null],
        'elasticsearch' => ['Elasticsearch', TechnologyRole::Cache, 'logos:elasticsearch'],
        'meilisearch' => ['Meilisearch', TechnologyRole::Cache, 'logos:meilisearch'],

        // External systems
        'anthropic' => ['Anthropic API (Claude)', TechnologyRole::External, 'logos:anthropic-icon'],
        'openai' => ['OpenAI API', TechnologyRole::External, 'logos:openai-icon'],
        'stripe' => ['Stripe', TechnologyRole::External, 'logos:stripe'],
        'aws' => ['Amazon Web Services', TechnologyRole::External, 'logos:aws'],
        'aws-s3' => ['Amazon S3', TechnologyRole::External, 'logos:aws-s3'],
        'aws-sqs' => ['Amazon SQS', TechnologyRole::External, 'logos:aws-sqs'],
        'aws-ses' => ['Amazon SES', TechnologyRole::External, 'logos:aws-ses'],
        'github-api' => ['GitHub API', TechnologyRole::External, 'logos:github-icon'],
        'sentry' => ['Sentry', TechnologyRole::External, 'logos:sentry-icon'],
        'pusher' => ['Pusher', TechnologyRole::External, 'logos:pusher-icon'],
        'twilio' => ['Twilio', TechnologyRole::External, 'logos:twilio-icon'],
        'resend' => ['Resend', TechnologyRole::External, 'logos:resend-icon'],
        'postmark' => ['Postmark', TechnologyRole::External, null],
        'mailgun' => ['Mailgun', TechnologyRole::External, 'logos:mailgun-icon'],
        'smtp' => ['Server de e-mail (SMTP)', TechnologyRole::External, null],
        'supabase' => ['Supabase', TechnologyRole::External, 'logos:supabase-icon'],
        'firebase' => ['Firebase', TechnologyRole::External, 'logos:firebase'],

        // Packaging and deployment
        'docker' => ['Docker', TechnologyRole::Infrastructure, 'logos:docker-icon'],
        'kubernetes' => ['Kubernetes', TechnologyRole::Infrastructure, 'logos:kubernetes'],
        'nginx' => ['Nginx', TechnologyRole::Infrastructure, 'logos:nginx'],
    ];

    /**
     * composer.json "require" package => technology key.
     */
    public const COMPOSER_PACKAGES = [
        'laravel/framework' => 'laravel',
        'symfony/framework-bundle' => 'symfony',
        'slim/slim' => 'slim',
        'cakephp/cakephp' => 'cakephp',
        'inertiajs/inertia-laravel' => 'inertia',
        'livewire/livewire' => 'livewire',
        'anthropic-ai/sdk' => 'anthropic',
        'openai-php/client' => 'openai',
        'openai-php/laravel' => 'openai',
        'stripe/stripe-php' => 'stripe',
        'laravel/cashier' => 'stripe',
        'aws/aws-sdk-php' => 'aws',
        'knplabs/github-api' => 'github-api',
        'sentry/sentry' => 'sentry',
        'sentry/sentry-laravel' => 'sentry',
        'pusher/pusher-php-server' => 'pusher',
        'twilio/sdk' => 'twilio',
        'resend/resend-php' => 'resend',
        'resend/resend-laravel' => 'resend',
    ];

    /**
     * package.json "dependencies" / "devDependencies" package => technology key.
     */
    public const NPM_PACKAGES = [
        'react' => 'react',
        'vue' => 'vue',
        'svelte' => 'svelte',
        '@angular/core' => 'angular',
        'solid-js' => 'solid',
        'preact' => 'preact',
        'electron' => 'electron',
        'next' => 'nextjs',
        'nuxt' => 'nuxt',
        '@sveltejs/kit' => 'sveltekit',
        'astro' => 'astro',
        '@remix-run/react' => 'remix',
        'express' => 'express',
        'fastify' => 'fastify',
        '@nestjs/core' => 'nestjs',
        'koa' => 'koa',
        'hono' => 'hono',
        '@inertiajs/react' => 'inertia',
        '@inertiajs/vue3' => 'inertia',
        '@inertiajs/svelte' => 'inertia',
        'typescript' => 'typescript',
        'vite' => 'vite',
        'tailwindcss' => 'tailwindcss',
        'prisma' => 'prisma',
        '@prisma/client' => 'prisma',
        'mermaid' => 'mermaid',
        'pg' => 'postgresql',
        'mysql2' => 'mysql',
        'mongodb' => 'mongodb',
        'mongoose' => 'mongodb',
        'better-sqlite3' => 'sqlite',
        'redis' => 'redis',
        'ioredis' => 'redis',
        '@anthropic-ai/sdk' => 'anthropic',
        'openai' => 'openai',
        'stripe' => 'stripe',
        'octokit' => 'github-api',
        '@octokit/rest' => 'github-api',
        'pusher-js' => 'pusher',
        '@supabase/supabase-js' => 'supabase',
        'firebase' => 'firebase',
    ];

    /**
     * package.json packages recognized by the start of their name => technology key.
     */
    public const NPM_PACKAGE_PREFIXES = [
        '@aws-sdk/' => 'aws',
        '@sentry/' => 'sentry',
    ];

    /**
     * pyproject.toml / requirements.txt package (lowercase) => technology key.
     */
    public const PYTHON_PACKAGES = [
        'django' => 'django',
        'flask' => 'flask',
        'fastapi' => 'fastapi',
        'celery' => 'celery',
        'tree-sitter' => 'tree-sitter',
        'tree-sitter-language-pack' => 'tree-sitter',
        'psycopg' => 'postgresql',
        'psycopg2' => 'postgresql',
        'psycopg2-binary' => 'postgresql',
        'asyncpg' => 'postgresql',
        'pymysql' => 'mysql',
        'mysqlclient' => 'mysql',
        'pymongo' => 'mongodb',
        'redis' => 'redis',
        'anthropic' => 'anthropic',
        'openai' => 'openai',
        'stripe' => 'stripe',
        'boto3' => 'aws',
        'pygithub' => 'github-api',
        'sentry-sdk' => 'sentry',
    ];

    /**
     * go.mod module => technology key; a module also matches when it starts with "{module}/" or "{module}-".
     */
    public const GO_MODULES = [
        'github.com/gin-gonic/gin' => 'gin',
        'github.com/labstack/echo' => 'echo',
        'github.com/gofiber/fiber' => 'fiber',
        'github.com/go-chi/chi' => 'chi',
        'github.com/jackc/pgx' => 'postgresql',
        'github.com/lib/pq' => 'postgresql',
        'github.com/go-sql-driver/mysql' => 'mysql',
        'github.com/mattn/go-sqlite3' => 'sqlite',
        'modernc.org/sqlite' => 'sqlite',
        'go.mongodb.org/mongo-driver' => 'mongodb',
        'github.com/redis/go-redis' => 'redis',
        'github.com/go-redis/redis' => 'redis',
        'github.com/anthropics/anthropic-sdk-go' => 'anthropic',
        'github.com/sashabaranov/go-openai' => 'openai',
        'github.com/openai/openai-go' => 'openai',
        'github.com/stripe/stripe-go' => 'stripe',
        'github.com/aws/aws-sdk-go' => 'aws',
        'github.com/google/go-github' => 'github-api',
    ];

    /**
     * .env.example DB_CONNECTION value => technology key.
     */
    public const DATABASE_CONNECTIONS = [
        'sqlite' => 'sqlite',
        'pgsql' => 'postgresql',
        'mysql' => 'mysql',
        'mariadb' => 'mariadb',
        'sqlsrv' => 'sqlserver',
        'mongodb' => 'mongodb',
    ];

    /**
     * .env.example CACHE_STORE / CACHE_DRIVER / SESSION_DRIVER value => technology key.
     */
    public const CACHE_STORES = [
        'redis' => 'redis',
        'memcached' => 'memcached',
    ];

    /**
     * .env.example QUEUE_CONNECTION value => technology key ("database" and "sync" need no other server).
     */
    public const QUEUE_CONNECTIONS = [
        'redis' => 'redis',
        'sqs' => 'aws-sqs',
        'beanstalkd' => 'beanstalkd',
        'rabbitmq' => 'rabbitmq',
    ];

    /**
     * .env.example MAIL_MAILER value => technology key ("log" and "array" send nothing).
     */
    public const MAILERS = [
        'smtp' => 'smtp',
        'ses' => 'aws-ses',
        'postmark' => 'postmark',
        'resend' => 'resend',
        'mailgun' => 'mailgun',
    ];

    /**
     * Docker image name (without registry, owner and tag) => technology key.
     */
    public const DOCKER_IMAGES = [
        'postgres' => 'postgresql',
        'postgis' => 'postgresql',
        'mysql' => 'mysql',
        'mariadb' => 'mariadb',
        'mongo' => 'mongodb',
        'redis' => 'redis',
        'valkey' => 'redis',
        'memcached' => 'memcached',
        'rabbitmq' => 'rabbitmq',
        'elasticsearch' => 'elasticsearch',
        'meilisearch' => 'meilisearch',
        'minio' => 'minio',
        'nginx' => 'nginx',
    ];

    /**
     * Build the technology with this key, found in $source.
     */
    public static function make(string $key, string $source): Technology
    {
        [$name, $role, $icon] = self::TECHNOLOGIES[$key];

        return new Technology($key, $name, $role, $icon, $source);
    }

    /**
     * The technology key of a package.json package, or null when we do not recognize it.
     */
    public static function npmPackage(string $package): ?string
    {
        if (isset(self::NPM_PACKAGES[$package])) {
            return self::NPM_PACKAGES[$package];
        }

        foreach (self::NPM_PACKAGE_PREFIXES as $prefix => $key) {
            if (str_starts_with($package, $prefix)) {
                return $key;
            }
        }

        return null;
    }

    /**
     * The technology key of a go.mod module ("github.com/gofiber/fiber/v2" is Fiber), or null.
     */
    public static function goModule(string $module): ?string
    {
        foreach (self::GO_MODULES as $known => $key) {
            if ($module === $known || str_starts_with($module, "{$known}/") || str_starts_with($module, "{$known}-")) {
                return $key;
            }
        }

        return null;
    }
}

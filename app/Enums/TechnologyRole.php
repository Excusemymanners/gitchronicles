<?php

namespace App\Enums;

/**
 * What a detected technology does in a project, which decides where the C4 diagrams show it
 * (see App\Diagrams\TechnologyCatalog and ArchitectureDiagramBuilder).
 */
enum TechnologyRole: string
{
    /** A programming language: PHP, Python, Go, Node.js, TypeScript. */
    case Language = 'language';

    /** A web framework that runs on the server: Laravel, Django, Express, Gin. */
    case Backend = 'backend';

    /** A framework that is both the server and the pages: Next.js, Nuxt, SvelteKit. */
    case Fullstack = 'fullstack';

    /** A framework for the pages that run in the browser: React, Vue, Svelte. */
    case Ui = 'ui';

    /** Glue between the server and the pages: Inertia. */
    case Bridge = 'bridge';

    /** A build or styling tool, or a library worth naming: Vite, Tailwind CSS, tree-sitter. */
    case Tool = 'tool';

    /** A process that runs background jobs: Celery. */
    case Worker = 'worker';

    /** Where the data is stored: SQLite, PostgreSQL, MySQL, MongoDB. */
    case Database = 'database';

    /** A cache or queue server: Redis, Memcached, RabbitMQ. */
    case Cache = 'cache';

    /** A service outside the system, reached through its API: Anthropic, Stripe, GitHub. */
    case External = 'external';

    /** How the system is packaged or deployed: Docker, Kubernetes, Nginx. */
    case Infrastructure = 'infrastructure';
}

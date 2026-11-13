<?php

namespace App\Diagrams;

/**
 * Puts a module (a folder, see ModuleGrouper) in an architecture layer by the names of its folders,
 * the way most projects name them (Laravel, Symfony, Django, Express, Go, ...):
 *
 *     Entry points → User interface → Web/API → Application logic → Data, and Other for the rest.
 *
 * The rules are checked in a fixed order and the first one that matches any folder of the path wins:
 * "app/Http" is Web/API (http) before it can be Application logic (app); "frontend/lib" is User interface (frontend).
 *
 * The layer titles and the exclusion labels are written inside the Mermaid code and saved with the
 * diagram, so they are in the content language (English, see App\Support\ContentLanguage).
 *
 * Some folders are not part of the running application and are left out of the diagrams:
 * tests, configuration, documentation, translations, runtime files (storage) and the root folder.
 */
final class LayerClassifier
{
    /**
     * The layers in the order they are drawn, from the top: key => title.
     */
    public const LAYERS = [
        'entry' => 'Entry points',
        'ui' => 'User interface',
        'web' => 'Web/API',
        'logic' => 'Application logic',
        'data' => 'Data',
        'other' => 'Other',
    ];

    /**
     * The folder names of each layer, in the order the rules are checked (lowercase).
     */
    private const LAYER_FOLDERS = [
        'entry' => ['routes', 'bootstrap', 'public', 'cmd', 'bin', 'cli', 'console', 'commands', 'entrypoints'],
        'ui' => ['resources', 'frontend', 'client', 'ui', 'components', 'pages', 'views', 'view', 'templates', 'layouts', 'styles', 'css', 'assets', 'static', 'www'],
        'web' => ['http', 'api', 'controllers', 'controller', 'handlers', 'handler', 'middleware', 'middlewares', 'endpoints', 'graphql', 'routers', 'router'],
        'data' => ['models', 'model', 'database', 'db', 'migrations', 'schema', 'schemas', 'entities', 'entity', 'repositories', 'prisma', 'seeders', 'factories', 'persistence', 'dao', 'orm'],
        'logic' => ['app', 'src', 'lib', 'libs', 'pkg', 'internal', 'core', 'domain', 'services', 'service', 'actions', 'jobs', 'workers', 'support', 'utils', 'helpers', 'enums', 'events', 'listeners', 'policies', 'providers', 'mail', 'notifications', 'rules', 'analysis', 'ai'],
    ];

    /**
     * The folders left out of the diagrams: reason => folder names (lowercase).
     */
    private const EXCLUDED_FOLDERS = [
        'tests' => ['tests', 'test', 'spec', 'specs', '__tests__', 'e2e', 'fixtures'],
        'config' => ['config', 'configs', '.github'],
        'docs' => ['docs', 'doc', 'documentation'],
        'lang' => ['lang', 'locales', 'locale', 'i18n', 'translations'],
        'storage' => ['storage'],
    ];

    /**
     * What the page says about each group of excluded folders.
     */
    public const EXCLUSION_LABELS = [
        'root' => 'Files in the project root',
        'tests' => 'Tests',
        'config' => 'Configuration',
        'docs' => 'Documentation',
        'lang' => 'Translations',
        'storage' => 'Files written at runtime (storage)',
        'no_code' => 'Folders without code',
    ];

    /**
     * Why a module is left out of the diagrams ("root", "tests", "config", "docs", "lang", "storage"),
     * or null when it is part of the application.
     */
    public static function exclusionReason(string $modulePath): ?string
    {
        if ($modulePath === '/') {
            return 'root';
        }

        $folders = self::folders($modulePath);

        foreach (self::EXCLUDED_FOLDERS as $reason => $names) {
            // A documentation folder is one at the top (docs/); deeper, "Documentation" is a code namespace
            // (app/Documentation), not pages to read.
            if (array_intersect($reason === 'docs' ? [$folders[0]] : $folders, $names) !== []) {
                return $reason;
            }
        }

        return null;
    }

    /**
     * The layer key of a module that is part of the application (see LAYERS).
     */
    public static function layerOf(string $modulePath): string
    {
        $folders = self::folders($modulePath);

        foreach (self::LAYER_FOLDERS as $layer => $names) {
            if (array_intersect($folders, $names) !== []) {
                return $layer;
            }
        }

        return 'other';
    }

    /**
     * The folder names of a module path, in lowercase: "app/Http" → ["app", "http"].
     *
     * @return list<string>
     */
    private static function folders(string $modulePath): array
    {
        return array_map(mb_strtolower(...), explode('/', trim($modulePath, '/')));
    }
}

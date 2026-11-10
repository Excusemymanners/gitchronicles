<?php

namespace Tests\Unit\Diagrams;

use App\Diagrams\LayerClassifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class LayerClassifierTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function modulesAndTheirLayers(): array
    {
        return [
            'Laravel routes' => ['routes', 'entry'],
            'artisan commands' => ['app/Console', 'entry'],
            'a CLI folder' => ['tools/cli', 'entry'],
            'React pages' => ['resources/js', 'ui'],
            'a frontend library' => ['frontend/lib', 'ui'],
            'Laravel HTTP layer before the generic app folder' => ['app/Http', 'web'],
            'Express handlers' => ['server/handlers', 'web'],
            'Eloquent models' => ['app/Models', 'data'],
            'migrations' => ['database/migrations', 'data'],
            'actions' => ['app/Actions', 'logic'],
            'a Go package' => ['internal/billing', 'logic'],
            'folder names in any case' => ['App/HTTP', 'web'],
            'an unknown folder' => ['tools', 'other'],
        ];
    }

    #[DataProvider('modulesAndTheirLayers')]
    public function test_puts_a_module_in_the_layer_of_its_folder_names(string $modulePath, string $layer): void
    {
        $this->assertSame($layer, LayerClassifier::layerOf($modulePath));
    }

    /**
     * @return array<string, array{string, string|null}>
     */
    public static function modulesAndWhyTheyAreLeftOut(): array
    {
        return [
            'the root folder' => ['/', 'root'],
            'tests' => ['tests/Feature', 'tests'],
            'Java tests' => ['src/test', 'tests'],
            'configuration' => ['config', 'config'],
            'GitHub workflows' => ['.github/workflows', 'config'],
            'documentation' => ['docs', 'docs'],
            'translations' => ['lang/ro', 'lang'],
            'runtime files' => ['storage/framework', 'storage'],
            'application code' => ['app/Http', null],
        ];
    }

    #[DataProvider('modulesAndWhyTheyAreLeftOut')]
    public function test_leaves_out_tests_configuration_documentation_translations_storage_and_the_root(string $modulePath, ?string $reason): void
    {
        $this->assertSame($reason, LayerClassifier::exclusionReason($modulePath));
    }
}

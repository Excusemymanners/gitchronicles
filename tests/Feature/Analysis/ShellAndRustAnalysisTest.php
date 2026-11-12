<?php

namespace Tests\Feature\Analysis;

use App\Analysis\RepositoryAnalyzer;
use App\Models\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\Concerns\RunsGit;
use Tests\TestCase;

/**
 * The real analysis of two small fixture projects: a shell project (functions, `source`, direct runs,
 * scripts known only by their shebang) and a Rust workspace of two crates (`mod`, `use crate::`,
 * `use super::`, grouped `use`, a `path =` dependency and an external crate).
 * Every test is skipped when the analyzer is not installed (analyzer/.venv).
 */
class ShellAndRustAnalysisTest extends TestCase
{
    use RefreshDatabase;
    use RunsGit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessAnalyzerIsInstalled();
    }

    public function test_a_file_without_an_extension_gets_its_language_from_the_shebang(): void
    {
        $repository = $this->analyzeFixture('analyzer-shell-project');

        $languages = $repository->files()->pluck('language', 'path')->all();

        $this->assertSame('Shell', $languages['bin/run']);
        $this->assertSame('Python', $languages['tools/convert']);
        $this->assertNull($languages['NOTES']);
    }

    public function test_shell_functions_are_symbols_with_their_line_ranges(): void
    {
        $repository = $this->analyzeFixture('analyzer-shell-project');

        $this->assertSame([['function', 'install_tool', 9, 12], ['function', 'finish', 14, 16]], $this->symbolsOf($repository, 'install.sh'));
        $this->assertSame([['function', 'log_info', 2, 4], ['function', 'warn', 6, 8]], $this->symbolsOf($repository, 'lib/common.sh'));
        $this->assertSame([['function', 'run_tool', 3, 5]], $this->symbolsOf($repository, 'bin/run'));
        $this->assertSame([['function', 'convert', 2, 3]], $this->symbolsOf($repository, 'tools/convert'));
    }

    public function test_shell_sources_and_direct_runs_are_resolved_and_variable_paths_are_not_guessed(): void
    {
        $repository = $this->analyzeFixture('analyzer-shell-project');

        $this->assertSame([
            ['$DIR/lib/common.sh', 'lib/common.sh', false],
            ['./lib/colors.sh', 'lib/colors.sh', false],
            ['$HOME/.profile', null, true],
            ['$CONFIG_DIR/settings.sh', null, true],
            ['scripts/build.sh', 'scripts/build.sh', false],
            ['./bin/run', 'bin/run', false],
        ], $this->importsOf($repository, 'install.sh'));
        $this->assertSame([['../lib/common.sh', 'lib/common.sh', false]], $this->importsOf($repository, 'scripts/build.sh'));
        $this->assertSame([
            ['$(dirname "$0")/../lib/colors.sh', 'lib/colors.sh', false],
            ['./scripts/build.sh', 'scripts/build.sh', false],
        ], $this->importsOf($repository, 'bin/run'));
    }

    public function test_rust_symbols_are_structs_enums_traits_functions_and_methods(): void
    {
        $repository = $this->analyzeFixture('analyzer-rust-project');

        $this->assertSame([['struct', 'Config', 1, 1], ['method', 'default', 4, 6]], $this->symbolsOf($repository, 'app/src/config.rs'));
        $this->assertSame([['struct', 'Server', 3, 3], ['trait', 'Handler', 5, 7]], $this->symbolsOf($repository, 'app/src/net/server.rs'));
        $this->assertSame([['struct', 'Store', 5, 5], ['enum', 'Mode', 7, 9]], $this->symbolsOf($repository, 'engine/src/store/mod.rs'));
    }

    public function test_rust_mod_use_crate_super_self_groups_and_workspace_crates_are_resolved(): void
    {
        $repository = $this->analyzeFixture('analyzer-rust-project');

        $this->assertSame([
            ['mod config', 'app/src/config.rs', false],
            ['mod net', 'app/src/net/mod.rs', false],
            ['crate::config::Config', 'app/src/config.rs', false],
            ['crate::net::client::Client', 'app/src/net/client.rs', false],
            ['crate::net::server', 'app/src/net/server.rs', false],
            ['crate::net::server::Server', 'app/src/net/server.rs', false],
            ['store_engine::store::Store', 'engine/src/store/mod.rs', false],
            ['serde::Serialize', null, true],
            ['std::io', null, true],
        ], $this->importsOf($repository, 'app/src/main.rs'));
        $this->assertSame([
            ['super::server::Server', 'app/src/net/server.rs', false],
            ['super', 'app/src/net/mod.rs', false],
            ['crate::config::Config', 'app/src/config.rs', false],
        ], $this->importsOf($repository, 'app/src/net/client.rs'));
        $this->assertSame([
            ['mod client', 'app/src/net/client.rs', false],
            ['mod server', 'app/src/net/server.rs', false],
        ], $this->importsOf($repository, 'app/src/net/mod.rs'));
        $this->assertSame([['mod cache', 'engine/src/store/cache.rs', false], ['cache::Cache', 'engine/src/store/cache.rs', false]], $this->importsOf($repository, 'engine/src/store/mod.rs'));
    }

    public function test_a_use_inside_an_inline_test_module_does_not_import_anything(): void
    {
        $repository = $this->analyzeFixture('analyzer-rust-project');

        $targets = array_column($this->importsOf($repository, 'app/src/net/client.rs'), 0);

        $this->assertNotContains('self::helper', $targets);
        $this->assertSame(1, count(array_keys($targets, 'super')));
    }

    private function analyzeFixture(string $fixture): Repository
    {
        $repository = Repository::factory()->ready()->create();
        File::copyDirectory(base_path("tests/Fixtures/{$fixture}"), $repository->clonePath());
        $this->commitEverything($repository->clonePath());

        app(RepositoryAnalyzer::class)->analyze($repository);

        return $repository;
    }

    /**
     * @return list<array{string, string, int, int}>
     */
    private function symbolsOf(Repository $repository, string $path): array
    {
        return $repository->files()->firstWhere('path', $path)->symbols()->orderBy('id')->get()
            ->map(fn ($symbol) => [$symbol->kind, $symbol->name, $symbol->start_line, $symbol->end_line])
            ->all();
    }

    /**
     * @return list<array{string, string|null, bool}>
     */
    private function importsOf(Repository $repository, string $path): array
    {
        return $repository->files()->firstWhere('path', $path)->imports()->with('targetFile')->orderBy('id')->get()
            ->map(fn ($import) => [$import->target, $import->targetFile?->path, $import->is_external])
            ->all();
    }
}

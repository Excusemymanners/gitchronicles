<?php

namespace Tests\Concerns;

use App\Models\Commit;
use App\Models\CommitFile;
use App\Models\FileImport;
use App\Models\Module;
use App\Models\ModuleDependency;
use App\Models\Repository;
use App\Models\RepositoryFile;

/**
 * A small analyzed project made with factories, for the tests of the diagrams' meaning:
 *
 *     routes → app/Http (2 imports); app/Http → app/Models (3), app/Http → app/Support (1);
 *     app/Models → app/Support (1, against the layer order: data → logic); app/Support → app/Models (1, a loop);
 *     resources/js imports nothing. Two commits changed app/Http, one changed app/Models.
 */
trait BuildsSmallProject
{
    /**
     * @return array<string, Module> the modules, by path
     */
    private function smallProject(Repository $repository): array
    {
        $modules = [];

        foreach (['routes' => 30, 'app/Http' => 300, 'app/Models' => 200, 'app/Support' => 100, 'resources/js' => 400] as $path => $lines) {
            $modules[$path] = Module::factory()->for($repository)->create(['path' => $path, 'name' => $path, 'file_count' => 1, 'line_count' => $lines]);
        }

        $files = [];

        foreach (['routes/web.php', 'routes/api.php', 'app/Http/A.php', 'app/Http/B.php', 'app/Http/C.php', 'app/Models/M.php', 'app/Support/S.php', 'resources/js/app.tsx'] as $path) {
            $module = collect($modules)->first(fn (Module $module): bool => str_starts_with($path, $module->path.'/'));
            $files[$path] = RepositoryFile::factory()->for($repository)->for($module)->create(['path' => $path, 'language' => str_ends_with($path, 'tsx') ? 'TypeScript' : 'PHP', 'lines' => 10]);
        }

        $imports = [
            ['routes/web.php', 'app/Http/A.php'], ['routes/api.php', 'app/Http/B.php'],
            ['app/Http/A.php', 'app/Models/M.php'], ['app/Http/B.php', 'app/Models/M.php'], ['app/Http/C.php', 'app/Models/M.php'],
            ['app/Http/A.php', 'app/Support/S.php'],
            ['app/Models/M.php', 'app/Support/S.php'], ['app/Support/S.php', 'app/Models/M.php'],
        ];
        $weights = [];

        foreach ($imports as [$from, $to]) {
            FileImport::factory()->create(['repository_file_id' => $files[$from]->id, 'repository_id' => $repository->id, 'target' => $to, 'target_file_id' => $files[$to]->id, 'is_external' => false]);
            $key = $files[$from]->module_id.':'.$files[$to]->module_id;
            $weights[$key] = ($weights[$key] ?? 0) + 1;
        }

        foreach ($weights as $key => $weight) {
            [$from, $to] = explode(':', $key);
            ModuleDependency::factory()->create(['repository_id' => $repository->id, 'from_module_id' => $from, 'to_module_id' => $to, 'weight' => $weight]);
        }

        foreach ([['app/Http/A.php'], ['app/Http/A.php', 'app/Http/B.php'], ['app/Models/M.php']] as $changedFiles) {
            $commit = Commit::factory()->create(['repository_id' => $repository->id]);

            foreach ($changedFiles as $path) {
                CommitFile::factory()->create(['commit_id' => $commit->id, 'path' => $path]);
            }
        }

        return $modules;
    }
}

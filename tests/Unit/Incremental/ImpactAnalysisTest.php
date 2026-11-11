<?php

namespace Tests\Unit\Incremental;

use App\Git\ChangeSet;
use App\Incremental\ImpactAnalysis;
use App\Incremental\ModuleGraph;
use PHPUnit\Framework\TestCase;

class ImpactAnalysisTest extends TestCase
{
    /**
     * app/Http imports app/Models, which imports app/Enums; routes imports app/Http; docs stands alone.
     */
    private function graph(array $moduleOfFile = [], array $edges = []): ModuleGraph
    {
        $moduleOfFile += [
            'app/Http/HomeController.php' => 'app/Http',
            'app/Models/User.php' => 'app/Models',
            'app/Enums/Role.php' => 'app/Enums',
            'routes/web.php' => 'routes',
            'docs/plan.md' => 'docs',
        ];

        $modulePaths = array_values(array_unique($moduleOfFile));
        sort($modulePaths);

        return new ModuleGraph($moduleOfFile, $edges ?: [['app/Http', 'app/Models'], ['app/Models', 'app/Enums'], ['routes', 'app/Http']], $modulePaths);
    }

    public function test_a_modified_file_affects_its_module_and_the_neighbours_one_step_away(): void
    {
        $impact = ImpactAnalysis::of(new ChangeSet(modified: ['app/Models/User.php']), $this->graph(), $this->graph());

        $this->assertSame([
            ['path' => 'app/Models', 'files' => 1, 'via' => 'changed'],
            ['path' => 'app/Http', 'files' => 0, 'via' => 'imports'],
            ['path' => 'app/Enums', 'files' => 0, 'via' => 'imported'],
        ], $impact['modules']);
        $this->assertSame([], $impact['modules_added']);
        $this->assertSame([], $impact['modules_removed']);
    }

    public function test_a_module_two_steps_away_is_not_affected(): void
    {
        $impact = ImpactAnalysis::of(new ChangeSet(modified: ['app/Enums/Role.php']), $this->graph(), $this->graph());

        $this->assertSame(['app/Enums', 'app/Models'], array_column($impact['modules'], 'path'));
    }

    public function test_added_and_deleted_files_count_for_their_modules(): void
    {
        $before = $this->graph();
        $after = $this->graph(['app/Http/AboutController.php' => 'app/Http', 'app/Jobs/Clean.php' => 'app/Jobs']);
        $after = new ModuleGraph(array_diff_key($after->moduleOfFile, ['docs/plan.md' => true]), $after->edges, array_values(array_diff($after->modulePaths, ['docs'])));

        $impact = ImpactAnalysis::of(
            new ChangeSet(added: ['app/Http/AboutController.php', 'app/Jobs/Clean.php'], deleted: ['docs/plan.md']),
            $before,
            $after,
        );

        $this->assertSame([
            ['path' => 'app/Http', 'files' => 1, 'via' => 'changed'],
            ['path' => 'app/Jobs', 'files' => 1, 'via' => 'changed'],
            ['path' => 'routes', 'files' => 0, 'via' => 'imports'],
            ['path' => 'app/Models', 'files' => 0, 'via' => 'imported'],
        ], $impact['modules']);
        $this->assertSame(['app/Jobs'], $impact['modules_added']);
        $this->assertSame(['docs'], $impact['modules_removed']);
    }

    public function test_an_import_that_was_removed_still_links_the_two_modules(): void
    {
        $before = $this->graph();
        $after = $this->graph(edges: [['app/Models', 'app/Enums'], ['routes', 'app/Http']]);

        $impact = ImpactAnalysis::of(new ChangeSet(modified: ['app/Http/HomeController.php']), $before, $after);

        $this->assertContains(['path' => 'app/Models', 'files' => 0, 'via' => 'imported'], $impact['modules']);
    }

    public function test_changed_files_outside_the_analysis_affect_no_module(): void
    {
        $impact = ImpactAnalysis::of(new ChangeSet(added: ['public/logo.png'], modified: ['composer.lock']), $this->graph(), $this->graph());

        $this->assertSame([], $impact['modules']);
    }

    public function test_files_moved_to_another_module_by_the_grouping_count_as_changed(): void
    {
        $before = $this->graph();
        $after = $this->graph(['app/Http/HomeController.php' => 'app/Http/Controllers']);

        $impact = ImpactAnalysis::of(new ChangeSet, $before, $after);

        $this->assertSame(['path' => 'app/Http/Controllers', 'files' => 1, 'via' => 'changed'], $impact['modules'][0]);
        $this->assertSame(['app/Http/Controllers'], $impact['modules_added']);
        $this->assertSame(['app/Http'], $impact['modules_removed']);
    }
}

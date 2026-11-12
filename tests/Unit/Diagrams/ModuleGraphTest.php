<?php

namespace Tests\Unit\Diagrams;

use App\Diagrams\ModuleGraph;
use PHPUnit\Framework\TestCase;

/**
 * The graph arithmetic of the findings, on a graph made by hand:
 *
 *     web → logic (5), web → data (2), logic → data (4), data → logic (1)   (a two-module loop)
 *     jobs → web (3), jobs → logic (1), a → b → c → a (a three-module loop), "lonely" imports nothing.
 */
class ModuleGraphTest extends TestCase
{
    private function graph(): ModuleGraph
    {
        $edge = fn (string $from, string $to, int $weight): array => ['from' => $from, 'to' => $to, 'weight' => $weight];

        return new ModuleGraph(
            ['web', 'logic', 'data', 'jobs', 'a', 'b', 'c', 'lonely'],
            [
                $edge('web', 'logic', 5), $edge('web', 'data', 2), $edge('logic', 'data', 4), $edge('data', 'logic', 1),
                $edge('jobs', 'web', 3), $edge('jobs', 'logic', 1),
                $edge('a', 'b', 1), $edge('b', 'c', 1), $edge('c', 'a', 1),
                $edge('web', 'outside', 9), $edge('web', 'web', 7),
            ],
        );
    }

    public function test_most_depended_on_counts_the_modules_that_import_each_module(): void
    {
        $this->assertSame([
            ['module' => 'logic', 'modules' => 3, 'imports' => 7],
            ['module' => 'data', 'modules' => 2, 'imports' => 6],
            ['module' => 'web', 'modules' => 1, 'imports' => 3],
        ], $this->graph()->mostDependedOn(3));
    }

    public function test_most_depending_on_others_counts_the_modules_each_module_imports(): void
    {
        $this->assertSame([
            ['module' => 'web', 'modules' => 2, 'imports' => 7],
            ['module' => 'jobs', 'modules' => 2, 'imports' => 4],
            ['module' => 'logic', 'modules' => 1, 'imports' => 4],
        ], $this->graph()->mostDependingOnOthers(3));
    }

    public function test_an_edge_to_an_unknown_module_or_to_itself_is_ignored(): void
    {
        $this->assertSame(19, $this->graph()->totalImports());
    }

    public function test_cycles_name_the_shortest_loop_of_each_group_of_modules(): void
    {
        $this->assertSame([
            ['modules' => ['a', 'b', 'c'], 'size' => 3],
            ['modules' => ['data', 'logic'], 'size' => 2],
        ], $this->graph()->cycles());
    }

    public function test_a_graph_without_loops_has_no_cycles(): void
    {
        $graph = new ModuleGraph(['x', 'y'], [['from' => 'x', 'to' => 'y', 'weight' => 1]]);

        $this->assertSame([], $graph->cycles());
    }

    public function test_imports_against_the_layer_order_go_from_a_lower_layer_to_a_higher_one(): void
    {
        $layers = ['web' => 'web', 'logic' => 'logic', 'data' => 'data', 'jobs' => 'entry', 'a' => 'other', 'b' => 'logic', 'c' => 'web', 'lonely' => 'other'];

        // "other" is not in the order, so the imports of a and c to and from it are not against it.
        $this->assertSame(
            [
                ['from' => 'b', 'to' => 'c', 'imports' => 1],
                ['from' => 'data', 'to' => 'logic', 'imports' => 1],
            ],
            $this->graph()->importsAgainstOrder($layers, ['entry', 'web', 'logic', 'data']),
        );
    }

    public function test_isolated_modules_have_no_import_in_or_out(): void
    {
        $this->assertSame(['lonely'], $this->graph()->isolated());
    }
}

<?php

namespace Tests\Unit\Diagrams;

use App\Diagrams\SystemMap;
use App\Diagrams\SystemMapValidator;
use PHPUnit\Framework\TestCase;

/**
 * The big picture proposed by the text generator is checked part by part and arrow by arrow against the
 * facts of the code; the deterministic picture never draws a part without an arrow.
 */
class SystemMapValidatorTest extends TestCase
{
    public function test_a_backed_answer_is_kept_with_its_numbered_path_and_the_backing_of_each_arrow(): void
    {
        $result = SystemMapValidator::validate($this->answer(), $this->facts());

        $this->assertNull($result['reason']);
        $this->assertSame(['web', 'jobs', 'analysis', 'git', 'db'], array_column($result['map']['nodes'], 'id'));
        $this->assertSame([1, 2, 3, null], array_column($result['map']['edges'], 'step'));
        $this->assertSame('links', $result['map']['edges'][0]['backing']['type']);
        $this->assertSame([['from' => 'app/Http', 'to' => 'app/Jobs', 'weight' => 3, 'kinds' => ['dispatch' => 2, 'import' => 1]]], $result['map']['edges'][0]['backing']['rows']);
        $this->assertSame('evidence', $result['map']['edges'][2]['backing']['type']);
        $this->assertSame(['proposed_nodes' => 5, 'proposed_edges' => 4, 'dropped_nodes' => 0, 'dropped_edges' => 0, 'fixed' => 0], $result['stats']);
    }

    public function test_an_unknown_module_a_module_in_two_parts_and_a_code_name_are_not_kept(): void
    {
        $answer = $this->answer();
        $answer['nodes'][1]['modules'] = ['app/Jobs', 'app/Nowhere'];
        $answer['nodes'][2]['modules'] = ['app/Analysis', 'app/Jobs'];
        $answer['nodes'][] = ['id' => 'ctrl', 'label' => 'RepositoryController', 'kind' => 'service', 'modules' => ['app/Support']];

        $result = SystemMapValidator::validate($answer, $this->facts());

        $nodes = array_column($result['map']['nodes'], 'modules', 'id');
        $this->assertSame(['app/Jobs'], $nodes['jobs']);
        $this->assertSame(['app/Analysis'], $nodes['analysis']);
        $this->assertArrayNotHasKey('ctrl', $nodes);
        $this->assertSame(1, $result['stats']['dropped_nodes']);
    }

    public function test_an_arrow_with_no_backing_is_dropped_and_a_part_left_alone_too(): void
    {
        $answer = $this->answer();
        $answer['nodes'][] = ['id' => 'support', 'label' => 'Helpers', 'kind' => 'service', 'modules' => ['app/Support']];
        $answer['edges'][] = ['id' => 'e5', 'from' => 'support', 'to' => 'git', 'verb' => 'clones the code'];
        $answer['edges'][] = ['id' => 'e6', 'from' => 'web', 'to' => 'jobs', 'verb' => 'starts a job'];

        $result = SystemMapValidator::validate($answer, $this->facts());

        $this->assertNotContains('support', array_column($result['map']['nodes'], 'id'));
        $this->assertSame(['e1', 'e2', 'e3', 'e4'], array_column($result['map']['edges'], 'id'));
        $this->assertSame(['dropped_nodes' => 1, 'dropped_edges' => 2], array_intersect_key($result['stats'], ['dropped_nodes' => 1, 'dropped_edges' => 1]));
    }

    public function test_fewer_than_sixty_percent_of_backed_arrows_or_five_parts_rejects_the_answer(): void
    {
        $answer = $this->answer();
        array_push($answer['edges'], ...array_map(fn (int $i): array => ['id' => "x{$i}", 'from' => 'git', 'to' => 'db', 'verb' => 'keeps things'], range(1, 3)));

        $this->assertSame('fewer than 60 % of the arrows are backed by the code', SystemMapValidator::validate($answer, $this->facts())['reason']);

        $small = $this->answer();
        $small['edges'] = [$small['edges'][0]];

        $this->assertSame('fewer than 5 parts survived', SystemMapValidator::validate($small, $this->facts())['reason']);
    }

    public function test_the_kind_of_a_part_follows_the_evidence(): void
    {
        $answer = $this->answer();
        $answer['nodes'][3]['kind'] = 'external';
        $answer['nodes'][2]['kind'] = 'worker';

        $result = SystemMapValidator::validate($answer, $this->facts());

        $kinds = array_column($result['map']['nodes'], 'kind', 'id');
        $this->assertSame(['tool', 'service'], [$kinds['git'], $kinds['analysis']]);
        $this->assertSame(2, $result['stats']['fixed']);
    }

    public function test_the_drawing_numbers_the_main_path_first_in_the_accent_and_names_no_code(): void
    {
        $map = SystemMapValidator::validate($this->answer(), $this->facts())['map'];
        $mermaid = SystemMap::mermaid($map);

        $this->assertStringContainsString('flowchart TB', $mermaid);
        $this->assertStringContainsString('n_web("<b>Web app</b><br>Takes the requests of the visitors")', $mermaid);
        $this->assertStringContainsString('n_db[("Database")]', $mermaid);
        $this->assertStringContainsString('n_web -->|"1 · starts a job"| n_jobs', $mermaid);
        $this->assertStringContainsString('linkStyle 0,1,2 '.SystemMap::MAIN_PATH_STYLE, $mermaid);
        $this->assertStringNotContainsString('app/', $mermaid);
    }

    public function test_the_deterministic_picture_has_no_part_without_an_arrow(): void
    {
        $facts = $this->facts();
        $facts['modules']['app/Lonely'] = ['id' => 9, 'layer' => 'other', 'lines' => 10, 'files' => 1, 'in' => 0, 'out' => 0, 'sentence' => null];

        $map = SystemMap::fallback($facts, 'shop');

        $connected = array_merge(array_column($map['edges'], 'from'), array_column($map['edges'], 'to'));
        $this->assertSame([], array_values(array_diff(array_column($map['nodes'], 'id'), $connected)));
        $this->assertNotContains('layer_other', array_column($map['nodes'], 'id'));
        $this->assertSame([], array_filter(array_column($map['edges'], 'step')));
        $this->assertContains('dispatches', array_column($map['edges'], 'verb'));
    }

    /**
     * @return array<string, mixed>
     */
    private function answer(): array
    {
        return [
            'title' => 'How shop works',
            'reading' => 'Start at the web app.',
            'nodes' => [
                ['id' => 'web', 'label' => 'Web app', 'does' => 'Takes the requests of the visitors', 'kind' => 'interface', 'modules' => ['app/Http']],
                ['id' => 'jobs', 'label' => 'Background jobs', 'does' => 'Runs the work in order', 'kind' => 'worker', 'modules' => ['app/Jobs']],
                ['id' => 'analysis', 'label' => 'Code analysis', 'does' => 'Reads the code', 'kind' => 'service', 'modules' => ['app/Analysis']],
                ['id' => 'git', 'label' => 'Git', 'does' => 'Copies the code', 'kind' => 'tool', 'modules' => ['tool:git']],
                ['id' => 'db', 'label' => 'Database', 'does' => null, 'kind' => 'data', 'modules' => ['store:database']],
            ],
            'edges' => [
                ['id' => 'e1', 'from' => 'web', 'to' => 'jobs', 'verb' => 'starts a job'],
                ['id' => 'e2', 'from' => 'jobs', 'to' => 'analysis', 'verb' => 'asks to read code'],
                ['id' => 'e3', 'from' => 'analysis', 'to' => 'git', 'verb' => 'clones the code'],
                ['id' => 'e4', 'from' => 'jobs', 'to' => 'db', 'verb' => 'saves the results', 'carries' => 'pages'],
            ],
            'main_path' => ['e1', 'e2', 'e3'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function facts(): array
    {
        $module = fn (int $id, string $layer): array => ['id' => $id, 'layer' => $layer, 'lines' => 100, 'files' => 2, 'in' => 1, 'out' => 1, 'sentence' => null];

        return [
            'modules' => ['app/Http' => $module(1, 'web'), 'app/Jobs' => $module(2, 'logic'), 'app/Analysis' => $module(3, 'logic'), 'app/Support' => $module(4, 'logic')],
            'links' => [
                ['from' => 'app/Http', 'to' => 'app/Jobs', 'weight' => 3, 'kinds' => ['dispatch' => 2, 'import' => 1]],
                ['from' => 'app/Jobs', 'to' => 'app/Analysis', 'weight' => 1, 'kinds' => ['import' => 1]],
            ],
            'externals' => [
                ['module' => 'app/Analysis', 'target' => 'tool:git', 'kind' => 'runs', 'count' => 4],
                ['module' => 'app/Jobs', 'target' => 'store:database', 'kind' => 'stores', 'count' => 2],
                ['module' => 'app/Jobs', 'target' => 'store:queue', 'kind' => 'queues', 'count' => 2],
            ],
            'targets' => [
                'tool:git' => ['name' => 'Git', 'type' => 'tool', 'count' => 4],
                'store:database' => ['name' => 'Database', 'type' => 'data', 'count' => 2],
                'store:queue' => ['name' => 'Job queue', 'type' => 'data', 'count' => 2],
            ],
            'entries' => [],
        ];
    }
}

<?php

namespace Tests\Unit\Diagrams;

use App\Diagrams\FlowParser;
use App\Diagrams\FlowSequence;
use PHPUnit\Framework\TestCase;

/**
 * The flows are sequence diagrams of requests and responses between parts named by their role, checked
 * message by message against the facts of the code; the main flow comes first.
 */
class FlowSequenceTest extends TestCase
{
    private const FILE_LINES = ['routes/web.php' => 40, 'app/Jobs/ProcessRepository.php' => 90];

    public function test_a_backed_flow_is_drawn_as_a_numbered_sequence_with_its_steps(): void
    {
        $result = FlowSequence::validate($this->flow(), $this->facts(), self::FILE_LINES);
        $mermaid = FlowSequence::mermaid($result['flow']);

        $this->assertNull($result['reason']);
        $this->assertSame(['proposed' => 5, 'dropped' => 0], $result['stats']);
        $this->assertSame(['visitor', 'web', 'jobs', 'db'], array_column($result['flow']['participants'], 'id'));
        $this->assertStringStartsWith("sequenceDiagram\n  autonumber\n  actor p_visitor as Visitor\n  participant p_web as Web app\n", $mermaid);
        $this->assertStringContainsString('participant p_db@{ "type": "database", "alias": "Database" }', $mermaid);
        $this->assertStringContainsString("  p_visitor->>+p_web: pastes a GitHub link\n", $mermaid);
        $this->assertStringContainsString("  Note over p_web,p_jobs: from here on everything runs in the background\n", $mermaid);
        $this->assertStringContainsString("  p_web-->>-p_visitor: the repository page\n", $mermaid);
        $this->assertSame(['path' => 'routes/web.php', 'start_line' => 3, 'end_line' => 5], $result['flow']['messages'][0]['code']);
        $this->assertNull($result['flow']['messages'][1]['code']);
    }

    public function test_an_unbacked_message_a_code_name_and_a_response_without_a_request_are_dropped(): void
    {
        $flow = $this->flow();
        $flow['messages'][] = ['from' => 'db', 'to' => 'web', 'text' => 'pages', 'type' => 'response'];
        $flow['messages'][] = ['from' => 'web', 'to' => 'db', 'text' => 'calls ProcessRepository::dispatch()', 'type' => 'request'];
        $flow['messages'][] = ['from' => 'visitor', 'to' => 'db', 'text' => 'reads the rows', 'type' => 'request'];

        $result = FlowSequence::validate($flow, $this->facts(), self::FILE_LINES);

        $this->assertSame(['proposed' => 8, 'dropped' => 3], $result['stats']);
        $this->assertCount(5, $result['flow']['messages']);
    }

    public function test_fewer_than_sixty_percent_of_backed_messages_rejects_the_flow(): void
    {
        $flow = $this->flow();
        $flow['participants'][] = ['id' => 'github', 'label' => 'GitHub', 'kind' => 'external', 'modules' => ['service:github']];
        array_push($flow['messages'], ...array_fill(0, 4, ['from' => 'jobs', 'to' => 'github', 'text' => 'clones the repository', 'type' => 'request']));

        $this->assertSame('fewer than 60 % of the messages are backed by the code', FlowSequence::validate($flow, $this->facts(), self::FILE_LINES)['reason']);
    }

    public function test_the_main_flow_comes_first_even_from_a_list_closed_too_early(): void
    {
        $side = [...$this->flow(), 'title' => 'What happens when someone opens a page', 'main' => false];
        $main = [...$this->flow(), 'main' => true];
        $broken = substr(json_encode(['flows' => [$side]]), 0, -1).','.json_encode($main).']}';

        $result = FlowParser::parse($broken, self::FILE_LINES, $this->facts());

        $this->assertSame(['what-happens-when-someone-adds-a-repository', 'what-happens-when-someone-opens-a-page'], array_column($result['flows'], 'key'));
        $this->assertSame([true, false], array_column($result['flows'], 'main'));
        $this->assertSame([['path' => 'routes/web.php', 'start_line' => 3, 'end_line' => 5]], $result['flows'][0]['sources']);
    }

    public function test_without_ai_the_main_flow_follows_the_route_to_the_dispatched_work(): void
    {
        $flow = FlowSequence::fallback($this->facts(), 'shop');

        $this->assertSame(['visitor', 'web', 'jobs', 'out3'], array_column($flow['participants'], 'id'));
        $this->assertSame(['sends a request', 'dispatches the work', 'reads and writes data', 'returns the answer'], array_column($flow['messages'], 'text'));
        $this->assertSame('the work waits in a queue and runs in the background', $flow['notes'][0]['text']);

        $library = $this->facts();
        $library['links'] = [];
        $this->assertNull(FlowSequence::fallback($library, 'shop'));
    }

    /**
     * @return array<string, mixed>
     */
    private function flow(): array
    {
        return [
            'title' => 'What happens when someone adds a repository',
            'explanation' => 'The visitor pastes a link and the work goes on in the background.',
            'main' => true,
            'participants' => [
                ['id' => 'visitor', 'label' => 'Visitor', 'kind' => 'person'],
                ['id' => 'web', 'label' => 'Web app', 'kind' => 'interface', 'modules' => ['routes', 'app/Http']],
                ['id' => 'jobs', 'label' => 'Background jobs', 'kind' => 'worker', 'modules' => ['app/Jobs']],
                ['id' => 'db', 'label' => 'Database', 'kind' => 'data', 'modules' => ['store:database']],
            ],
            'messages' => [
                ['from' => 'visitor', 'to' => 'web', 'text' => 'pastes a GitHub link', 'type' => 'request', 'code' => ['path' => 'routes/web.php', 'start_line' => 3, 'end_line' => 5], 'does' => 'The route of the form'],
                ['from' => 'web', 'to' => 'jobs', 'text' => 'starts the pipeline', 'type' => 'request', 'code' => ['path' => 'app/Missing.php', 'start_line' => 1, 'end_line' => 2]],
                ['from' => 'jobs', 'to' => 'db', 'text' => 'saves the results', 'type' => 'request'],
                ['from' => 'jobs', 'to' => 'db', 'text' => 'saves the pages', 'type' => 'request'],
                ['from' => 'web', 'to' => 'visitor', 'text' => 'the repository page', 'type' => 'response'],
            ],
            'notes' => [['after' => 2, 'over' => ['web', 'jobs'], 'text' => 'from here on everything runs in the background']],
            'groups' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function facts(): array
    {
        $module = fn (int $id, string $layer): array => ['id' => $id, 'layer' => $layer, 'lines' => 100, 'files' => 2, 'in' => 1, 'out' => 1, 'sentence' => null];

        return [
            'modules' => ['routes' => $module(1, 'entry'), 'app/Http' => $module(2, 'web'), 'app/Jobs' => $module(3, 'logic')],
            'links' => [
                ['from' => 'routes', 'to' => 'app/Http', 'weight' => 4, 'kinds' => ['route' => 4]],
                ['from' => 'app/Http', 'to' => 'app/Jobs', 'weight' => 2, 'kinds' => ['dispatch' => 2]],
            ],
            'externals' => [
                ['module' => 'app/Jobs', 'target' => 'store:database', 'kind' => 'stores', 'count' => 3],
                ['module' => 'app/Jobs', 'target' => 'store:queue', 'kind' => 'queues', 'count' => 1],
            ],
            'targets' => [
                'store:database' => ['name' => 'Database', 'type' => 'data', 'count' => 3],
                'store:queue' => ['name' => 'Job queue', 'type' => 'data', 'count' => 1],
                'service:github' => ['name' => 'GitHub', 'type' => 'external', 'count' => 1],
            ],
            'entries' => [],
        ];
    }
}

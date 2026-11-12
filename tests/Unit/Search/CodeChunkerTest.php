<?php

namespace Tests\Unit\Search;

use App\Search\CodeChunker;
use PHPUnit\Framework\TestCase;

class CodeChunkerTest extends TestCase
{
    public function test_each_method_is_one_chunk_with_its_docblock_named_after_its_class_and_the_rest_are_windows(): void
    {
        $content = <<<'PHP'
            <?php

            namespace Shop;

            class Cart
            {
                private array $items = [];

                /**
                 * Add a product.
                 */
                public function add(string $product): void
                {
                    $this->items[] = $product;
                }

                #[Pure]
                public function count(): int
                {
                    return count($this->items);
                }
            }

            PHP;

        $chunks = CodeChunker::chunk($content, [
            ['name' => 'Cart', 'start_line' => 5, 'end_line' => 22],
            ['name' => 'add', 'start_line' => 12, 'end_line' => 15],
            ['name' => 'count', 'start_line' => 18, 'end_line' => 21],
        ]);

        $this->assertSame([
            [1, 8, null],
            [9, 15, 'Cart.add'],
            [17, 21, 'Cart.count'],
        ], $this->ranges($chunks));
        $this->assertSame("    /**\n     * Add a product.\n     */\n    public function add(string \$product): void\n    {\n        \$this->items[] = \$product;\n    }", $chunks[1]['content']);
    }

    public function test_a_window_inside_a_symbol_is_named_after_it(): void
    {
        $content = implode("\n", ['class Cart', '{', '    private array $items = [];', '', '    public function add() {}', '}']);

        $chunks = CodeChunker::chunk($content, [
            ['name' => 'Cart', 'start_line' => 1, 'end_line' => 6],
            ['name' => 'add', 'start_line' => 5, 'end_line' => 5],
        ]);

        $this->assertSame([[1, 4, 'Cart'], [5, 5, 'Cart.add']], $this->ranges($chunks));
    }

    public function test_a_file_without_symbols_is_cut_into_windows_of_eighty_lines(): void
    {
        $content = implode("\n", array_map(fn (int $line): string => "Rândul {$line}", range(1, 170)));

        $chunks = CodeChunker::chunk($content, []);

        $this->assertSame([[1, 80, null], [81, 160, null], [161, 170, null]], $this->ranges($chunks));
    }

    public function test_a_symbol_longer_than_one_hundred_twenty_lines_is_cut_into_windows_that_keep_its_name(): void
    {
        $content = implode("\n", array_map(fn (int $line): string => "    \$total += {$line};", range(1, 130)));

        $chunks = CodeChunker::chunk($content, [['name' => 'sum', 'start_line' => 1, 'end_line' => 130]]);

        $this->assertSame([[1, 80, 'sum'], [81, 130, 'sum']], $this->ranges($chunks));
    }

    public function test_a_symbol_of_one_hundred_twenty_lines_stays_one_chunk(): void
    {
        $content = implode("\n", array_map(fn (int $line): string => "    \$total += {$line};", range(1, 120)));

        $chunks = CodeChunker::chunk($content, [['name' => 'sum', 'start_line' => 1, 'end_line' => 120]]);

        $this->assertSame([[1, 120, 'sum']], $this->ranges($chunks));
    }

    public function test_leaves_out_pieces_without_letters_or_digits(): void
    {
        $content = implode("\n", ['function a() {', '  return 1;', '}', '', '}', '']);

        $chunks = CodeChunker::chunk($content, [['name' => 'a', 'start_line' => 1, 'end_line' => 3]]);

        $this->assertSame([[1, 3, 'a']], $this->ranges($chunks));
    }

    public function test_two_symbols_on_the_same_lines_make_one_chunk(): void
    {
        $content = implode("\n", ['export function format() {', '  return 1;', '}']);

        $chunks = CodeChunker::chunk($content, [
            ['name' => 'format', 'start_line' => 1, 'end_line' => 3],
            ['name' => 'format', 'start_line' => 1, 'end_line' => 3],
        ]);

        $this->assertSame([[1, 3, 'format']], $this->ranges($chunks));
    }

    public function test_ignores_symbols_outside_the_file_and_cuts_an_end_past_the_last_line(): void
    {
        $content = "def a():\n    return 1\n";

        $chunks = CodeChunker::chunk($content, [
            ['name' => 'a', 'start_line' => 1, 'end_line' => 9],
            ['name' => 'ghost', 'start_line' => 40, 'end_line' => 41],
        ]);

        $this->assertSame([[1, 2, 'a']], $this->ranges($chunks));
        $this->assertSame("def a():\n    return 1", $chunks[0]['content']);
    }

    public function test_reads_lines_that_end_in_crlf(): void
    {
        $chunks = CodeChunker::chunk("# Titlu\r\n\r\nText.\r\n", []);

        $this->assertSame([[1, 3, null]], $this->ranges($chunks));
        $this->assertSame("# Titlu\n\nText.", $chunks[0]['content']);
    }

    /**
     * The chunks as [start line, end line, symbol name].
     *
     * @param  list<array{start_line: int, end_line: int, symbol_name: string|null, content: string}>  $chunks
     * @return list<array{int, int, string|null}>
     */
    private function ranges(array $chunks): array
    {
        return array_map(fn (array $chunk): array => [$chunk['start_line'], $chunk['end_line'], $chunk['symbol_name']], $chunks);
    }

    public function test_thousands_of_symbols_are_chunked_in_a_moment_not_in_seconds(): void
    {
        $count = 4000;
        $content = collect(range(1, $count))->map(fn (int $i) => "function f{$i}() {}")->implode("\n");
        $symbols = collect(range(1, $count))->map(fn (int $i) => ['name' => "f{$i}", 'start_line' => $i, 'end_line' => $i])->all();

        $start = microtime(true);
        $chunks = CodeChunker::chunk($content, $symbols);

        $this->assertCount($count, $chunks);
        // Comparing every symbol with every other one took 1 s for 4,000 symbols; one pass takes a few ms.
        $this->assertLessThan(0.5, microtime(true) - $start);
    }

    public function test_nested_symbols_are_named_after_their_smallest_parent_and_the_outer_class_keeps_only_its_own_lines(): void
    {
        $content = implode("\n", ['class A {', '  function one() {', '    return 1;', '  }', '  function two() {', '    return 2;', '  }', '}']);
        $symbols = [
            ['name' => 'A', 'start_line' => 1, 'end_line' => 8],
            ['name' => 'one', 'start_line' => 2, 'end_line' => 4],
            ['name' => 'two', 'start_line' => 5, 'end_line' => 7],
        ];

        $names = array_column(CodeChunker::chunk($content, $symbols), 'symbol_name');

        $this->assertSame(['A', 'A.one', 'A.two'], $names);
    }

    public function test_a_file_with_more_symbols_than_any_real_file_is_cut_into_plain_windows(): void
    {
        $count = CodeChunker::MAX_SYMBOLS + 1;
        $content = collect(range(1, $count))->map(fn (int $i) => "x{$i}")->implode("\n");
        $symbols = collect(range(1, $count))->map(fn (int $i) => ['name' => "f{$i}", 'start_line' => $i, 'end_line' => $i])->all();

        $chunks = CodeChunker::chunk($content, $symbols);

        $this->assertCount((int) ceil($count / CodeChunker::WINDOW_LINES), $chunks);
        $this->assertNull($chunks[0]['symbol_name']);
    }
}

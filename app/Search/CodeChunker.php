<?php

namespace App\Search;

/**
 * Cuts one file into the pieces the assistant searches and cites (code_chunks), using the symbols
 * the code analysis found in it (code_symbols, Faza 5):
 *
 * 1. every innermost symbol (a function, a method, or a class without methods: a symbol with no
 *    other symbol inside) is one chunk with its exact lines, named after its parent when it has one
 *    ("ProductController.show"); the comment or attribute lines right above it (its docblock,
 *    "#[...]", "@decorator") are included, so the explanation stays with the code;
 * 2. the lines no symbol chunk covers (imports, properties, the text of a Markdown file, ...)
 *    are cut into windows of at most 80 lines, named after the symbol that contains the whole
 *    window, if any;
 * 3. a symbol longer than 120 lines is cut into 80-line windows that keep its name, so one chunk
 *    never fills the assistant's context alone;
 * 4. pieces without any letter or digit (blank lines, a lone "}") are left out.
 */
final class CodeChunker
{
    /**
     * The longest window of lines.
     */
    public const WINDOW_LINES = 80;

    /**
     * A symbol up to this long stays one chunk; a longer one is cut into windows.
     */
    public const MAX_SYMBOL_LINES = 120;

    /**
     * A file with more symbols than this is cut into plain windows, without looking at its symbols.
     */
    public const MAX_SYMBOLS = 5000;

    /**
     * How a comment, a docblock line, an attribute or a decorator starts (after the indentation).
     */
    private const COMMENT_STARTS = ['//', '/*', '*', '#', '@', '"""', "'''"];

    /**
     * The chunks of one file, in the order of its lines.
     *
     * @param  list<array{name: string, start_line: int, end_line: int}>  $symbols  the symbols of the file (lines start at 1)
     * @return list<array{start_line: int, end_line: int, symbol_name: string|null, content: string}>
     */
    public static function chunk(string $content, array $symbols): array
    {
        $lines = self::lines($content);
        $symbols = self::validSymbols($symbols, count($lines));

        // More symbols than any real file has: plain windows are enough (and cost time linear in the lines).
        if (count($symbols) > self::MAX_SYMBOLS) {
            $symbols = [];
        }

        $chunks = [];
        $nextUncoveredLine = 1;

        foreach (self::symbolRanges($lines, $symbols) as [$start, $end, $name]) {
            array_push($chunks, ...self::windows($lines, $symbols, $nextUncoveredLine, $start - 1));
            array_push($chunks, ...self::symbolChunks($lines, $start, $end, $name));
            $nextUncoveredLine = $end + 1;
        }

        array_push($chunks, ...self::windows($lines, $symbols, $nextUncoveredLine, count($lines)));

        return $chunks;
    }

    /**
     * The lines of the file ("\n", "\r\n" or "\r"), without the empty piece after the last line break.
     *
     * @return list<string>
     */
    private static function lines(string $content): array
    {
        $lines = preg_split('/\r\n|\n|\r/', $content) ?: [];

        if ($lines !== [] && end($lines) === '') {
            array_pop($lines);
        }

        return $lines;
    }

    /**
     * The symbols whose lines exist in the file; an end past the last line is cut to it.
     *
     * @param  list<array{name: string, start_line: int, end_line: int}>  $symbols
     * @return list<array{name: string, start_line: int, end_line: int}>
     */
    private static function validSymbols(array $symbols, int $lineCount): array
    {
        $valid = [];

        foreach ($symbols as $symbol) {
            if ($symbol['start_line'] >= 1 && $symbol['start_line'] <= $lineCount && $symbol['end_line'] >= $symbol['start_line']) {
                $valid[] = [...$symbol, 'end_line' => min($symbol['end_line'], $lineCount)];
            }
        }

        return $valid;
    }

    /**
     * The line ranges of the innermost symbols, sorted, never overlapping, as [start, end, name].
     *
     * The symbols are visited once, outer ones before inner ones, with a stack of the symbols still open (the
     * ones that contain the line being visited): the symbol on top of the stack is the smallest one that contains
     * the new symbol, so it is its parent (for the name "Parent.child") and it is not innermost. Comparing every
     * symbol with every other one would take time with the square of the number of symbols.
     *
     * @param  list<string>  $lines
     * @param  list<array{name: string, start_line: int, end_line: int}>  $symbols
     * @return list<array{int, int, string}>
     */
    private static function symbolRanges(array $lines, array $symbols): array
    {
        [$parentOf, $hasSymbolInside] = self::nesting($symbols);

        $ranges = [];

        foreach ($symbols as $index => $symbol) {
            if (! isset($hasSymbolInside[$index])) {
                $parent = $parentOf[$index] ?? null;

                $ranges[] = [
                    self::startWithCommentAbove($lines, $symbol['start_line']),
                    $symbol['end_line'],
                    $parent === null ? $symbol['name'] : "{$symbols[$parent]['name']}.{$symbol['name']}",
                ];
            }
        }

        usort($ranges, fn (array $a, array $b): int => $a[0] <=> $b[0]);

        // Two symbols on the same lines (or a comment taken from the previous symbol): keep the first one's lines.
        $sorted = [];
        $lastEnd = 0;

        foreach ($ranges as [$start, $end, $name]) {
            if ($end <= $lastEnd) {
                continue;
            }

            $sorted[] = [max($start, $lastEnd + 1), $end, $name];
            $lastEnd = $end;
        }

        return $sorted;
    }

    /**
     * Which symbol contains which, in one pass: the index of the smallest symbol that contains each symbol (its
     * parent; a symbol on exactly the same lines is a duplicate, not a parent), and the set of the symbols that
     * have another symbol inside. Two symbols on exactly the same lines: the first one counts as the outer one,
     * so exactly one of them is innermost.
     *
     * @param  list<array{name: string, start_line: int, end_line: int}>  $symbols
     * @return array{array<int, int>, array<int, true>} parents by index, and the indexes that contain a symbol
     */
    private static function nesting(array $symbols): array
    {
        $order = array_keys($symbols);

        // Outer symbols first: earlier start, then later end, then the order they were found in.
        usort($order, fn (int $a, int $b): int => [$symbols[$a]['start_line'], $symbols[$b]['end_line'], $a] <=> [$symbols[$b]['start_line'], $symbols[$a]['end_line'], $b]);

        $open = [];
        $parentOf = [];
        $hasSymbolInside = [];

        foreach ($order as $index) {
            $symbol = $symbols[$index];

            while ($open !== [] && $symbols[end($open)]['end_line'] < $symbol['start_line']) {
                array_pop($open);
            }

            $parentLength = null;

            for ($position = count($open) - 1; $position >= 0; $position--) {
                $outer = $symbols[$open[$position]];

                if ($outer['end_line'] < $symbol['end_line']) {
                    continue; // starts before and ends inside: overlaps, does not contain
                }

                $hasSymbolInside[$open[$position]] = true;

                // The parent is the smallest container; one on exactly the same lines is a duplicate, not a parent.
                $isDuplicate = $outer['start_line'] === $symbol['start_line'] && $outer['end_line'] === $symbol['end_line'];
                $length = $outer['end_line'] - $outer['start_line'];

                if (! $isDuplicate && ($parentLength === null || $length < $parentLength || ($length === $parentLength && $open[$position] < $parentOf[$index]))) {
                    $parentOf[$index] = $open[$position];
                    $parentLength = $length;
                }
            }

            $open[] = $index;
        }

        return [$parentOf, $hasSymbolInside];
    }

    /**
     * The smallest symbol that contains the lines from $start to $end, or null (used for the name of a window,
     * and a file has few windows).
     *
     * @param  list<array{name: string, start_line: int, end_line: int}>  $symbols
     * @return array{name: string, start_line: int, end_line: int}|null
     */
    private static function smallestContaining(array $symbols, int $start, int $end): ?array
    {
        $smallest = null;

        foreach ($symbols as $symbol) {
            if ($symbol['start_line'] > $start || $symbol['end_line'] < $end) {
                continue;
            }

            if ($smallest === null || $symbol['end_line'] - $symbol['start_line'] < $smallest['end_line'] - $smallest['start_line']) {
                $smallest = $symbol;
            }
        }

        return $smallest;
    }

    /**
     * The first line of a symbol's chunk: its first line, moved up over the comment lines right above it.
     *
     * @param  list<string>  $lines
     */
    private static function startWithCommentAbove(array $lines, int $startLine): int
    {
        // $lines starts at index 0, so line N is $lines[N - 1] and the line above it is $lines[N - 2].
        while ($startLine > 1 && self::isComment($lines[$startLine - 2])) {
            $startLine--;
        }

        return $startLine;
    }

    /**
     * Whether a line is a comment, a docblock line, an attribute or a decorator.
     */
    private static function isComment(string $line): bool
    {
        $trimmed = ltrim($line);

        foreach (self::COMMENT_STARTS as $start) {
            if (str_starts_with($trimmed, $start)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The chunks of one symbol: the whole symbol, or 80-line windows with its name when it is too long.
     *
     * @param  list<string>  $lines
     * @return list<array{start_line: int, end_line: int, symbol_name: string|null, content: string}>
     */
    private static function symbolChunks(array $lines, int $start, int $end, string $name): array
    {
        $windowLength = $end - $start + 1 <= self::MAX_SYMBOL_LINES ? self::MAX_SYMBOL_LINES : self::WINDOW_LINES;

        $chunks = [];

        for ($windowStart = $start; $windowStart <= $end; $windowStart += $windowLength) {
            $chunk = self::piece($lines, $windowStart, min($windowStart + $windowLength - 1, $end), $name);

            if ($chunk !== null) {
                $chunks[] = $chunk;
            }
        }

        return $chunks;
    }

    /**
     * The 80-line windows of the lines from $start to $end (none when $start > $end), each named
     * after the smallest symbol that contains the whole window.
     *
     * @param  list<string>  $lines
     * @param  list<array{name: string, start_line: int, end_line: int}>  $symbols
     * @return list<array{start_line: int, end_line: int, symbol_name: string|null, content: string}>
     */
    private static function windows(array $lines, array $symbols, int $start, int $end): array
    {
        $chunks = [];

        for ($windowStart = $start; $windowStart <= $end; $windowStart += self::WINDOW_LINES) {
            $windowEnd = min($windowStart + self::WINDOW_LINES - 1, $end);
            $chunk = self::piece($lines, $windowStart, $windowEnd, self::smallestContaining($symbols, $windowStart, $windowEnd)['name'] ?? null);

            if ($chunk !== null) {
                $chunks[] = $chunk;
            }
        }

        return $chunks;
    }

    /**
     * One chunk with the lines from $start to $end, or null when they hold no letter or digit.
     *
     * @param  list<string>  $lines
     * @return array{start_line: int, end_line: int, symbol_name: string|null, content: string}|null
     */
    private static function piece(array $lines, int $start, int $end, ?string $name): ?array
    {
        $content = implode("\n", array_slice($lines, $start - 1, $end - $start + 1));

        if (preg_match('/[\p{L}\p{N}]/u', $content) !== 1) {
            return null;
        }

        return ['start_line' => $start, 'end_line' => $end, 'symbol_name' => $name, 'content' => $content];
    }
}

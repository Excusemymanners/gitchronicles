<?php

namespace Tests\Unit\Documentation;

use App\Documentation\LinkGuard;
use App\Documentation\SourceLinks;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Links and images in text written by Claude: only the repository's own GitHub addresses, pages of the app
 * and anchors stay links (security audit AI-LAYER-06).
 */
class LinkGuardTest extends TestCase
{
    private function links(): SourceLinks
    {
        return new SourceLinks('acme', 'magazin', 'abc123');
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function markdownAndItsCleanedForm(): array
    {
        return [
            'a file of the repository stays' => ['[User](https://github.com/acme/magazin/blob/abc123/app/User.php#L1-L9)', '[User](https://github.com/acme/magazin/blob/abc123/app/User.php#L1-L9)'],
            'a folder of the repository stays' => ['[app](https://github.com/acme/magazin/tree/abc123/app)', '[app](https://github.com/acme/magazin/tree/abc123/app)'],
            'a page of the app and an anchor stay' => ['[Models](/repositories/7/docs/models) [up](#top)', '[Models](/repositories/7/docs/models) [up](#top)'],
            'a link to another site becomes its text' => ['See [the docs](https://evil.example/phish).', 'See the docs.'],
            'a link to another repository becomes text' => ['[x](https://github.com/acme/magazin-evil/blob/x/a.php)', 'x'],
            'an image becomes its description' => ['![pixel](https://evil.example/p.png?q=secret)', 'pixel'],
            'a protocol-relative and a javascript link become text' => ['[a](//evil.example) [b](javascript:alert(1))', 'a b)'],
            'an autolink and a bare address become code' => ['<https://evil.example/x> and https://evil.example/y and www.evil.example', '`https://evil.example/x` and `https://evil.example/y` and `www.evil.example`'],
            'a reference definition is removed' => ["[x][1]\n\n[1]: https://evil.example/z\n", "[x][1]\n\n"],
            'a link around an image leaves nothing to render' => ['[![a](https://evil.example/i.png)](https://evil.example/t)', 'a'],
            'code is not touched' => ["```bash\ncurl https://evil.example/x\n```\n`https://evil.example`", "```bash\ncurl https://evil.example/x\n```\n`https://evil.example`"],
        ];
    }

    #[DataProvider('markdownAndItsCleanedForm')]
    public function test_cleans_a_link_that_is_not_allowed(string $markdown, string $cleaned): void
    {
        $this->assertSame($cleaned, (new LinkGuard)->clean($markdown, $this->links()));
    }

    public function test_counts_what_it_removed_and_the_rendered_page_has_no_foreign_address_left(): void
    {
        $guard = new LinkGuard;
        $markdown = '[a](https://evil.example/1) ![b](https://evil.example/2) https://evil.example/3 [ok](#x)';

        $this->assertNotSame([], $guard->unsafeAddresses($markdown, $this->links()));

        $removed = 0;
        $cleaned = $guard->clean($markdown, $this->links(), $removed);

        $this->assertSame(3, $removed);
        $this->assertSame([], $guard->unsafeAddresses($cleaned, $this->links()));
    }
}

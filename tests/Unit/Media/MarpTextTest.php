<?php

namespace Tests\Unit\Media;

use App\Media\MarpText;
use PHPUnit\Framework\TestCase;

class MarpTextTest extends TestCase
{
    public function test_html_and_markdown_syntax_from_a_repository_is_escaped(): void
    {
        $this->assertSame(
            '&lt;style\>section \{ color\: red \}&lt;/style\> a\|b \*bold\* \_i\_ \[link\]\(http\://x\) \:smile\: \$x\$ \#tag &amp; \`cod\`',
            MarpText::text('<style>section { color: red }</style> a|b *bold* _i_ [link](http://x) :smile: $x$ #tag & `cod`'),
        );
    }

    public function test_a_text_cannot_start_a_list_a_new_slide_or_span_several_lines(): void
    {
        $this->assertSame('\---', MarpText::text('---'));
        $this->assertSame('\- element', MarpText::text('- element'));
        $this->assertSame('1\. primul', MarpText::text('1. primul'));
        $this->assertSame('unu --- doi', MarpText::text("unu\n---\ndoi"));
    }

    public function test_a_text_is_cleaned_and_cut_at_a_word(): void
    {
        $this->assertSame('Știință și țară', MarpText::text('Ştiinţă şi ţară 🚀'));
        $this->assertSame('unu doi…', MarpText::text('unu doi trei patru', 10));
        $this->assertSame('', MarpText::text(null));
    }

    public function test_a_command_is_kept_as_it_is_unless_it_could_end_the_code(): void
    {
        $this->assertSame('npm run build -- --watch', MarpText::code(' npm run build -- --watch '));
        $this->assertNull(MarpText::code('echo `whoami`'));
        $this->assertNull(MarpText::code('  '));
    }
}

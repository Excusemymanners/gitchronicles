<?php

namespace Tests\Unit;

use App\Documentation\ReadmeImages;
use PHPUnit\Framework\TestCase;

/**
 * What the README parser finds: pictures with their section, descriptive sections, and what it skips.
 */
class ReadmeImagesTest extends TestCase
{
    private const README = <<<'MD'
# Acme

[![Build](https://img.shields.io/github/actions/workflow/status/acme/acme/ci.yml)](https://example.com)
![Logo](docs/logo.png)

An intro line.

## Features

- Fast
<img src="https://raw.githubusercontent.com/acme/acme/main/shot.png" alt="A screenshot" width="600">
<img src="https://evil.example.net/pixel.png" width="1" height="1">
<img src="https://tracker.example.net/big.png" alt="Elsewhere">

## Installation

```bash
![not a picture](ignored.png)
```

## Demo

![Demo animation](./media/demo.gif "The demo")
![](data:image/png;base64,AAAA)

## License

MIT
MD;

    public function test_it_finds_pictures_with_their_section_and_skips_badges_pixels_and_code(): void
    {
        $sources = array_column(ReadmeImages::pictures(self::README), 'source');

        $this->assertSame([
            'docs/logo.png',
            'https://raw.githubusercontent.com/acme/acme/main/shot.png',
            'https://tracker.example.net/big.png',
            './media/demo.gif',
        ], $sources);
    }

    public function test_a_picture_knows_its_alt_text_and_the_heading_it_appears_under(): void
    {
        $pictures = ReadmeImages::pictures(self::README);

        $this->assertSame(['alt' => 'A screenshot', 'heading' => 'Features'], ['alt' => $pictures[1]['alt'], 'heading' => $pictures[1]['heading']]);
        $this->assertSame('Acme', $pictures[0]['heading']);
        $this->assertSame('Demo', $pictures[3]['heading']);
    }

    public function test_it_keeps_only_the_sections_that_describe_the_project(): void
    {
        $sections = ReadmeImages::sections(self::README);

        $this->assertSame(['Acme', 'Features', 'Demo'], array_column($sections, 'heading'));
        $this->assertStringContainsString('An intro line.', $sections[0]['text']);
        $this->assertStringNotContainsString('![', $sections[0]['text']);
        $this->assertSame(['docs/logo.png'], $sections[0]['sources']);
        $this->assertSame(['./media/demo.gif'], $sections[2]['sources']);
    }
}

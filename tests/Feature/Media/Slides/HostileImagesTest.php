<?php

namespace Tests\Feature\Media\Slides;

use App\Media\Slides\SlideIdentity;
use App\Models\Repository;
use App\Support\SafeDownload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Images named by a repository (the README's logo, an avatar address) are downloaded by this server, so they
 * must never reach an internal address, an endless answer, a page that is not an image or a picture made to fill
 * the memory when it is decoded.
 */
class HostileImagesTest extends TestCase
{
    use RefreshDatabase;

    private const HOSTS = ['github.com', '*.githubusercontent.com'];

    public function test_addresses_inside_the_network_and_hosts_outside_the_list_are_refused_without_a_request(): void
    {
        Http::fake();

        foreach ([
            'http://raw.githubusercontent.com/a/b/logo.png',
            'https://127.0.0.1/logo.png',
            'https://[::1]/logo.png',
            'https://169.254.169.254/latest/meta-data/',
            'https://localhost/logo.png',
            'https://evil.example/logo.png',
            'https://github.com.evil.example/logo.png',
            'https://user:pass@github.com/logo.png',
            'https://github.com:8443/logo.png',
            'file:///etc/passwd',
            'ftp://github.com/logo.png',
        ] as $address) {
            $this->assertNull(SafeDownload::image($address, self::HOSTS, 1000), $address);
        }

        Http::assertNothingSent();
    }

    public function test_an_allowed_host_that_redirects_to_an_internal_address_is_not_followed(): void
    {
        Http::fake([
            'raw.githubusercontent.com/*' => Http::response('', 302, ['Location' => 'https://169.254.169.254/latest/meta-data/']),
            '169.254.169.254/*' => Http::response('secret', 200, ['Content-Type' => 'image/png']),
        ]);

        $this->assertNull(SafeDownload::image('https://raw.githubusercontent.com/a/b/logo.png', self::HOSTS, 1000));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '169.254.169.254'));
    }

    public function test_a_redirect_between_allowed_hosts_is_followed(): void
    {
        Http::fake([
            'github.com/*' => Http::response('', 302, ['Location' => 'https://avatars.githubusercontent.com/u/1']),
            'avatars.githubusercontent.com/*' => Http::response($this->png(10, 10), 200, ['Content-Type' => 'image/png']),
        ]);

        $this->assertSame($this->png(10, 10), SafeDownload::image('https://github.com/octocat.png', self::HOSTS, 10_000));
    }

    public function test_an_answer_over_the_size_limit_or_that_is_not_an_image_is_refused(): void
    {
        Http::fake([
            'github.com/big.png' => Http::response(str_repeat('A', 5000), 200, ['Content-Type' => 'image/png']),
            'github.com/page' => Http::response('<html></html>', 200, ['Content-Type' => 'text/html']),
        ]);

        $this->assertNull(SafeDownload::image('https://github.com/big.png', self::HOSTS, 1000));
        $this->assertNull(SafeDownload::image('https://github.com/page', self::HOSTS, 1000));
    }

    public function test_a_picture_that_claims_too_many_pixels_and_a_dangerous_svg_are_not_safe_images(): void
    {
        $this->assertTrue(SlideIdentity::isSafeImage($this->png(64, 64)));
        $this->assertFalse(SlideIdentity::isSafeImage($this->png(60_000, 60_000)));
        $this->assertTrue(SlideIdentity::isSafeImage('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><rect width="10" height="10"/></svg>'));
        $this->assertFalse(SlideIdentity::isSafeImage('<svg xmlns="http://www.w3.org/2000/svg"><script>fetch("/")</script></svg>'));
        $this->assertFalse(SlideIdentity::isSafeImage('<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"></svg>'));
        $this->assertFalse(SlideIdentity::isSafeImage('<svg xmlns="http://www.w3.org/2000/svg"><image href="file:///etc/passwd"/></svg>'));
        $this->assertFalse(SlideIdentity::isSafeImage('<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY a "aaaa">]><svg xmlns="http://www.w3.org/2000/svg">&a;</svg>'));
        $this->assertFalse(SlideIdentity::isSafeImage('<svg xmlns="http://www.w3.org/2000/svg"><foreignObject><iframe src="https://x"/></foreignObject></svg>'));
    }

    public function test_a_readme_whose_logo_is_on_an_internal_address_makes_no_request_for_it(): void
    {
        Storage::fake('local');
        Http::fake(['*' => Http::response('', 404)]);
        Process::fake(['*' => Process::result(output: '[]')]);
        $repository = Repository::factory()->ready()->create(['owner' => 'acme', 'name' => 'magazin']);
        File::ensureDirectoryExists($repository->clonePath());
        File::put($repository->clonePath().'/README.md', "# magazin\n<img src=\"https://169.254.169.254/logo.png\" alt=\"logo\">\n![logo](http://10.0.0.5/admin/logo.png)\n");

        $identity = app(SlideIdentity::class)->collect($repository, ['avatar_url' => 'https://127.0.0.1/avatar.png']);

        $this->assertNull($identity['logo']);
        $this->assertNull($identity['avatar']);
        Http::assertNothingSent();
    }

    public function test_a_logo_that_is_a_link_out_of_the_clone_is_not_embedded(): void
    {
        Storage::fake('local');
        Http::fake();
        Process::fake(['*' => Process::result(output: '[]')]);
        $repository = Repository::factory()->ready()->create(['owner' => 'acme', 'name' => 'magazin']);
        $outside = sys_get_temp_dir().'/gc-logo-'.bin2hex(random_bytes(4)).'.png';
        File::put($outside, $this->png(10, 10));
        File::ensureDirectoryExists($repository->clonePath());
        symlink($outside, $repository->clonePath().'/logo.png');
        File::put($repository->clonePath().'/README.md', "![logo](logo.png)\n");

        $identity = app(SlideIdentity::class)->collect($repository, null);

        $this->assertNull($identity['logo']);
        File::delete($outside);
    }

    /**
     * A PNG file with only the header of the given size (the checksum is right; the picture itself is missing).
     */
    private function png(int $width, int $height): string
    {
        $header = pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0);

        return "\x89PNG\r\n\x1a\n".pack('N', 13).'IHDR'.$header.pack('N', crc32('IHDR'.$header));
    }
}

<?php

namespace Tests\Feature\Consulting;

use App\Consulting\Runtime\RuntimeVersionCheck;
use App\Consulting\ToolRun;
use App\Enums\FindingSeverity;
use App\Enums\ScanToolStatus;
use App\Models\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FakesSupportData;
use Tests\TestCase;

/**
 * The version check: the status of each declared version from the (faked) public sources, the findings,
 * the offline state and what is sent out.
 */
class RuntimeVersionCheckTest extends TestCase
{
    use FakesSupportData;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-12 12:00:00');
        $this->fakeSupportData();
    }

    public function test_an_end_of_life_php_is_a_high_finding_that_says_where_and_what_to_change_it_to(): void
    {
        $run = $this->check(['composer.json' => "{\n  \"require\": {\n    \"php\": \"^7.4\"\n  }\n}\n"]);

        $this->assertSame(ScanToolStatus::Ran, $run->status);
        $row = $run->details['versions'][0];
        $this->assertSame(['end_of_life', '7.4', '7.4', true, 'composer.json', 3, '2022-11-28'], [$row['status'], $row['release_line'], $row['version'], $row['is_range'], $row['path'], $row['line'], $row['supported_until']]);
        $this->assertSame('8.5', $row['target']);

        $finding = $run->findings[0];
        $this->assertSame(FindingSeverity::High, $finding->severity);
        $this->assertSame(['runtime', 'composer.json', 3], [$finding->tool, $finding->path, $finding->startLine]);
        $this->assertSame('PHP 7.4 is past end of life', $finding->title);
        $this->assertStringContainsString('PHP 7.4 has had no security fixes since 28 Nov 2022.', $finding->message);
        $this->assertStringContainsString('Declared in composer.json line 3 as "^7.4".', $finding->message);
        $this->assertStringContainsString('lowest version it allows, 7.4', $finding->message);
        $this->assertStringContainsString('Change it to 8.5 (newest release 8.5.12) or later.', $finding->message);
        $this->assertSame('8.5.12', $finding->fixedVersion);
    }

    public function test_a_pinned_node_behind_its_line_counts_the_security_releases_it_misses(): void
    {
        $run = $this->check(['.nvmrc' => "22.20.0\n"]);

        $row = $run->details['versions'][0];
        $this->assertSame(['behind', '22', '22.23.5'], [$row['status'], $row['release_line'], $row['newest']]);
        $this->assertSame(6, $row['missing_security_releases']);

        $finding = $run->findings[0];
        $this->assertSame(FindingSeverity::Medium, $finding->severity);
        $this->assertSame('Node.js 22.20.0 misses 6 security releases', $finding->title);
        $this->assertStringContainsString('Change it to 22.23.5.', $finding->message);
    }

    public function test_node_20_is_end_of_life_and_the_target_is_the_newest_lts_line(): void
    {
        $run = $this->check(['Dockerfile' => "FROM node:20-alpine\n"]);

        $this->assertSame('end_of_life', $run->details['versions'][0]['status']);
        $this->assertSame('26', $run->details['versions'][0]['target']);
        $this->assertStringContainsString('Dockerfile line 1', $run->findings[0]->message);
    }

    public function test_a_range_that_is_not_behind_is_judged_by_its_lowest_version_and_a_supported_line_is_a_row_without_a_finding(): void
    {
        $run = $this->check(['composer.json' => json_encode(['require' => ['php' => '^8.5']])]);

        $this->assertSame([], $run->findings);
        $this->assertSame(['current', '2029-12-31', '8.5.12'], [$run->details['versions'][0]['status'], $run->details['versions'][0]['supported_until'], $run->details['versions'][0]['newest']]);
    }

    public function test_a_line_with_security_fixes_only_is_a_low_finding(): void
    {
        $run = $this->check(['composer.json' => json_encode(['require' => ['php' => '^8.3']])]);

        $this->assertSame('security_only', $run->details['versions'][0]['status']);
        $this->assertSame(FindingSeverity::Low, $run->findings[0]->severity);
        $this->assertStringContainsString('only security fixes, until 31 Dec 2027', $run->findings[0]->message);
    }

    public function test_frameworks_come_from_the_lock_file_and_an_old_one_is_end_of_life(): void
    {
        $run = $this->check([
            'composer.lock' => "{\n \"packages\": [\n  {\n   \"name\": \"laravel/framework\",\n   \"version\": \"v10.48.0\"\n  }\n ]\n}\n",
        ]);

        $this->assertSame(['laravel', 'end_of_life', 'composer.lock', 4], [$run->details['versions'][0]['product'], $run->details['versions'][0]['status'], $run->details['versions'][0]['path'], $run->details['versions'][0]['line']]);
        $this->assertSame(FindingSeverity::High, $run->findings[0]->severity);
    }

    public function test_a_framework_behind_its_line_is_only_a_low_finding(): void
    {
        $run = $this->check(['composer.lock' => json_encode(['packages' => [['name' => 'laravel/framework', 'version' => 'v13.0.0']]], JSON_PRETTY_PRINT)]);

        $this->assertSame('behind', $run->details['versions'][0]['status']);
        $this->assertSame(FindingSeverity::Low, $run->findings[0]->severity);
    }

    public function test_a_release_line_the_source_does_not_list_is_unknown_and_a_product_it_does_not_know_is_unchecked(): void
    {
        $run = $this->check([
            '.python-version' => "3.8.1\n",
            'go.mod' => "module x\n\ngo 1.22\n",
        ]);

        $statuses = collect($run->details['versions'])->pluck('status', 'product')->all();
        $this->assertSame(['python' => 'unknown', 'go' => 'unchecked'], $statuses);
        $this->assertSame('http_error', collect($run->details['versions'])->firstWhere('product', 'go')['reason']);
        $this->assertSame(ScanToolStatus::Ran, $run->status);
        $this->assertSame([], $run->findings);
    }

    public function test_offline_the_check_says_it_could_not_check_and_keeps_the_declared_versions(): void
    {
        $this->fakeSupportData(unreachable: true);

        $run = $this->check(['composer.json' => json_encode(['require' => ['php' => '^7.4']])]);

        $this->assertSame([ScanToolStatus::Failed, 'source_unavailable', 0], [$run->status, $run->error, $run->errorValue]);
        $this->assertSame([], $run->findings);
        $this->assertSame(['unchecked', 'unreachable', '7.4'], [$run->details['versions'][0]['status'], $run->details['versions'][0]['reason'], $run->details['versions'][0]['version']]);
        $this->assertNull($run->details['fetched_at']);
    }

    public function test_the_same_problem_in_several_files_is_one_finding_that_names_the_other_places_but_every_file_keeps_its_row(): void
    {
        $run = $this->check([
            'a/package.json' => json_encode(['engines' => ['node' => '>=22']]),
            'b/package.json' => json_encode(['engines' => ['node' => '>=22']]),
            'Dockerfile' => "FROM node:22.20.0-slim@sha256:abc123\n",
            'Dockerfile.test' => "FROM node:22.20.0-slim\n",
        ]);

        $this->assertCount(4, $run->details['versions']);
        $this->assertCount(2, $run->findings);
        $this->assertSame(['behind', 'behind', 'security_only', 'security_only'], collect($run->details['versions'])->pluck('status')->all());
        $this->assertSame('Node.js 22.20.0 misses 6 security releases', $run->findings[0]->title);
        $this->assertStringContainsString('Declared in Dockerfile line 1 as "node:22.20.0-slim".', $run->findings[0]->message);
        $this->assertStringContainsString('also declared in Dockerfile.test:1.', $run->findings[0]->message);
        $this->assertStringContainsString('also declared in b/package.json.', $run->findings[1]->message);
    }

    public function test_one_missed_security_release_is_not_plural(): void
    {
        $run = $this->check(['.nvmrc' => "22.23.3\n"]);

        $this->assertSame('Node.js 22.23.3 misses 2 security releases', $run->findings[0]->title);

        $run = $this->check(['.nvmrc' => "22.23.4\n"]);

        $this->assertSame('Node.js 22.23.4 misses 1 security release', $run->findings[0]->title);
    }

    public function test_nothing_is_asked_when_the_repository_declares_no_version(): void
    {
        $run = $this->check(['README.md' => '# hi']);

        $this->assertSame([ScanToolStatus::Ran, [], null], [$run->status, $run->details['versions'], $run->details['fetched_at']]);
        Http::assertNothingSent();
    }

    public function test_only_the_product_name_is_sent_with_a_generic_client_name_and_answers_are_kept_for_a_day(): void
    {
        $repository = Repository::factory()->ready()->create(['owner' => 'secret-owner', 'name' => 'secret-name']);
        $this->writeClone($repository, ['composer.json' => json_encode(['require' => ['php' => '^8.5']])]);

        app(RuntimeVersionCheck::class)->run($repository);
        $sent = Http::recorded()->count();
        app(RuntimeVersionCheck::class)->run($repository);

        $this->assertSame($sent, Http::recorded()->count());
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://endoflife.date/api/v1/products/php/'
            && $request->header('User-Agent') === ['GitChronicle-version-check']);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'secret'));

        Carbon::setTestNow(now()->addHours(25));
        app(RuntimeVersionCheck::class)->run($repository);

        $this->assertGreaterThan($sent, Http::recorded()->count());
    }

    /**
     * @param  array<string, string>  $files
     */
    private function check(array $files): ToolRun
    {
        $repository = Repository::factory()->ready()->create();
        $this->writeClone($repository, $files);

        return app(RuntimeVersionCheck::class)->run($repository);
    }
}

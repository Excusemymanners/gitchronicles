<?php

namespace Tests\Feature\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The scheduled cleaning of the database cache (security audit WEB-LAYER-12).
 */
class PruneExpiredCacheTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_deletes_only_the_expired_rows_of_the_cache_and_of_its_locks(): void
    {
        config(['cache.default' => 'database']);
        DB::table('cache')->insert([
            ['key' => 'old', 'value' => 's:1:"x";', 'expiration' => time() - 10],
            ['key' => 'new', 'value' => 's:1:"x";', 'expiration' => time() + 600],
        ]);
        DB::table('cache_locks')->insert([
            ['key' => 'old-lock', 'owner' => 'a', 'expiration' => time() - 10],
            ['key' => 'new-lock', 'owner' => 'b', 'expiration' => time() + 600],
        ]);

        $this->artisan('cache:prune-expired')->expectsOutput('Deleted 2 expired cache rows.')->assertSuccessful();

        $this->assertSame(['new'], DB::table('cache')->pluck('key')->all());
        $this->assertSame(['new-lock'], DB::table('cache_locks')->pluck('key')->all());
    }

    public function test_it_does_nothing_for_another_cache_store(): void
    {
        config(['cache.default' => 'array']);

        $this->artisan('cache:prune-expired')->expectsOutput('The cache store is not the database: nothing to prune.')->assertSuccessful();
    }

    public function test_it_runs_once_a_day_in_the_scheduler(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event): bool => str_contains($event->command, 'cache:prune-expired'));

        $this->assertNotNull($event);
        $this->assertSame('0 0 * * *', $event->expression);
    }
}

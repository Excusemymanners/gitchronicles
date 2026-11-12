<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Deletes the expired rows of the database cache store:
 *
 *     php artisan cache:prune-expired
 *
 * The "database" cache store removes an expired row only when the same key is read again, so every key that
 * is never read again (a rate limiter key for one visitor, a lock) would stay in the table for ever. The
 * Laravel Scheduler runs this command once a day (routes/console.php). With another cache store it does
 * nothing: Redis and the file store expire their entries themselves. Security audit WEB-LAYER-12.
 */
#[Signature('cache:prune-expired')]
#[Description('Delete the expired rows of the database cache store and of its locks')]
class PruneExpiredCache extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $store = config('cache.stores.'.config('cache.default'));

        if (($store['driver'] ?? null) !== 'database') {
            $this->info('The cache store is not the database: nothing to prune.');

            return self::SUCCESS;
        }

        $connection = DB::connection($store['connection'] ?? null);
        $deleted = $connection->table($store['table'])->where('expiration', '<=', time())->delete()
            + $connection->table($store['lock_table'] ?? 'cache_locks')->where('expiration', '<=', time())->delete();

        $this->info("Deleted {$deleted} expired cache rows.");

        return self::SUCCESS;
    }
}

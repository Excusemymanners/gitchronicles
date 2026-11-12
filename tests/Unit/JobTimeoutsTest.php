<?php

namespace Tests\Unit;

use Illuminate\Contracts\Queue\ShouldQueue;
use ReflectionClass;
use Tests\TestCase;

/**
 * A job that runs longer than the queue's retry_after is handed to a second worker while the first one still works
 * on it: a hostile repository that makes one step slow would then be processed twice at once. Every job must stop
 * (its $timeout) before that.
 */
class JobTimeoutsTest extends TestCase
{
    public function test_every_job_times_out_before_the_queue_hands_it_to_another_worker(): void
    {
        $retryAfter = (int) config('queue.connections.database.retry_after');
        $jobs = 0;

        foreach (glob(app_path('Jobs/*.php')) as $file) {
            $class = new ReflectionClass('App\\Jobs\\'.basename($file, '.php'));

            if (! $class->implementsInterface(ShouldQueue::class) || ! $class->hasProperty('timeout')) {
                continue;
            }

            $jobs++;
            $timeout = $class->getProperty('timeout')->getDefaultValue();

            $this->assertLessThan($retryAfter, $timeout, "{$class->getShortName()} runs up to {$timeout} s, the queue retries after {$retryAfter} s.");
        }

        $this->assertGreaterThanOrEqual(8, $jobs);
    }
}

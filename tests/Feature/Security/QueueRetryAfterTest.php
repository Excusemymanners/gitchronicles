<?php

namespace Tests\Feature\Security;

use ReflectionClass;
use Tests\TestCase;

/**
 * A job still running when the queue's retry_after passes is handed to a second worker, and with one try it
 * is then marked failed while the first worker is still working (security audit WEB-LAYER-11).
 */
class QueueRetryAfterTest extends TestCase
{
    public function test_the_queue_waits_longer_than_the_slowest_job_runs(): void
    {
        $timeouts = [];

        foreach (glob(app_path('Jobs/*.php')) as $file) {
            $class = 'App\\Jobs\\'.basename($file, '.php');

            if ((new ReflectionClass($class))->hasProperty('timeout')) {
                $timeouts[$class] = (new ReflectionClass($class))->getProperty('timeout')->getDefaultValue();
            }
        }

        $this->assertGreaterThan(1000, max($timeouts), 'The video render has the longest timeout.');
        $this->assertGreaterThan(max($timeouts), config('queue.connections.database.retry_after'));
    }
}

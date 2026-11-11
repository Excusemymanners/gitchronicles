<?php

namespace Tests\Feature\Discover;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/**
 * The daily run of `discover:refresh` in the Laravel Scheduler (routes/console.php).
 */
class DiscoverScheduleTest extends TestCase
{
    public function test_discover_refresh_is_scheduled_once_a_day_without_overlapping(): void
    {
        $event = $this->discoverEvent();

        $this->assertSame('0 3 * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertTrue($event->filtersPass($this->app));
    }

    public function test_the_daily_run_can_be_turned_off_from_the_config(): void
    {
        config(['gitchronicle.discover.schedule_enabled' => false]);

        $this->assertFalse($this->discoverEvent()->filtersPass($this->app));
    }

    /**
     * The scheduled event of `discover:refresh`.
     */
    private function discoverEvent(): Event
    {
        $events = collect($this->app->make(Schedule::class)->events())
            ->filter(fn (Event $event): bool => str_contains((string) $event->command, 'discover:refresh'));

        $this->assertCount(1, $events);

        return $events->first();
    }
}

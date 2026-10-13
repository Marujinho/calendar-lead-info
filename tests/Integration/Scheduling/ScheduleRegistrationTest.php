<?php

declare(strict_types=1);

namespace Tests\Integration\Scheduling;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The three schedule entries are registered up front (routes/console.php) so
 * feature branches only add the commands. Every entry runs on one server and
 * never overlaps itself (PRD §14, NFR-1).
 */
final class ScheduleRegistrationTest extends TestCase
{
    #[Test]
    public function the_three_commands_are_scheduled_with_locks(): void
    {
        $events = collect($this->app->make(Schedule::class)->events())
            ->keyBy(fn (Event $e): string => (string) preg_replace('/^.*artisan[\'"]? /', '', $e->command ?? ''));

        foreach (['calendar:dispatch-sync', 'digest:dispatch-due', 'meetings:purge'] as $command) {
            self::assertTrue($events->has($command), sprintf('%s is not scheduled', $command));
            $event = $events->get($command);
            self::assertInstanceOf(Event::class, $event);
            self::assertTrue($event->onOneServer, sprintf('%s must run on one server', $command));
            self::assertTrue($event->withoutOverlapping, sprintf('%s must not overlap', $command));
        }

        self::assertSame('* * * * *', $events->get('calendar:dispatch-sync')?->expression);
        self::assertSame('* * * * *', $events->get('digest:dispatch-due')?->expression);
    }
}

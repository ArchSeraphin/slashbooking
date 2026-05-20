<?php
declare(strict_types=1);

namespace Trinity\Booking\Tests\Unit\Google;

use PHPUnit\Framework\TestCase;
use Trinity\Booking\Google\PushScheduler;

final class PushSchedulerTest extends TestCase
{
    public function test_on_created_enqueues_create_action(): void
    {
        $calls = [];
        $enqueue = function (string $hook, array $args) use (&$calls): void {
            $calls[] = [$hook, $args];
        };
        $scheduler = new PushScheduler($enqueue);
        $scheduler->onCreated(42);

        self::assertSame([['tb/push_gcal_event', [42, 'create']]], $calls);
    }

    public function test_on_confirmed_enqueues_confirm(): void
    {
        $calls = [];
        $scheduler = new PushScheduler(function (string $hook, array $args) use (&$calls): void {
            $calls[] = [$hook, $args];
        });
        $scheduler->onConfirmed(42);
        self::assertSame([['tb/push_gcal_event', [42, 'confirm']]], $calls);
    }

    public function test_on_rejected_and_cancelled_enqueue_delete(): void
    {
        $calls = [];
        $enq = function (string $hook, array $args) use (&$calls): void {
            $calls[] = [$hook, $args];
        };
        $s = new PushScheduler($enq);
        $s->onRejected(42);
        $s->onCancelled(43);
        self::assertSame([
            ['tb/push_gcal_event', [42, 'delete']],
            ['tb/push_gcal_event', [43, 'delete']],
        ], $calls);
    }
}

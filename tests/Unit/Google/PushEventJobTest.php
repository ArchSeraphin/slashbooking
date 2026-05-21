<?php
declare(strict_types=1);

namespace Slash\Booking\Tests\Unit\Google;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Slash\Booking\Domain\Booking;
use Slash\Booking\Domain\GoogleAccount;
use Slash\Booking\Domain\Service;
use Slash\Booking\Domain\TimeSlot;
use Slash\Booking\Google\EventFormatter;
use Slash\Booking\Google\PushEventJob;
use Slash\Booking\Tests\Unit\Support\FakeCalendarGateway;

final class PushEventJobTest extends TestCase
{
    private FakeCalendarGateway $gateway;
    private EventFormatter $formatter;

    /** @var list<array<string, mixed>> */
    private array $log;

    protected function setUp(): void
    {
        $this->gateway = new FakeCalendarGateway();
        $this->formatter = new EventFormatter('6', '10');
        $this->log = [];
    }

    private function service(): Service
    {
        return new Service(
            id: 1, slug: 'pv', name: 'Photovoltaïque',
            durationMin: 90, bufferBeforeMin: 0, bufferAfterMin: 30,
            minLeadTimeHours: 24, maxHorizonDays: 60,
            weeklyHours: [], active: true, color: '#000'
        );
    }

    private function pending(int $id): Booking
    {
        // TimeSlot requires UTC dates.
        $slot = new TimeSlot(
            new DateTimeImmutable('2026-06-01T08:00:00Z', new DateTimeZone('UTC')),
            new DateTimeImmutable('2026-06-01T09:30:00Z', new DateTimeZone('UTC')),
        );
        $b = Booking::createPending(
            serviceId: 1, slot: $slot, timezone: 'Europe/Paris',
            customerName: 'Jean', customerEmail: 'j@x.fr',
            customerPhone: '0600', customerAddress: 'x',
            customerMeta: [], notes: '',
        );
        $b->assignId($id);
        return $b;
    }

    private function account(): GoogleAccount
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $a = GoogleAccount::connect('x', 'primary', 'enc-r', 'enc-a', $now->modify('+1 hour'));
        $a->assignId(1);
        return $a;
    }

    private function job(Booking $b, ?GoogleAccount $a = null): PushEventJob
    {
        $a = $a ?? $this->account();
        return new PushEventJob(
            findBooking: fn () => $b,
            findAccount: fn () => $a,
            persistBooking: function (Booking $bb): void { /* in-memory */ },
            gateway: $this->gateway,
            formatter: $this->formatter,
            service: $this->service(),
            log: function (array $entry): void { $this->log[] = $entry; },
        );
    }

    public function test_create_inserts_event_and_persists_id_etag(): void
    {
        $b = $this->pending(42);
        $this->job($b)->handle(42, 'create');

        self::assertCount(1, $this->gateway->calls);
        self::assertSame('insert', $this->gateway->calls[0]['op']);
        self::assertNotNull($b->googleEventId());
        self::assertNotNull($b->googleEventEtag());
        self::assertSame('ok', $this->log[0]['status']);
    }

    public function test_create_is_idempotent_when_event_already_set(): void
    {
        $b = $this->pending(42);
        $b->setGoogleEvent('evt_existing', '"etag_x"');
        $this->job($b)->handle(42, 'create');

        self::assertSame([], $this->gateway->calls);
        self::assertSame('evt_existing', $b->googleEventId());
    }

    public function test_confirm_patches_existing_event(): void
    {
        $b = $this->pending(42);
        $b->setGoogleEvent('evt_1', '"etag_old"');
        $b->confirm();

        $this->gateway->events['evt_1'] = ['etag' => '"etag_old"', 'payload' => []];

        $this->job($b)->handle(42, 'confirm');

        self::assertSame('patch', $this->gateway->calls[0]['op']);
        self::assertSame('evt_1', $b->googleEventId());
        self::assertStringStartsWith('"etag_patched_', $b->googleEventEtag() ?? '');
    }

    public function test_confirm_falls_back_to_create_when_no_event(): void
    {
        $b = $this->pending(42);
        $b->confirm();
        $this->job($b)->handle(42, 'confirm');

        self::assertSame('insert', $this->gateway->calls[0]['op']);
        self::assertNotNull($b->googleEventId());
    }

    public function test_delete_calls_gateway_and_clears_event(): void
    {
        $b = $this->pending(42);
        $b->setGoogleEvent('evt_to_delete', '"etag_x"');
        $this->gateway->events['evt_to_delete'] = ['etag' => '"etag_x"', 'payload' => []];

        $this->job($b)->handle(42, 'delete');

        self::assertSame('delete', $this->gateway->calls[0]['op']);
        self::assertNull($b->googleEventId());
    }

    public function test_delete_noop_when_no_event_set(): void
    {
        $b = $this->pending(42);
        $this->job($b)->handle(42, 'delete');
        self::assertSame([], $this->gateway->calls);
        self::assertSame('ok', $this->log[0]['status']);
    }

    public function test_delete_swallows_404_from_gateway_as_success(): void
    {
        $b = $this->pending(42);
        $b->setGoogleEvent('evt_already_gone', '"etag_x"');
        $this->gateway->throwClientErrorOnDelete = 404;

        $this->job($b)->handle(42, 'delete');

        self::assertSame('delete', $this->gateway->calls[0]['op']);
        self::assertNull($b->googleEventId());
        self::assertSame('ok', $this->log[0]['status']);
    }

    public function test_delete_swallows_410_from_gateway_as_success(): void
    {
        $b = $this->pending(42);
        $b->setGoogleEvent('evt_gone', '"etag_x"');
        $this->gateway->throwClientErrorOnDelete = 410;

        $this->job($b)->handle(42, 'delete');

        self::assertNull($b->googleEventId());
        self::assertSame('ok', $this->log[0]['status']);
    }

    public function test_delete_propagates_other_4xx_as_failed(): void
    {
        $b = $this->pending(42);
        $b->setGoogleEvent('evt_locked', '"etag_x"');
        $this->gateway->throwClientErrorOnDelete = 403;

        $this->job($b)->handle(42, 'delete');

        // Event id NOT cleared (operation failed); logged as 'failed'.
        self::assertSame('evt_locked', $b->googleEventId());
        self::assertSame('failed', $this->log[0]['status']);
    }

    public function test_transient_5xx_is_logged_as_retry_and_rethrown(): void
    {
        $b = $this->pending(42);
        $this->gateway->failNext = true;
        $this->expectException(\Slash\Booking\Google\Exceptions\GoogleApiError::class);
        try {
            $this->job($b)->handle(42, 'create');
        } finally {
            self::assertSame('retry', $this->log[0]['status']);
        }
    }

    public function test_unknown_booking_logs_failed(): void
    {
        $job = new PushEventJob(
            findBooking: fn () => null,
            findAccount: fn () => $this->account(),
            persistBooking: fn () => null,
            gateway: $this->gateway,
            formatter: $this->formatter,
            service: $this->service(),
            log: function (array $e): void { $this->log[] = $e; },
        );
        $job->handle(999, 'create');
        self::assertSame('failed', $this->log[0]['status']);
    }

    public function test_no_account_logs_failed(): void
    {
        $b = $this->pending(42);
        $job = new PushEventJob(
            findBooking: fn () => $b,
            findAccount: fn () => null,
            persistBooking: fn () => null,
            gateway: $this->gateway,
            formatter: $this->formatter,
            service: $this->service(),
            log: function (array $e): void { $this->log[] = $e; },
        );
        $job->handle(42, 'create');
        self::assertSame('failed', $this->log[0]['status']);
        self::assertStringContainsString('No Google account', (string) $this->log[0]['error_message']);
    }
}

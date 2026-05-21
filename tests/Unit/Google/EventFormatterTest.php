<?php
declare(strict_types=1);

namespace Slash\Booking\Tests\Unit\Google;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Slash\Booking\Domain\Booking;
use Slash\Booking\Domain\Service;
use Slash\Booking\Domain\TimeSlot;
use Slash\Booking\Google\EventFormatter;

final class EventFormatterTest extends TestCase
{
    private function service(): Service
    {
        return new Service(
            id: 1, slug: 'pv', name: 'Photovoltaïque',
            durationMin: 90, bufferBeforeMin: 0, bufferAfterMin: 30,
            minLeadTimeHours: 24, maxHorizonDays: 60,
            weeklyHours: [], active: true, color: '#000'
        );
    }

    private function pending(): Booking
    {
        // TimeSlot requires UTC dates; 10:00 Europe/Paris (UTC+2) = 08:00 UTC in summer.
        $slot = new TimeSlot(
            new DateTimeImmutable('2026-06-01T08:00:00Z', new DateTimeZone('UTC')),
            new DateTimeImmutable('2026-06-01T09:30:00Z', new DateTimeZone('UTC')),
        );
        return Booking::createPending(
            serviceId: 1, slot: $slot, timezone: 'Europe/Paris',
            customerName: 'Jean Dupont', customerEmail: 'j@x.fr',
            customerPhone: '0600000000', customerAddress: '1 rue X',
            customerMeta: [], notes: 'Voir étage 2',
        );
    }

    public function test_pending_format_uses_orange_color_and_prefix(): void
    {
        $f = new EventFormatter(pendingColorId: '6', confirmedColorId: '10');
        $b = $this->pending();
        $payload = $f->format($b, $this->service());

        self::assertStringStartsWith('[À VALIDER] Photovoltaïque · Jean Dupont', $payload['summary']);
        self::assertSame('6', $payload['colorId']);
        self::assertSame('2026-06-01T10:00:00+02:00', $payload['start']['dateTime']);
        self::assertSame('Europe/Paris', $payload['start']['timeZone']);
        self::assertSame('2026-06-01T11:30:00+02:00', $payload['end']['dateTime']);
        self::assertStringContainsString('j@x.fr', $payload['description']);
        self::assertStringContainsString('0600000000', $payload['description']);
        self::assertStringContainsString('1 rue X', $payload['description']);
        self::assertStringContainsString('Voir étage 2', $payload['description']);
    }

    public function test_confirmed_format_uses_green_color_no_prefix(): void
    {
        $f = new EventFormatter(pendingColorId: '6', confirmedColorId: '10');
        $b = $this->pending();
        $b->assignId(7);
        $b->confirm();

        $payload = $f->format($b, $this->service());

        self::assertSame('Photovoltaïque · Jean Dupont', $payload['summary']);
        self::assertSame('10', $payload['colorId']);
    }

    public function test_description_escapes_html(): void
    {
        $f = new EventFormatter(pendingColorId: '6', confirmedColorId: '10');
        $slot = new TimeSlot(
            new DateTimeImmutable('2026-06-01T08:00:00Z', new DateTimeZone('UTC')),
            new DateTimeImmutable('2026-06-01T09:30:00Z', new DateTimeZone('UTC')),
        );
        $b = Booking::createPending(
            serviceId: 1, slot: $slot, timezone: 'Europe/Paris',
            customerName: '<script>x</script>', customerEmail: 'a@b.fr',
            customerPhone: '0', customerAddress: 'x',
            customerMeta: [], notes: '',
        );
        $payload = $f->format($b, $this->service());
        self::assertStringNotContainsString('<script>', $payload['description']);
        self::assertStringContainsString('&lt;script&gt;', $payload['description']);
    }
}

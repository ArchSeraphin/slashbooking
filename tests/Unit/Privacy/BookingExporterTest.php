<?php
declare(strict_types=1);

namespace Slash\Booking\Tests\Unit\Privacy;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Slash\Booking\Domain\Booking;
use Slash\Booking\Domain\BookingStatus;
use Slash\Booking\Domain\TimeSlot;
use Slash\Booking\Privacy\BookingExporter;

final class BookingExporterTest extends TestCase
{
    public function test_exports_data_for_matching_email(): void
    {
        $booking = $this->makeBooking('alice@example.com', 'Alice Martin');
        $exporter = new BookingExporter(
            findByEmail: fn (string $email) => $email === 'alice@example.com' ? [$booking] : [],
        );

        $result = $exporter->export('alice@example.com', 1);

        $this->assertTrue($result['done']);
        $this->assertCount(1, $result['data']);
        $this->assertSame('slashbooking', $result['data'][0]['group_id']);
        $this->assertSame((string) $booking->id(), $result['data'][0]['item_id']);
        $fields = array_column($result['data'][0]['data'], 'value', 'name');
        $this->assertSame('Alice Martin', $fields['Nom']);
        $this->assertSame('alice@example.com', $fields['E-mail']);
    }

    public function test_returns_empty_when_no_match(): void
    {
        $exporter = new BookingExporter(findByEmail: fn () => []);
        $result = $exporter->export('unknown@example.com', 1);

        $this->assertSame([], $result['data']);
        $this->assertTrue($result['done']);
    }

    private function makeBooking(string $email, string $name): Booking
    {
        $utc = new DateTimeZone('UTC');
        $b = Booking::createPending(
            serviceId: 1,
            slot: new TimeSlot(
                new DateTimeImmutable('2026-06-01 08:00', $utc),
                new DateTimeImmutable('2026-06-01 09:30', $utc),
            ),
            timezone: 'Europe/Paris',
            customerName: $name,
            customerEmail: $email,
            customerPhone: '+33 6 12 34 56 78',
            customerAddress: '1 rue de la Paix, 75001 Paris',
            customerMeta: [],
            notes: 'Test',
        );
        $b->assignId(42);
        return $b;
    }
}

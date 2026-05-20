<?php
declare(strict_types=1);

namespace Trinity\Booking\Tests\Unit\Notifications;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Trinity\Booking\Notifications\IcsBuilder;

final class IcsBuilderTest extends TestCase
{
    public function test_builds_minimal_vevent(): void
    {
        $ics = (new IcsBuilder())->build(
            uid: 'abc-123@trinity-booking',
            summary: 'RDV Photovoltaïque',
            description: 'Jean, 1 rue X',
            startUtc: new DateTimeImmutable('2026-06-01T12:00:00Z', new DateTimeZone('UTC')),
            endUtc:   new DateTimeImmutable('2026-06-01T13:30:00Z', new DateTimeZone('UTC')),
        );

        self::assertStringContainsString("BEGIN:VCALENDAR\r\n", $ics);
        self::assertStringContainsString("VERSION:2.0\r\n", $ics);
        self::assertStringContainsString("BEGIN:VEVENT\r\n", $ics);
        self::assertStringContainsString("UID:abc-123@trinity-booking\r\n", $ics);
        self::assertStringContainsString("DTSTART:20260601T120000Z\r\n", $ics);
        self::assertStringContainsString("DTEND:20260601T133000Z\r\n", $ics);
        self::assertStringContainsString("SUMMARY:RDV Photovoltaïque\r\n", $ics);
        self::assertStringContainsString("END:VEVENT\r\n", $ics);
        self::assertStringContainsString("END:VCALENDAR\r\n", $ics);
    }

    public function test_escapes_commas_semicolons_backslashes_and_newlines(): void
    {
        $ics = (new IcsBuilder())->build(
            uid: 'x@y',
            summary: 'A, B; C\\ D',
            description: "Ligne 1\nLigne 2",
            startUtc: new DateTimeImmutable('2026-06-01T10:00:00Z', new DateTimeZone('UTC')),
            endUtc:   new DateTimeImmutable('2026-06-01T11:00:00Z', new DateTimeZone('UTC')),
        );
        self::assertStringContainsString('SUMMARY:A\\, B\\; C\\\\ D', $ics);
        self::assertStringContainsString('DESCRIPTION:Ligne 1\\nLigne 2', $ics);
    }
}

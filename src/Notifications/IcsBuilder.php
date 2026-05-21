<?php
declare(strict_types=1);

namespace Slash\Booking\Notifications;

use DateTimeImmutable;

final class IcsBuilder
{
    private const CRLF = "\r\n";

    public function build(
        string $uid,
        string $summary,
        string $description,
        DateTimeImmutable $startUtc,
        DateTimeImmutable $endUtc,
    ): string {
        $now = gmdate('Ymd\THis\Z');
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//SlashBooking//FR',
            'CALSCALE:GREGORIAN',
            'METHOD:REQUEST',
            'BEGIN:VEVENT',
            'UID:' . $uid,
            'DTSTAMP:' . $now,
            'DTSTART:' . $startUtc->format('Ymd\THis\Z'),
            'DTEND:'   . $endUtc->format('Ymd\THis\Z'),
            'SUMMARY:' . $this->escape($summary),
            'DESCRIPTION:' . $this->escape($description),
            'STATUS:CONFIRMED',
            'END:VEVENT',
            'END:VCALENDAR',
        ];
        return implode(self::CRLF, $lines) . self::CRLF;
    }

    private function escape(string $value): string
    {
        return strtr($value, [
            '\\' => '\\\\',
            ','  => '\\,',
            ';'  => '\\;',
            "\n" => '\\n',
            "\r" => '',
        ]);
    }
}

<?php
declare(strict_types=1);

namespace Trinity\Booking\Persistence;

use Trinity\Booking\Domain\BusyBlock;
use Trinity\Booking\Domain\TimeSlot;
use DateTimeImmutable;
use DateTimeZone;
use wpdb;

final class BusyBlockRepository
{
    private string $table;

    public function __construct(private readonly wpdb $wpdb)
    {
        $this->table = $wpdb->prefix . 'tb_busy_blocks';
    }

    /**
     * @return list<BusyBlock>
     */
    public function findInRange(DateTimeImmutable $fromUtc, DateTimeImmutable $toUtc): array
    {
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table}
                  WHERE starts_at_utc < %s AND ends_at_utc > %s
                  ORDER BY starts_at_utc",
                $toUtc->format('Y-m-d H:i:s'),
                $fromUtc->format('Y-m-d H:i:s'),
            ),
            ARRAY_A
        );
        if (!is_array($rows)) {
            return [];
        }
        $utc = new DateTimeZone('UTC');
        $out = [];
        foreach ($rows as $row) {
            $out[] = new BusyBlock(
                id: (int) $row['id'],
                source: (string) $row['source'],
                sourceId: (string) $row['source_id'],
                googleAccountId: $row['google_account_id'] !== null ? (int) $row['google_account_id'] : null,
                slot: new TimeSlot(
                    new DateTimeImmutable((string) $row['starts_at_utc'], $utc),
                    new DateTimeImmutable((string) $row['ends_at_utc'], $utc),
                ),
                summary: (string) ($row['summary'] ?? ''),
            );
        }
        return $out;
    }
}

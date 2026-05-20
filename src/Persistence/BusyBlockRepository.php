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
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
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
        return $this->hydrateMany(is_array($rows) ? $rows : []);
    }

    public function findBySourceId(int $googleAccountId, string $sourceId): ?BusyBlock
    {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE source = 'google' AND google_account_id = %d AND source_id = %s LIMIT 1",
                $googleAccountId,
                $sourceId,
            ),
            ARRAY_A
        );
        if (!is_array($row)) {
            return null;
        }
        return $this->hydrateOne($row);
    }

    public function upsertFromGoogle(BusyBlock $block): void
    {
        if ($block->googleAccountId === null) {
            throw new \InvalidArgumentException('BusyBlock from Google requires googleAccountId.');
        }
        $existing = $this->findBySourceId($block->googleAccountId, $block->sourceId);
        $row = [
            'source'             => 'google',
            'source_id'          => $block->sourceId,
            'google_account_id'  => $block->googleAccountId,
            'starts_at_utc'      => $block->slot->start->format('Y-m-d H:i:s'),
            'ends_at_utc'        => $block->slot->end->format('Y-m-d H:i:s'),
            'summary'            => $block->summary,
            'last_synced_at'     => ($block->lastSyncedAt ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
        ];
        if ($existing === null) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $this->wpdb->insert($this->table, $row);
            return;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $this->wpdb->update($this->table, $row, ['id' => $existing->id]);
    }

    public function deleteBySourceId(int $googleAccountId, string $sourceId): void
    {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $this->wpdb->delete($this->table, [
            'source'            => 'google',
            'google_account_id' => $googleAccountId,
            'source_id'         => $sourceId,
        ]);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<BusyBlock>
     */
    private function hydrateMany(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[] = $this->hydrateOne($row);
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateOne(array $row): BusyBlock
    {
        $utc = new DateTimeZone('UTC');
        return new BusyBlock(
            id: (int) $row['id'],
            source: (string) $row['source'],
            sourceId: (string) $row['source_id'],
            googleAccountId: $row['google_account_id'] !== null ? (int) $row['google_account_id'] : null,
            slot: new TimeSlot(
                new DateTimeImmutable((string) $row['starts_at_utc'], $utc),
                new DateTimeImmutable((string) $row['ends_at_utc'], $utc),
            ),
            summary: (string) ($row['summary'] ?? ''),
            lastSyncedAt: isset($row['last_synced_at'])
                ? new DateTimeImmutable((string) $row['last_synced_at'], $utc)
                : null,
        );
    }
}

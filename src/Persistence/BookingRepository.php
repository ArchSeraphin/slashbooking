<?php

declare(strict_types=1);

namespace Trinity\Booking\Persistence;

use Trinity\Booking\Domain\Booking;
use Trinity\Booking\Domain\BookingStatus;
use Trinity\Booking\Domain\TimeSlot;
use DateTimeImmutable;
use DateTimeZone;
use wpdb;
use ReflectionClass;

final class BookingRepository
{
    private string $table;

    public function __construct(private readonly wpdb $wpdb)
    {
        $this->table = $wpdb->prefix . 'tb_bookings';
    }

    public function save(Booking $booking): void
    {
        $row = $this->toRow($booking);
        if ($booking->id() === null) {
            $this->wpdb->insert($this->table, $row);
            $booking->assignId((int) $this->wpdb->insert_id);
            return;
        }
        $this->wpdb->update($this->table, $row, ['id' => $booking->id()]);
    }

    public function findById(int $id): ?Booking
    {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare("SELECT * FROM {$this->table} WHERE id = %d", $id),
            ARRAY_A
        );
        return is_array($row) ? $this->fromRow($row) : null;
    }

    public function findByGoogleEventId(string $googleEventId): ?Booking
    {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE google_event_id = %s LIMIT 1",
                $googleEventId,
            ),
            ARRAY_A
        );
        return is_array($row) ? $this->fromRow($row) : null;
    }

    public function findByPublicUid(string $uid): ?Booking
    {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare("SELECT * FROM {$this->table} WHERE public_uid = %s", $uid),
            ARRAY_A
        );
        return is_array($row) ? $this->fromRow($row) : null;
    }

    /**
     * @return list<Booking>
     */
    public function findByCustomerEmail(string $email): array
    {
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE customer_email = %s ORDER BY starts_at_utc DESC",
                $email,
            ),
            ARRAY_A
        );
        if (!is_array($rows)) {
            return [];
        }
        return array_values(array_map(fn (array $r) => $this->fromRow($r), $rows));
    }

    /**
     * Anonymizes all bookings matching the given e-mail, preserving aggregates.
     *
     * Replaces customer PII with a SHA-256 hash of the original e-mail
     * (idempotent — calling twice on the same e-mail is a no-op the second time
     * because the hash != original e-mail).
     *
     * @return int Number of rows updated.
     */
    public function anonymizeByEmail(string $email): int
    {
        $hash = hash('sha256', strtolower(trim($email)));
        $now  = current_time('mysql', true);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $updated = $this->wpdb->update(
            $this->table,
            [
                'customer_name'    => 'Anonyme',
                'customer_email'   => substr($hash, 0, 16) . '@anon.invalid',
                'customer_phone'   => '',
                'customer_address' => '',
                'customer_meta'    => wp_json_encode([]),
                'notes'            => '',
                'ip'               => null,
                'user_agent'       => null,
                'updated_at'       => $now,
            ],
            ['customer_email' => $email],
        );

        return is_int($updated) ? $updated : 0;
    }

    /**
     * Hard-deletes bookings whose ends_at_utc is older than $cutoff
     * and status is terminal (completed, cancelled, rejected).
     *
     * Confirmed future bookings are NEVER deleted.
     *
     * @return int Number of rows deleted.
     */
    public function deleteOlderThan(\DateTimeImmutable $cutoff): int
    {
        $cutoffStr = $cutoff->format('Y-m-d H:i:s');
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $deleted = $this->wpdb->query(
            $this->wpdb->prepare(
                "DELETE FROM {$this->table}
                 WHERE ends_at_utc < %s
                 AND status IN ('completed', 'cancelled', 'rejected')",
                $cutoffStr,
            ),
        );
        return is_int($deleted) ? $deleted : 0;
    }

    /**
     * @param array{status?:?string, service_id?:?int, from?:?\DateTimeImmutable, to?:?\DateTimeImmutable} $filters
     * @return array{items:list<Booking>, total:int, page:int, per_page:int}
     */
    public function paginate(array $filters, int $page, int $perPage): array
    {
        $page    = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $where   = [];
        $args    = [];

        if (!empty($filters['status'])) {
            $where[] = 'status = %s';
            $args[]  = $filters['status'];
        }
        if (!empty($filters['service_id'])) {
            $where[] = 'service_id = %d';
            $args[]  = (int) $filters['service_id'];
        }
        if (!empty($filters['from'])) {
            $where[] = 'starts_at_utc >= %s';
            $args[]  = $filters['from']->format('Y-m-d H:i:s');
        }
        if (!empty($filters['to'])) {
            $where[] = 'starts_at_utc < %s';
            $args[]  = $filters['to']->format('Y-m-d H:i:s');
        }

        $whereSql = $where !== [] ? ' WHERE ' . implode(' AND ', $where) : '';
        $offset   = ($page - 1) * $perPage;

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- table name is internal constant, args are passed via prepare()
        $totalSql = "SELECT COUNT(*) FROM {$this->table}" . $whereSql;
        $total    = (int) $this->wpdb->get_var(
            $args === [] ? $totalSql : $this->wpdb->prepare($totalSql, ...$args)
        );

        $listSql = "SELECT * FROM {$this->table}" . $whereSql .
            " ORDER BY starts_at_utc DESC LIMIT %d OFFSET %d";
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare($listSql, ...array_merge($args, [$perPage, $offset])),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
        if (!is_array($rows)) {
            $rows = [];
        }
        $items = array_map(fn (array $r) => $this->fromRow($r), $rows);
        return ['items' => $items, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    /**
     * @return list<Booking>
     */
    public function findRemindersDue(DateTimeImmutable $windowStart, DateTimeImmutable $windowEnd): array
    {
        $sql = $this->wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $this->table is internal constant
            "SELECT * FROM {$this->table}
             WHERE status = %s
               AND reminder_sent_at IS NULL
               AND starts_at_utc >= %s
               AND starts_at_utc < %s",
            BookingStatus::CONFIRMED->value,
            $windowStart->format('Y-m-d H:i:s'),
            $windowEnd->format('Y-m-d H:i:s'),
        );
        $rows = $this->wpdb->get_results($sql, ARRAY_A);
        if (!is_array($rows)) {
            return [];
        }
        return array_map(fn (array $r) => $this->fromRow($r), $rows);
    }

    public function markReminderSent(int $bookingId, DateTimeImmutable $atUtc): void
    {
        $this->wpdb->update(
            $this->table,
            ['reminder_sent_at' => $atUtc->format('Y-m-d H:i:s')],
            ['id' => $bookingId],
        );
    }

    /**
     * @return list<Booking>
     */
    public function findOverlapping(int $serviceId, TimeSlot $slot): array
    {
        $blocking = [BookingStatus::PENDING->value, BookingStatus::CONFIRMED->value];
        $placeholders = implode(',', array_fill(0, count($blocking), '%s'));
        $args = array_merge(
            [$serviceId],
            $blocking,
            [$slot->end->format('Y-m-d H:i:s'), $slot->start->format('Y-m-d H:i:s')]
        );
        $sql = $this->wpdb->prepare(
            "SELECT * FROM {$this->table}
              WHERE service_id = %d
                AND status IN ({$placeholders})
                AND starts_at_utc < %s
                AND ends_at_utc > %s",
            ...$args,
        );
        $rows = $this->wpdb->get_results($sql, ARRAY_A);
        if (!is_array($rows)) {
            return [];
        }
        return array_map(fn (array $row) => $this->fromRow($row), $rows);
    }

    /**
     * @return array<string, mixed>
     */
    private function toRow(Booking $b): array
    {
        return [
            'public_uid'         => $b->publicUid(),
            'service_id'         => $b->serviceId(),
            'status'             => $b->status()->value,
            'starts_at_utc'      => $b->slot()->start->format('Y-m-d H:i:s'),
            'ends_at_utc'        => $b->slot()->end->format('Y-m-d H:i:s'),
            'timezone'           => $b->timezone(),
            'customer_name'      => $b->customerName(),
            'customer_email'     => $b->customerEmail(),
            'customer_phone'     => $b->customerPhone(),
            'customer_address'   => $b->customerAddress(),
            'customer_meta'      => wp_json_encode($b->customerMeta()),
            'notes'              => $b->notes(),
            'google_event_id'    => $b->googleEventId(),
            'google_event_etag'  => $b->googleEventEtag(),
            'decision_token_hash' => $b->decisionTokenHash(),
            'reminder_sent_at'   => $b->reminderSentAt()?->format('Y-m-d H:i:s'),
            'created_at'         => $b->createdAt()->format('Y-m-d H:i:s'),
            'updated_at'         => $b->updatedAt()->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function fromRow(array $row): Booking
    {
        $utc = new DateTimeZone('UTC');
        $slot = new TimeSlot(
            new DateTimeImmutable((string) $row['starts_at_utc'], $utc),
            new DateTimeImmutable((string) $row['ends_at_utc'], $utc),
        );

        $ref = new ReflectionClass(Booking::class);
        $booking = $ref->newInstanceWithoutConstructor();

        $set = static function (string $prop, mixed $value) use ($ref, $booking): void {
            $p = $ref->getProperty($prop);
            $p->setValue($booking, $value);
        };

        $meta = is_string($row['customer_meta'] ?? null)
            ? (array) (json_decode((string) $row['customer_meta'], true) ?? [])
            : [];

        $set('id', (int) $row['id']);
        $set('publicUid', (string) $row['public_uid']);
        $set('serviceId', (int) $row['service_id']);
        $set('status', BookingStatus::from((string) $row['status']));
        $set('slot', $slot);
        $set('timezone', (string) $row['timezone']);
        $set('customerName', (string) $row['customer_name']);
        $set('customerEmail', (string) $row['customer_email']);
        $set('customerPhone', (string) $row['customer_phone']);
        $set('customerAddress', (string) ($row['customer_address'] ?? ''));
        $set('customerMeta', $meta);
        $set('notes', (string) ($row['notes'] ?? ''));
        $set('googleEventId', $row['google_event_id'] !== null ? (string) $row['google_event_id'] : null);
        $set('googleEventEtag', $row['google_event_etag'] !== null ? (string) $row['google_event_etag'] : null);
        $set('decisionTokenHash', $row['decision_token_hash'] !== null ? (string) $row['decision_token_hash'] : null);
        $set(
            'reminderSentAt',
            $row['reminder_sent_at'] !== null
                ? new DateTimeImmutable((string) $row['reminder_sent_at'], $utc)
                : null
        );
        $set('createdAt', new DateTimeImmutable((string) $row['created_at'], $utc));
        $set('updatedAt', new DateTimeImmutable((string) $row['updated_at'], $utc));

        return $booking;
    }
}

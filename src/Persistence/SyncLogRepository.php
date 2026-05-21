<?php
declare(strict_types=1);

namespace Slash\Booking\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use wpdb;

final class SyncLogRepository
{
    private const PAYLOAD_MAX = 4096;

    private string $table;

    public function __construct(private readonly wpdb $wpdb)
    {
        $this->table = $wpdb->prefix . 'sb_sync_log';
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function append(
        string $level,
        string $direction,
        string $entity,
        ?int $entityId,
        ?string $googleEventId,
        string $action,
        string $status,
        array $payload,
        ?string $errorMessage,
    ): void {
        $json = (string) wp_json_encode($payload);
        if (strlen($json) > self::PAYLOAD_MAX) {
            $json = substr($json, 0, self::PAYLOAD_MAX) . '…[truncated]';
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $this->wpdb->insert($this->table, [
            'ts'              => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
            'level'           => $level,
            'direction'       => $direction,
            'entity'          => $entity,
            'entity_id'       => $entityId,
            'google_event_id' => $googleEventId,
            'action'          => $action,
            'payload'         => $json,
            'status'          => $status,
            'error_message'   => $errorMessage,
        ]);
    }

    /**
     * @param array{level?:string, direction?:string, status?:string, entity_id?:int} $filters
     * @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public function paginate(array $filters, int $page, int $perPage): array
    {
        $page    = max(1, $page);
        $perPage = max(1, min(200, $perPage));
        $where   = [];
        $args    = [];

        foreach (['level', 'direction', 'status'] as $col) {
            if (!empty($filters[$col])) {
                $where[] = "{$col} = %s";
                $args[]  = (string) $filters[$col];
            }
        }
        if (!empty($filters['entity_id'])) {
            $where[] = 'entity_id = %d';
            $args[]  = (int) $filters['entity_id'];
        }
        $whereSql = $where !== [] ? ' WHERE ' . implode(' AND ', $where) : '';

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
        $totalSql = "SELECT COUNT(*) FROM {$this->table}" . $whereSql;
        $total = (int) $this->wpdb->get_var(
            $args === [] ? $totalSql : $this->wpdb->prepare($totalSql, ...$args)
        );

        $listSql = "SELECT * FROM {$this->table}" . $whereSql .
            ' ORDER BY id DESC LIMIT %d OFFSET %d';
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare($listSql, ...array_merge($args, [$perPage, ($page - 1) * $perPage])),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

        return [
            'items'    => is_array($rows) ? $rows : [],
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
        ];
    }

    public function purgeOlderThan(DateTimeImmutable $cutoff): int
    {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $rows = $this->wpdb->query(
            $this->wpdb->prepare(
                "DELETE FROM {$this->table} WHERE ts < %s",
                $cutoff->format('Y-m-d H:i:s')
            )
        );
        return is_int($rows) ? $rows : 0;
    }
}

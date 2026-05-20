<?php
declare(strict_types=1);

namespace Trinity\Booking\Persistence;

use Trinity\Booking\Domain\Service;
use wpdb;

final class ServiceRepository
{
    private string $table;

    public function __construct(private readonly wpdb $wpdb)
    {
        $this->table = $wpdb->prefix . 'tb_services';
    }

    public function findById(int $id): ?Service
    {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare("SELECT * FROM {$this->table} WHERE id = %d", $id),
            ARRAY_A
        );
        return is_array($row) ? Service::fromRow($row) : null;
    }

    public function findBySlug(string $slug): ?Service
    {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare("SELECT * FROM {$this->table} WHERE slug = %s", $slug),
            ARRAY_A
        );
        return is_array($row) ? Service::fromRow($row) : null;
    }

    /**
     * @return list<Service>
     */
    public function findAllActive(): array
    {
        $rows = $this->wpdb->get_results(
            "SELECT * FROM {$this->table} WHERE active = 1 ORDER BY sort_order, id",
            ARRAY_A
        );
        if (!is_array($rows)) {
            return [];
        }
        return array_map(static fn (array $row) => Service::fromRow($row), $rows);
    }

    /**
     * @return list<Service>
     */
    public function findAll(): array
    {
        $rows = $this->wpdb->get_results(
            "SELECT * FROM {$this->table} ORDER BY sort_order, id",
            ARRAY_A
        );
        if (!is_array($rows)) {
            return [];
        }
        return array_map(static fn (array $row) => Service::fromRow($row), $rows);
    }

    /**
     * Updates a service by id. Returns true on success.
     */
    public function update(Service $service): bool
    {
        if ($service->id === null) {
            return false;
        }
        $row = $service->toRow();
        $row['updated_at'] = current_time('mysql', true);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $res = $this->wpdb->update($this->table, $row, ['id' => $service->id]);
        return $res !== false;
    }
}

<?php
declare(strict_types=1);

namespace Trinity\Booking\Domain;

use InvalidArgumentException;

/**
 * @phpstan-type WeeklyHours array<int, list<array{open:string, close:string}>>
 */
final readonly class Service
{
    /**
     * @param WeeklyHours $weeklyHours
     */
    public function __construct(
        public ?int $id,
        public string $slug,
        public string $name,
        public int $durationMin,
        public int $bufferBeforeMin,
        public int $bufferAfterMin,
        public int $minLeadTimeHours,
        public int $maxHorizonDays,
        public array $weeklyHours,
        public bool $active,
        public string $color,
    ) {
        if ($durationMin < 1) {
            throw new InvalidArgumentException('Service duration must be >= 1 minute.');
        }
        if ($maxHorizonDays < 1) {
            throw new InvalidArgumentException('Service horizon must be >= 1 day.');
        }
        if (!preg_match('/^[a-z0-9-]+$/', $slug)) {
            throw new InvalidArgumentException('Service slug must be lowercase kebab-case.');
        }
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    /**
     * @return list<array{open:string, close:string}>
     */
    public function weeklyHoursForIsoDay(int $isoDay): array
    {
        return $this->weeklyHours[$isoDay] ?? [];
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $settings = [];
        if (isset($row['settings']) && is_string($row['settings'])) {
            $decoded = json_decode($row['settings'], true);
            if (is_array($decoded)) {
                $settings = $decoded;
            }
        }
        /** @var WeeklyHours $weekly */
        $weekly = $settings['weekly_hours'] ?? [];

        return new self(
            id: isset($row['id']) ? (int) $row['id'] : null,
            slug: (string) $row['slug'],
            name: (string) $row['name'],
            durationMin: (int) $row['duration_min'],
            bufferBeforeMin: (int) ($row['buffer_before_min'] ?? 0),
            bufferAfterMin: (int) ($row['buffer_after_min'] ?? 0),
            minLeadTimeHours: (int) ($row['min_lead_time_hours'] ?? 24),
            maxHorizonDays: (int) ($row['max_horizon_days'] ?? 60),
            weeklyHours: $weekly,
            active: ((int) ($row['active'] ?? 1)) === 1,
            color: (string) ($row['color'] ?? '#0ea5e9'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toRow(): array
    {
        return [
            'slug' => $this->slug,
            'name' => $this->name,
            'duration_min' => $this->durationMin,
            'buffer_before_min' => $this->bufferBeforeMin,
            'buffer_after_min' => $this->bufferAfterMin,
            'min_lead_time_hours' => $this->minLeadTimeHours,
            'max_horizon_days' => $this->maxHorizonDays,
            'color' => $this->color,
            'active' => $this->active ? 1 : 0,
            // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Domain layer does not depend on WordPress.
            'settings' => json_encode(['weekly_hours' => $this->weeklyHours], JSON_UNESCAPED_UNICODE),
        ];
    }
}

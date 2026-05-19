<?php
declare(strict_types=1);

namespace Trinity\Booking\Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;
use Trinity\Booking\Domain\Service;

final class ServiceTest extends TestCase
{
    public function test_constructs_and_exposes_attributes(): void
    {
        $svc = new Service(
            id: 1,
            slug: 'pv',
            name: 'Photovoltaïque',
            durationMin: 90,
            bufferBeforeMin: 0,
            bufferAfterMin: 30,
            minLeadTimeHours: 24,
            maxHorizonDays: 60,
            weeklyHours: [
                1 => [['open' => '09:00', 'close' => '18:00']],
                6 => [['open' => '09:00', 'close' => '13:00']],
            ],
            active: true,
            color: '#f59e0b',
        );
        self::assertSame('pv', $svc->slug);
        self::assertSame(90, $svc->durationMin);
        self::assertTrue($svc->isActive());
    }

    public function test_from_row_round_trip(): void
    {
        $row = [
            'id' => 2,
            'slug' => 'irve',
            'name' => 'Borne de recharge',
            'duration_min' => 45,
            'buffer_before_min' => 0,
            'buffer_after_min' => 30,
            'min_lead_time_hours' => 24,
            'max_horizon_days' => 60,
            'color' => '#10b981',
            'active' => 1,
            'sort_order' => 2,
            // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Test layer does not depend on WordPress.
            'settings' => json_encode(['weekly_hours' => [
                1 => [['open' => '09:00', 'close' => '18:00']],
            ]]),
        ];
        $svc = Service::fromRow($row);
        self::assertSame('irve', $svc->slug);
        self::assertSame([['open' => '09:00', 'close' => '18:00']], $svc->weeklyHoursForIsoDay(1));
        self::assertSame([], $svc->weeklyHoursForIsoDay(7));
    }

    public function test_rejects_negative_duration(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Service(
            id: 1, slug: 'x', name: 'X',
            durationMin: 0,
            bufferBeforeMin: 0, bufferAfterMin: 0,
            minLeadTimeHours: 0, maxHorizonDays: 1,
            weeklyHours: [], active: true, color: '#000000',
        );
    }
}

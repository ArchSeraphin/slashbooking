<?php

declare(strict_types=1);

namespace Slash\Booking\Tests\Integration;

use WP_UnitTestCase;
use Slash\Booking\Activator;
use Slash\Booking\Persistence\ServiceRepository;

final class ServiceRepositoryTest extends WP_UnitTestCase
{
    private ServiceRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        Activator::activate();
        global $wpdb;
        $this->repo = new ServiceRepository($wpdb);
    }

    public function test_find_by_slug(): void
    {
        $svc = $this->repo->findBySlug('pv');
        self::assertNotNull($svc);
        self::assertSame(90, $svc->durationMin);
    }

    public function test_find_active(): void
    {
        $active = $this->repo->findAllActive();
        self::assertCount(2, $active);
    }

    public function test_find_by_slug_returns_null_when_missing(): void
    {
        self::assertNull($this->repo->findBySlug('inexistant'));
    }

    public function test_find_by_id(): void
    {
        $svc = $this->repo->findBySlug('pv');
        self::assertNotNull($svc->id);
        $found = $this->repo->findById($svc->id);
        self::assertNotNull($found);
        self::assertSame('pv', $found->slug);
    }
}

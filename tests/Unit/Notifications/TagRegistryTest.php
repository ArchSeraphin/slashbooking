<?php
declare(strict_types=1);

namespace Trinity\Booking\Tests\Unit\Notifications;

use PHPUnit\Framework\TestCase;
use Trinity\Booking\Notifications\TagRegistry;

final class TagRegistryTest extends TestCase
{
    public function test_known_tag_returns_metadata(): void
    {
        $r = new TagRegistry();
        $tag = $r->find('customer_name');
        self::assertNotNull($tag);
        self::assertSame('customer', $tag['category']);
        self::assertFalse($tag['raw']);
    }

    public function test_url_tag_is_raw(): void
    {
        $r = new TagRegistry();
        self::assertTrue($r->find('cancel_url')['raw']);
        self::assertTrue($r->find('confirm_url')['raw']);
        self::assertTrue($r->find('reject_url')['raw']);
        self::assertTrue($r->find('ics_url')['raw']);
        self::assertTrue($r->find('company_logo')['raw']);
    }

    public function test_unknown_tag_returns_null(): void
    {
        self::assertNull((new TagRegistry())->find('nope'));
    }

    public function test_grouped_returns_categories_with_tags(): void
    {
        $grouped = (new TagRegistry())->grouped();
        self::assertArrayHasKey('customer', $grouped);
        self::assertArrayHasKey('appointment', $grouped);
        self::assertArrayHasKey('actions', $grouped);
        self::assertArrayHasKey('site', $grouped);
        self::assertNotEmpty($grouped['customer']);
    }
}

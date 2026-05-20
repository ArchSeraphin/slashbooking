<?php
declare(strict_types=1);

namespace Trinity\Booking\Tests\Unit\Http;

use PHPUnit\Framework\TestCase;
use Trinity\Booking\Http\TagRegistryController;
use Trinity\Booking\Notifications\TagRegistry;

final class TagRegistryControllerTest extends TestCase
{
    public function test_list_groups_tags_by_category(): void
    {
        $controller = new TagRegistryController(new TagRegistry());
        $response   = $controller->list();
        $data       = $response->get_data();

        $this->assertArrayHasKey('groups', $data);

        $cats = array_column($data['groups'], 'category');
        $this->assertContains('customer', $cats);
        $this->assertContains('appointment', $cats);
        $this->assertContains('actions', $cats);
        $this->assertContains('site', $cats);
    }

    public function test_each_group_has_label_and_tags(): void
    {
        $controller = new TagRegistryController(new TagRegistry());
        $data = $controller->list()->get_data();

        foreach ($data['groups'] as $group) {
            $this->assertArrayHasKey('category', $group);
            $this->assertArrayHasKey('label', $group);
            $this->assertArrayHasKey('tags', $group);
            $this->assertIsArray($group['tags']);
            $this->assertNotEmpty($group['tags']);
        }
    }
}

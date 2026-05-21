<?php
declare(strict_types=1);

namespace Slash\Booking\Tests\Unit\Notifications;

use PHPUnit\Framework\TestCase;
use Slash\Booking\Notifications\DefaultTemplates;
use Slash\Booking\Notifications\Events\EventKey;

final class DefaultTemplatesTest extends TestCase
{
    public function test_returns_template_for_each_event_key(): void
    {
        $defaults = DefaultTemplates::all();
        foreach (EventKey::cases() as $event) {
            self::assertArrayHasKey($event->value, $defaults);
            $tpl = $defaults[$event->value];
            self::assertNotEmpty($tpl['subject']);
            self::assertNotEmpty($tpl['html_body']);
        }
    }

    public function test_confirmed_template_includes_appointment_tags(): void
    {
        $tpl = DefaultTemplates::all()[EventKey::CONFIRMED_CLIENT->value];
        self::assertStringContainsString('{{appointment_date}}', $tpl['html_body']);
        self::assertStringContainsString('{{appointment_time}}', $tpl['html_body']);
        self::assertStringContainsString('{{cancel_url}}', $tpl['html_body']);
    }

    public function test_admin_pending_template_includes_decision_links(): void
    {
        $tpl = DefaultTemplates::all()[EventKey::PENDING_ADMIN->value];
        self::assertStringContainsString('{{confirm_url}}', $tpl['html_body']);
        self::assertStringContainsString('{{reject_url}}', $tpl['html_body']);
    }
}

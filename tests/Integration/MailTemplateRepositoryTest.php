<?php
declare(strict_types=1);

namespace Trinity\Booking\Tests\Integration;

use Trinity\Booking\Activator;
use Trinity\Booking\Notifications\Events\EventKey;
use Trinity\Booking\Persistence\MailTemplateRepository;
use WP_UnitTestCase;

final class MailTemplateRepositoryTest extends WP_UnitTestCase
{
    private MailTemplateRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        Activator::activate();
        global $wpdb;
        $this->repo = new MailTemplateRepository($wpdb);
    }

    public function test_default_when_no_custom_template(): void
    {
        $tpl = $this->repo->getOrDefault(EventKey::CONFIRMED_CLIENT);
        self::assertNotEmpty($tpl['subject']);
        self::assertNotEmpty($tpl['html_body']);
        self::assertFalse($tpl['is_custom']);
    }

    public function test_save_then_read_custom_template(): void
    {
        $this->repo->save(
            event: EventKey::CONFIRMED_CLIENT,
            subject: 'Sujet custom',
            htmlBody: '<p>Custom</p>',
            textBody: null,
            enabled: true,
            updatedBy: 1,
        );
        $tpl = $this->repo->getOrDefault(EventKey::CONFIRMED_CLIENT);
        self::assertSame('Sujet custom', $tpl['subject']);
        self::assertSame('<p>Custom</p>', $tpl['html_body']);
        self::assertTrue($tpl['is_custom']);
    }

    public function test_disabled_custom_falls_back_to_default(): void
    {
        $this->repo->save(
            EventKey::CONFIRMED_CLIENT, 'Sujet custom', '<p>Custom</p>',
            null, false, 1,
        );
        $tpl = $this->repo->getOrDefault(EventKey::CONFIRMED_CLIENT);
        self::assertFalse($tpl['is_custom']);
        self::assertStringNotContainsString('Sujet custom', $tpl['subject']);
    }

    public function test_delete_falls_back_to_default(): void
    {
        $this->repo->save(EventKey::CONFIRMED_CLIENT, 'X', '<p>X</p>', null, true, 1);
        $this->repo->delete(EventKey::CONFIRMED_CLIENT);
        $tpl = $this->repo->getOrDefault(EventKey::CONFIRMED_CLIENT);
        self::assertFalse($tpl['is_custom']);
        self::assertStringNotContainsString('X', $tpl['html_body']);
    }
}

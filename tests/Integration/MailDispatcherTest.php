<?php
declare(strict_types=1);

namespace Trinity\Booking\Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
use Trinity\Booking\Activator;
use Trinity\Booking\Domain\Booking;
use Trinity\Booking\Domain\Service;
use Trinity\Booking\Domain\TimeSlot;
use Trinity\Booking\Notifications\Events\BookingContext;
use Trinity\Booking\Notifications\Events\EventKey;
use Trinity\Booking\Notifications\IcsBuilder;
use Trinity\Booking\Notifications\MailDispatcher;
use Trinity\Booking\Notifications\TagRegistry;
use Trinity\Booking\Notifications\TemplateRenderer;
use Trinity\Booking\Notifications\TextBodyGenerator;
use Trinity\Booking\Persistence\MailTemplateRepository;
use WP_UnitTestCase;

final class MailDispatcherTest extends WP_UnitTestCase
{
    private MailDispatcher $dispatcher;

    /** @var list<array<string, mixed>> */
    public array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        Activator::activate();
        global $wpdb;
        $this->dispatcher = new MailDispatcher(
            templates: new MailTemplateRepository($wpdb),
            renderer:  new TemplateRenderer(new TagRegistry()),
            text:      new TextBodyGenerator(),
            ics:       new IcsBuilder(),
        );

        $self = $this;
        add_filter('pre_wp_mail', static function ($null, array $atts) use ($self): bool {
            $self->sent[] = $atts;
            return true;
        }, 10, 2);
    }

    public function test_sends_confirmed_email_with_ics_attachment(): void
    {
        $b = $this->newBooking();
        $svc = $this->newService();
        $ctx = BookingContext::fromBooking($b, $svc, ['site_name' => 'Trinity']);

        $ok = $this->dispatcher->send(
            event: EventKey::CONFIRMED_CLIENT,
            recipient: 'jean@test.fr',
            context: $ctx,
            withIcsFor: $b,
        );

        self::assertTrue($ok);
        self::assertCount(1, $this->sent);
        self::assertSame('jean@test.fr', $this->sent[0]['to']);
        self::assertStringContainsString('Photovoltaïque', $this->sent[0]['subject']);
        self::assertNotEmpty($this->sent[0]['attachments']);
    }

    public function test_send_returns_false_when_wp_mail_fails_without_throwing(): void
    {
        remove_all_filters('pre_wp_mail');
        add_filter('pre_wp_mail', static fn () => false);

        $b   = $this->newBooking();
        $svc = $this->newService();
        $ctx = BookingContext::fromBooking($b, $svc, []);

        $ok = $this->dispatcher->send(EventKey::PENDING_CLIENT, 'a@a.fr', $ctx);
        self::assertFalse($ok);
    }

    private function newBooking(): Booking
    {
        $slot = new TimeSlot(
            new DateTimeImmutable('2026-06-01T08:00:00Z', new DateTimeZone('UTC')),
            new DateTimeImmutable('2026-06-01T09:30:00Z', new DateTimeZone('UTC')),
        );
        $b = Booking::createPending(
            serviceId: 1, slot: $slot, timezone: 'Europe/Paris',
            customerName: 'Jean', customerEmail: 'jean@test.fr',
            customerPhone: '0600', customerAddress: 'x',
            customerMeta: [], notes: '',
        );
        $b->assignId(7);
        return $b;
    }

    private function newService(): Service
    {
        return new Service(
            id: 1, slug: 'pv', name: 'Photovoltaïque', durationMin: 90,
            bufferBeforeMin: 0, bufferAfterMin: 30,
            minLeadTimeHours: 0, maxHorizonDays: 60,
            weeklyHours: [], active: true, color: '#000',
        );
    }
}

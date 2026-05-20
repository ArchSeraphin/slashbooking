<?php
declare(strict_types=1);

namespace Trinity\Booking\Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
use Trinity\Booking\Activator;
use Trinity\Booking\Booking\DecisionTokenSigner;
use Trinity\Booking\Domain\Booking;
use Trinity\Booking\Domain\TimeSlot;
use Trinity\Booking\Http\UrlBuilder;
use Trinity\Booking\Notifications\BookingNotifier;
use Trinity\Booking\Notifications\IcsBuilder;
use Trinity\Booking\Notifications\MailDispatcher;
use Trinity\Booking\Notifications\TagRegistry;
use Trinity\Booking\Notifications\TemplateRenderer;
use Trinity\Booking\Notifications\TextBodyGenerator;
use Trinity\Booking\Persistence\BookingRepository;
use Trinity\Booking\Persistence\MailTemplateRepository;
use Trinity\Booking\Persistence\ServiceRepository;
use WP_UnitTestCase;

final class BookingNotifierTest extends WP_UnitTestCase
{
    /** @var list<array<string,mixed>> */
    public array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        Activator::activate();
        global $wpdb;
        $services = new ServiceRepository($wpdb);
        $bookings = new BookingRepository($wpdb);
        $dispatcher = new MailDispatcher(
            new MailTemplateRepository($wpdb),
            new TemplateRenderer(new TagRegistry()),
            new TextBodyGenerator(),
            new IcsBuilder(),
        );
        $signer = new DecisionTokenSigner((string) get_option('tb_decision_secret'));
        $urls   = new UrlBuilder($signer, rest_url('trinity-booking/v1'));

        (new BookingNotifier($services, $bookings, $dispatcher, $urls))->register();

        $self = $this;
        add_filter('pre_wp_mail', static function ($null, array $atts) use ($self): bool {
            $self->sent[] = $atts;
            return true;
        }, 10, 2);
    }

    public function test_booking_created_sends_two_emails(): void
    {
        $b = $this->newBooking('jean@test.fr');
        do_action('trinity_booking/booking_created', $b->id());

        self::assertCount(2, $this->sent);
        $recipients = array_column($this->sent, 'to');
        self::assertContains('jean@test.fr', $recipients);
        self::assertContains(get_option('admin_email'), $recipients);
    }

    public function test_booking_confirmed_sends_one_email_with_ics(): void
    {
        $b = $this->newBooking('jean@test.fr');
        do_action('trinity_booking/booking_confirmed', $b->id());

        self::assertCount(1, $this->sent);
        self::assertNotEmpty($this->sent[0]['attachments']);
    }

    public function test_unknown_booking_id_is_safe_noop(): void
    {
        do_action('trinity_booking/booking_confirmed', 99999);
        self::assertCount(0, $this->sent);
    }

    private function newBooking(string $email): Booking
    {
        global $wpdb;
        $slot = new TimeSlot(
            new DateTimeImmutable('2026-06-01T08:00:00Z', new DateTimeZone('UTC')),
            new DateTimeImmutable('2026-06-01T09:30:00Z', new DateTimeZone('UTC')),
        );
        $b = Booking::createPending(
            serviceId: 1, slot: $slot, timezone: 'Europe/Paris',
            customerName: 'Jean', customerEmail: $email,
            customerPhone: '0600', customerAddress: 'x',
            customerMeta: [], notes: '',
        );
        (new BookingRepository($wpdb))->save($b);
        return $b;
    }
}

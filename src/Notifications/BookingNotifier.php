<?php
declare(strict_types=1);

namespace Slash\Booking\Notifications;

use Slash\Booking\Domain\Booking;
use Slash\Booking\Domain\Service;
use Slash\Booking\Http\UrlBuilder;
use Slash\Booking\Notifications\Events\BookingContext;
use Slash\Booking\Notifications\Events\EventKey;
use Slash\Booking\Persistence\BookingRepository;
use Slash\Booking\Persistence\ServiceRepository;

final class BookingNotifier
{
    public function __construct(
        private readonly ServiceRepository $services,
        private readonly BookingRepository $bookings,
        private readonly MailDispatcher    $dispatcher,
        private readonly UrlBuilder        $urls,
    ) {
    }

    public function register(): void
    {
        add_action('slashbooking/booking_created',      [$this, 'onCreated'],      10, 1);
        add_action('slashbooking/booking_confirmed',    [$this, 'onConfirmed'],    10, 1);
        add_action('slashbooking/booking_rejected',     [$this, 'onRejected'],     10, 1);
        add_action('slashbooking/booking_cancelled',    [$this, 'onCancelled'],    10, 1);
        add_action('slashbooking/booking_reminder_due', [$this, 'onReminderDue'],  10, 1);
    }

    public function onCreated(int $bookingId): void
    {
        $b = $this->bookings->findById($bookingId);
        if ($b === null) {
            return;
        }
        $ctx = $this->context($b);
        $this->dispatcher->send(EventKey::PENDING_CLIENT, $b->customerEmail(), $ctx);
        $this->dispatcher->send(EventKey::PENDING_ADMIN, $this->adminEmail($ctx), $ctx);
    }

    public function onConfirmed(int $bookingId): void
    {
        $b = $this->bookings->findById($bookingId);
        if ($b === null) {
            return;
        }
        $this->dispatcher->send(EventKey::CONFIRMED_CLIENT, $b->customerEmail(), $this->context($b), $b);
    }

    public function onRejected(int $bookingId): void
    {
        $b = $this->bookings->findById($bookingId);
        if ($b === null) {
            return;
        }
        $this->dispatcher->send(EventKey::REJECTED_CLIENT, $b->customerEmail(), $this->context($b));
    }

    public function onCancelled(int $bookingId): void
    {
        $b = $this->bookings->findById($bookingId);
        if ($b === null) {
            return;
        }
        $this->dispatcher->send(EventKey::CANCELLED_CLIENT, $b->customerEmail(), $this->context($b));
    }

    public function onReminderDue(int $bookingId): void
    {
        $b = $this->bookings->findById($bookingId);
        if ($b === null) {
            return;
        }
        $this->dispatcher->send(EventKey::REMINDER_CLIENT, $b->customerEmail(), $this->context($b));
    }

    private function context(Booking $b): BookingContext
    {
        $svc = $this->services->findById($b->serviceId());
        if ($svc === null) {
            $durationMin = max(1, (int) (($b->slot()->end->getTimestamp() - $b->slot()->start->getTimestamp()) / 60));
            $svc = new Service(
                id: $b->serviceId(), slug: 'unknown', name: 'Service',
                durationMin: $durationMin,
                bufferBeforeMin: 0, bufferAfterMin: 0,
                minLeadTimeHours: 0, maxHorizonDays: 60,
                weeklyHours: [], active: true, color: '#000000',
            );
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.rand_mt_rand -- HOUR_IN_SECONDS is a WP constant; time() is used intentionally for expiry calculation.
        $exp = time() + 72 * HOUR_IN_SECONDS;
        $extra = [
            'site_name'     => (string) get_option('blogname', ''),
            'site_url'      => (string) home_url('/'),
            'admin_email'   => (string) get_option('admin_email', ''),
            'company_phone' => (string) get_option('sb_company_phone', ''),
            'company_logo'  => (string) get_option('sb_company_logo', ''),
            'cancel_url'    => $this->urls->cancelUrl($b->publicUid(), $exp),
            'confirm_url'   => $b->id() !== null ? $this->urls->decisionUrl($b->id(), 'confirm', $exp) : '',
            'reject_url'    => $b->id() !== null ? $this->urls->decisionUrl($b->id(), 'reject',  $exp) : '',
            'ics_url'       => '',
        ];

        return BookingContext::fromBooking($b, $svc, $extra);
    }

    private function adminEmail(BookingContext $ctx): string
    {
        $email = (string) ($ctx->toArray()['admin_email'] ?? '');
        return $email !== '' ? $email : (string) get_option('admin_email', '');
    }
}

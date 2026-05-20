<?php
declare(strict_types=1);

namespace Trinity\Booking\Http;

use Trinity\Booking\Availability\SlotGenerator;
use Trinity\Booking\Persistence\BookingRepository;
use Trinity\Booking\Persistence\BusyBlockRepository;
use Trinity\Booking\Persistence\ServiceRepository;

final class RestRouter
{
    public function register(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        global $wpdb;
        $services = new ServiceRepository($wpdb);
        $bookings = new BookingRepository($wpdb);
        $busy     = new BusyBlockRepository($wpdb);
        $generator = new SlotGenerator(
            stepMinutes: 15,
            siteTimezone: wp_timezone_string(),
        );

        $createBooking = new \Trinity\Booking\Booking\CreateBooking(
            slotIsFree: function (\Trinity\Booking\Domain\Service $svc, \Trinity\Booking\Domain\TimeSlot $slot) use ($bookings, $busy): bool {
                $blocking = $bookings->findOverlapping($svc->id ?? 0, $slot);
                if ($blocking !== []) {
                    return false;
                }
                foreach ($busy->findInRange($slot->start, $slot->end) as $bb) {
                    if ($slot->overlaps($bb->slot)) {
                        return false;
                    }
                }
                return true;
            },
            persist: function (\Trinity\Booking\Domain\Booking $b) use ($bookings): void {
                $bookings->save($b);
            },
        );

        (new PublicBookingController($services, $bookings, $busy, $generator, $createBooking))->registerRoutes();

        $signer = new \Trinity\Booking\Booking\DecisionTokenSigner((string) get_option('tb_decision_secret'));
        $cancel = new \Trinity\Booking\Booking\CancelBooking(
            find: fn (string $uid) => $bookings->findByPublicUid($uid),
            persist: fn (\Trinity\Booking\Domain\Booking $b) => $bookings->save($b),
        );
        (new PublicCancelController($signer, $cancel))->registerRoutes();

        $confirmUC = new \Trinity\Booking\Booking\ConfirmBooking(
            find: fn (int $id) => $bookings->findById($id),
            persist: fn (\Trinity\Booking\Domain\Booking $b) => $bookings->save($b),
        );
        $rejectUC = new \Trinity\Booking\Booking\RejectBooking(
            find: fn (int $id) => $bookings->findById($id),
            persist: fn (\Trinity\Booking\Domain\Booking $b) => $bookings->save($b),
        );
        (new DecisionController($signer, $confirmUC, $rejectUC))->registerRoutes();

        (new AdminBookingController($bookings, $confirmUC, $rejectUC, $cancel))->registerRoutes();

        $accounts    = new \Trinity\Booking\Persistence\GoogleAccountRepository($wpdb);
        $keyResolver = new \Trinity\Booking\Google\EncryptionKeyResolver();
        $encryption  = new \Trinity\Booking\Google\Encryption($keyResolver->resolve());
        $oauthState  = new \Trinity\Booking\Google\OAuthState((string) get_option('tb_decision_secret'));
        $oauthClient = new \Trinity\Booking\Google\OAuthClient(
            clientId: (string) get_option('tb_google_client_id', ''),
            clientSecret: (string) get_option('tb_google_client_secret', ''),
            redirectUri: rest_url(\Trinity\Booking\Plugin::REST_NAMESPACE . '/admin/google/oauth/callback'),
        );
        (new AdminGoogleController($accounts, $oauthClient, $oauthState, $encryption))->registerRoutes();

        $syncLog = new \Trinity\Booking\Persistence\SyncLogRepository($wpdb);
        (new AdminSyncLogController($syncLog))->registerRoutes();
    }
}

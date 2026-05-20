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
        $clientBuilder = new \Trinity\Booking\Google\GoogleClientBuilder($encryption, $accounts);
        $watchMgr      = new \Trinity\Booking\Google\WatchChannelManager(
            persist: fn (\Trinity\Booking\Domain\GoogleAccount $a) => $accounts->save($a),
            ttlSeconds: 604_800,
        );
        // TODO Task 16: replace inline closure with real Action Scheduler wiring from Plugin.php
        $enqueuePull = static function (int $accountId): void {
            if (function_exists('as_schedule_single_action')) {
                as_schedule_single_action(time() + 5, 'tb/google_pull', [$accountId], 'trinity-booking');
                return;
            }
            do_action('tb/google_pull', $accountId);
        };

        (new AdminGoogleController(
            $accounts,
            $oauthClient,
            $oauthState,
            $encryption,
            $watchMgr,
            $clientBuilder,
            $enqueuePull,
        ))->registerRoutes();

        $syncLog = new \Trinity\Booking\Persistence\SyncLogRepository($wpdb);
        (new AdminSyncLogController($syncLog))->registerRoutes();

        (new AdminGoogleSettingsController())->registerRoutes();

        // Plan 4 webhook — TODO Task 16: full wiring review
        (new GoogleWebhookController(
            $accounts,
            $enqueuePull,
            log: function (array $entry) use ($syncLog): void {
                $syncLog->append(
                    level: (string) $entry['level'],
                    direction: (string) $entry['direction'],
                    entity: (string) $entry['entity'],
                    entityId: $entry['entity_id'] !== null ? (int) $entry['entity_id'] : null,
                    googleEventId: $entry['google_event_id'] !== null ? (string) $entry['google_event_id'] : null,
                    action: (string) $entry['action'],
                    status: (string) $entry['status'],
                    payload: is_array($entry['payload'] ?? null) ? $entry['payload'] : [],
                    errorMessage: $entry['error_message'] !== null ? (string) $entry['error_message'] : null,
                );
            },
        ))->registerRoutes();

        // --- Plan 5 : templates editor + settings ---
        $mailRepo    = new \Trinity\Booking\Persistence\MailTemplateRepository($wpdb);
        $tagRegistry = new \Trinity\Booking\Notifications\TagRegistry();
        $renderer    = new \Trinity\Booking\Notifications\TemplateRenderer($tagRegistry);
        $dispatcher  = $this->resolveMailDispatcher($mailRepo, $renderer);

        (new \Trinity\Booking\Http\AdminMailTemplateController($mailRepo, $renderer, $dispatcher))->registerRoutes();
        (new \Trinity\Booking\Http\TagRegistryController($tagRegistry))->registerRoutes();
        (new \Trinity\Booking\Http\AdminSettingsController())->registerRoutes();
        (new \Trinity\Booking\Http\AdminServiceController($services))->registerRoutes();
    }

    private function resolveMailDispatcher(
        \Trinity\Booking\Persistence\MailTemplateRepository $repo,
        \Trinity\Booking\Notifications\TemplateRenderer $renderer,
    ): \Trinity\Booking\Notifications\MailDispatcher {
        try {
            return \Trinity\Booking\Plugin::instance()->get(\Trinity\Booking\Notifications\MailDispatcher::class);
        } catch (\RuntimeException) {
            // Fallback: build a fresh one (e.g., during tests or before Plugin boot).
            return new \Trinity\Booking\Notifications\MailDispatcher(
                $repo,
                $renderer,
                new \Trinity\Booking\Notifications\TextBodyGenerator(),
                new \Trinity\Booking\Notifications\IcsBuilder(),
            );
        }
    }
}

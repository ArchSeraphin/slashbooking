<?php
declare(strict_types=1);

namespace Slash\Booking\Http;

use Slash\Booking\Availability\SlotGenerator;
use Slash\Booking\Persistence\BookingRepository;
use Slash\Booking\Persistence\BusyBlockRepository;
use Slash\Booking\Persistence\ServiceRepository;
use Slash\Booking\Plugin;

final class RestRouter
{
    /**
     * Routes intentionally exposed to unauthenticated visitors. Validated
     * separately via signed tokens (cancel/decide/webhook), Turnstile +
     * honeypot + rate-limiting (bookings), or open by design (services,
     * availability).
     */
    private const PUBLIC_ROUTES = [
        'services',
        'availability',
        'bookings',
        'cancel',
        'decide',
        'google/webhook',
    ];

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
        // Some security plugins / snippets block the entire REST API for
        // guests via rest_authentication_errors, which short-circuits before
        // our routes' '__return_true' permission_callback can run. Whitelist
        // our public endpoints so the booking widget works in incognito.
        add_filter('rest_authentication_errors', [$this, 'unblockPublicEndpoints'], 99);
    }

    /**
     * @param  mixed $result
     * @return mixed
     */
    public function unblockPublicEndpoints($result)
    {
        if (!is_wp_error($result)) {
            return $result;
        }

        // Only relax "not authenticated" style errors. Never bypass nonce or
        // cookie validation failures that indicate a real auth-state mismatch.
        $bypassable = [
            'rest_not_logged_in',
            'rest_forbidden',
            'rest_cannot_access',
            'rest_login_required',
            'rest_user_invalid',
        ];
        if (!in_array($result->get_error_code(), $bypassable, true)) {
            return $result;
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
        if ($uri === '') {
            return $result;
        }

        $prefix = '/' . Plugin::REST_NAMESPACE . '/';
        foreach (self::PUBLIC_ROUTES as $route) {
            $needle = $prefix . $route;
            $pos = strpos($uri, $needle);
            if ($pos === false) {
                continue;
            }
            // Boundary check: must be followed by /, ?, &, or end of string,
            // so '/slashbooking/v1/services' does not also match a hypothetical
            // '/slashbooking/v1/services-extra' route.
            $after = $uri[$pos + strlen($needle)] ?? '';
            if ($after === '' || $after === '/' || $after === '?' || $after === '&') {
                return true;
            }
        }

        return $result;
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

        $createBooking = new \Slash\Booking\Booking\CreateBooking(
            slotIsFree: function (\Slash\Booking\Domain\Service $svc, \Slash\Booking\Domain\TimeSlot $slot) use ($bookings, $busy): bool {
                $blocking = $bookings->findOverlapping($svc->id ?? 0, $slot);
                if ($blocking !== []) {
                    return false;
                }
                // Calendar events use the same symmetric buffer policy as availability:
                // candidate.bufferAfter covers the "before" side, an explicit expansion
                // covers the "after" side.
                $bufferAfter = $svc->bufferAfterMin;
                $candidateExpanded = $slot->expand(0, $bufferAfter);
                $searchFrom = $slot->start->modify('-' . $bufferAfter . ' minutes');
                $searchTo   = $slot->end->modify('+' . $bufferAfter . ' minutes');
                foreach ($busy->findInRange($searchFrom, $searchTo) as $bb) {
                    $busySlot = $bb->slot->expand(0, $bufferAfter);
                    if ($candidateExpanded->overlaps($busySlot)) {
                        return false;
                    }
                }
                return true;
            },
            persist: function (\Slash\Booking\Domain\Booking $b) use ($bookings): void {
                $bookings->save($b);
            },
        );

        $turnstile = new \Slash\Booking\PublicFront\TurnstileVerifier(
            (string) get_option('sb_turnstile_secret_key', ''),
        );
        (new PublicBookingController($services, $bookings, $busy, $generator, $createBooking, $turnstile))->registerRoutes();

        $signer = new \Slash\Booking\Booking\DecisionTokenSigner((string) get_option('sb_decision_secret'));
        $cancel = new \Slash\Booking\Booking\CancelBooking(
            find: fn (string $uid) => $bookings->findByPublicUid($uid),
            persist: fn (\Slash\Booking\Domain\Booking $b) => $bookings->save($b),
        );
        (new PublicCancelController($signer, $cancel))->registerRoutes();

        $confirmUC = new \Slash\Booking\Booking\ConfirmBooking(
            find: fn (int $id) => $bookings->findById($id),
            persist: fn (\Slash\Booking\Domain\Booking $b) => $bookings->save($b),
        );
        $rejectUC = new \Slash\Booking\Booking\RejectBooking(
            find: fn (int $id) => $bookings->findById($id),
            persist: fn (\Slash\Booking\Domain\Booking $b) => $bookings->save($b),
        );
        (new DecisionController($signer, $confirmUC, $rejectUC))->registerRoutes();

        (new AdminBookingController($bookings, $confirmUC, $rejectUC, $cancel))->registerRoutes();

        $accounts    = new \Slash\Booking\Persistence\GoogleAccountRepository($wpdb);
        $keyResolver = new \Slash\Booking\Google\EncryptionKeyResolver();
        $encryption  = new \Slash\Booking\Google\Encryption($keyResolver->resolve());
        $oauthState  = new \Slash\Booking\Google\OAuthState((string) get_option('sb_decision_secret'));
        $oauthClient = new \Slash\Booking\Google\OAuthClient(
            clientId: (string) get_option('sb_google_client_id', ''),
            clientSecret: (string) get_option('sb_google_client_secret', ''),
            redirectUri: rest_url(\Slash\Booking\Plugin::REST_NAMESPACE . '/admin/google/oauth/callback'),
        );
        $clientBuilder = new \Slash\Booking\Google\GoogleClientBuilder($encryption, $accounts);
        $watchMgr      = new \Slash\Booking\Google\WatchChannelManager(
            persist: fn (\Slash\Booking\Domain\GoogleAccount $a) => $accounts->save($a),
            ttlSeconds: 604_800,
        );
        // TODO Task 16: replace inline closure with real Action Scheduler wiring from Plugin.php
        $enqueuePull = static function (int $accountId): void {
            if (function_exists('as_schedule_single_action')) {
                as_schedule_single_action(time() + 5, 'sb/google_pull', [$accountId], 'slashbooking');
                return;
            }
            do_action('sb/google_pull', $accountId);
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

        $syncLog = new \Slash\Booking\Persistence\SyncLogRepository($wpdb);
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
        $mailRepo    = new \Slash\Booking\Persistence\MailTemplateRepository($wpdb);
        $tagRegistry = new \Slash\Booking\Notifications\TagRegistry();
        $renderer    = new \Slash\Booking\Notifications\TemplateRenderer($tagRegistry);
        $dispatcher  = $this->resolveMailDispatcher($mailRepo, $renderer);

        (new \Slash\Booking\Http\AdminMailTemplateController($mailRepo, $renderer, $dispatcher))->registerRoutes();
        (new \Slash\Booking\Http\TagRegistryController($tagRegistry))->registerRoutes();
        (new \Slash\Booking\Http\AdminSettingsController())->registerRoutes();
        (new \Slash\Booking\Http\AdminServiceController($services, $bookings))->registerRoutes();
    }

    private function resolveMailDispatcher(
        \Slash\Booking\Persistence\MailTemplateRepository $repo,
        \Slash\Booking\Notifications\TemplateRenderer $renderer,
    ): \Slash\Booking\Notifications\MailDispatcher {
        try {
            return \Slash\Booking\Plugin::instance()->get(\Slash\Booking\Notifications\MailDispatcher::class);
        } catch (\RuntimeException) {
            // Fallback: build a fresh one (e.g., during tests or before Plugin boot).
            return new \Slash\Booking\Notifications\MailDispatcher(
                $repo,
                $renderer,
                new \Slash\Booking\Notifications\TextBodyGenerator(),
                new \Slash\Booking\Notifications\IcsBuilder(),
            );
        }
    }
}

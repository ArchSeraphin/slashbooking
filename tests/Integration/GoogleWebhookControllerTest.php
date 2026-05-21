<?php
declare(strict_types=1);

namespace Slash\Booking\Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Slash\Booking\Domain\GoogleAccount;
use Slash\Booking\Http\GoogleWebhookController;
use Slash\Booking\Persistence\GoogleAccountRepository;
use WP_REST_Request;

final class GoogleWebhookControllerTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (!class_exists('WP_REST_Request')) {
            self::markTestSkipped('WP REST not available.');
        }
    }

    private function freshAccount(): GoogleAccount
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query("DELETE FROM {$wpdb->prefix}sb_google_accounts");

        $repo = new GoogleAccountRepository($wpdb);
        $a = GoogleAccount::connect(
            'l',
            'primary',
            'r',
            'a',
            new DateTimeImmutable('+1 hour', new DateTimeZone('UTC')),
        );
        $a->attachWatch('ch_known', 'res_known', 'sec_known', new DateTimeImmutable('+1 day', new DateTimeZone('UTC')));
        $repo->save($a);
        return $a;
    }

    public function test_rejects_wrong_token(): void
    {
        $this->freshAccount();
        $enqueued = [];
        $ctrl = new GoogleWebhookController(
            new GoogleAccountRepository($GLOBALS['wpdb']),
            enqueuePull: function (int $id) use (&$enqueued): void {
                $enqueued[] = $id;
            },
            log: fn () => null,
        );
        $req = new WP_REST_Request('POST', '/slashbooking/v1/google/webhook');
        $req->set_header('X-Goog-Channel-Token', 'wrong');
        $req->set_header('X-Goog-Channel-Id', 'ch_known');
        $req->set_header('X-Goog-Resource-State', 'exists');

        $resp = $ctrl->handle($req);
        self::assertSame(401, $resp->get_status());
        self::assertSame([], $enqueued);
    }

    public function test_accepts_valid_token_and_enqueues_pull(): void
    {
        $account = $this->freshAccount();
        $enqueued = [];
        $ctrl = new GoogleWebhookController(
            new GoogleAccountRepository($GLOBALS['wpdb']),
            enqueuePull: function (int $id) use (&$enqueued): void {
                $enqueued[] = $id;
            },
            log: fn () => null,
        );
        $req = new WP_REST_Request('POST', '/slashbooking/v1/google/webhook');
        $req->set_header('X-Goog-Channel-Token', 'sec_known');
        $req->set_header('X-Goog-Channel-Id', 'ch_known');
        $req->set_header('X-Goog-Resource-State', 'exists');

        $resp = $ctrl->handle($req);
        self::assertSame(200, $resp->get_status());
        self::assertSame([(int) $account->id()], $enqueued);
    }

    public function test_sync_state_ack_no_pull(): void
    {
        $this->freshAccount();
        $enqueued = [];
        $ctrl = new GoogleWebhookController(
            new GoogleAccountRepository($GLOBALS['wpdb']),
            enqueuePull: function (int $id) use (&$enqueued): void {
                $enqueued[] = $id;
            },
            log: fn () => null,
        );
        $req = new WP_REST_Request('POST', '/slashbooking/v1/google/webhook');
        $req->set_header('X-Goog-Channel-Token', 'sec_known');
        $req->set_header('X-Goog-Channel-Id', 'ch_known');
        $req->set_header('X-Goog-Resource-State', 'sync');

        $resp = $ctrl->handle($req);
        self::assertSame(200, $resp->get_status());
        self::assertSame([], $enqueued);
    }
}

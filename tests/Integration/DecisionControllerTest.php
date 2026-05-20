<?php
declare(strict_types=1);

namespace Trinity\Booking\Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
use Trinity\Booking\Activator;
use Trinity\Booking\Booking\DecisionTokenSigner;
use Trinity\Booking\Domain\Booking;
use Trinity\Booking\Domain\BookingStatus;
use Trinity\Booking\Domain\TimeSlot;
use Trinity\Booking\Persistence\BookingRepository;
use WP_REST_Request;
use WP_UnitTestCase;

final class DecisionControllerTest extends WP_UnitTestCase
{
    private DecisionTokenSigner $signer;

    protected function setUp(): void
    {
        parent::setUp();
        Activator::activate();
        do_action('rest_api_init');
        $this->signer = new DecisionTokenSigner((string) get_option('tb_decision_secret'));
    }

    public function test_confirm_transitions_pending_to_confirmed(): void
    {
        $b = $this->seedPending();
        $exp = time() + 3600;
        $sig = $this->signer->sign('decide|' . $b->id() . '|confirm', $exp);
        $request = new WP_REST_Request('GET', '/trinity-booking/v1/decide');
        $request->set_query_params(['booking' => $b->id(), 'action' => 'confirm', 'exp' => $exp, 'sig' => $sig]);
        $response = rest_do_request($request);
        self::assertSame(200, $response->get_status());

        global $wpdb;
        $refreshed = (new BookingRepository($wpdb))->findById((int) $b->id());
        self::assertSame(BookingStatus::CONFIRMED, $refreshed->status());
    }

    public function test_reject_transitions_pending_to_rejected(): void
    {
        $b = $this->seedPending();
        $exp = time() + 3600;
        $sig = $this->signer->sign('decide|' . $b->id() . '|reject', $exp);
        $request = new WP_REST_Request('GET', '/trinity-booking/v1/decide');
        $request->set_query_params(['booking' => $b->id(), 'action' => 'reject', 'exp' => $exp, 'sig' => $sig]);
        $response = rest_do_request($request);
        self::assertSame(200, $response->get_status());

        global $wpdb;
        $refreshed = (new BookingRepository($wpdb))->findById((int) $b->id());
        self::assertSame(BookingStatus::REJECTED, $refreshed->status());
    }

    public function test_invalid_signature_returns_403(): void
    {
        $b = $this->seedPending();
        $request = new WP_REST_Request('GET', '/trinity-booking/v1/decide');
        $request->set_query_params(['booking' => $b->id(), 'action' => 'confirm', 'exp' => time() + 60, 'sig' => 'bogus']);
        $response = rest_do_request($request);
        self::assertSame(403, $response->get_status());
    }

    public function test_expired_token_returns_403(): void
    {
        $b = $this->seedPending();
        $exp = time() - 10;
        $sig = $this->signer->sign('decide|' . $b->id() . '|confirm', $exp);
        $request = new WP_REST_Request('GET', '/trinity-booking/v1/decide');
        $request->set_query_params(['booking' => $b->id(), 'action' => 'confirm', 'exp' => $exp, 'sig' => $sig]);
        self::assertSame(403, rest_do_request($request)->get_status());
    }

    public function test_idempotent_second_confirm_is_200(): void
    {
        $b = $this->seedPending();
        $exp = time() + 3600;
        $sig = $this->signer->sign('decide|' . $b->id() . '|confirm', $exp);
        $request = new WP_REST_Request('GET', '/trinity-booking/v1/decide');
        $request->set_query_params(['booking' => $b->id(), 'action' => 'confirm', 'exp' => $exp, 'sig' => $sig]);
        rest_do_request($request);
        self::assertSame(200, rest_do_request($request)->get_status());
    }

    private function seedPending(): Booking
    {
        global $wpdb;
        $repo = new BookingRepository($wpdb);
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
        $repo->save($b);
        return $b;
    }
}

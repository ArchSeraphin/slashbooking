<?php
declare(strict_types=1);

namespace Trinity\Booking\Http;

use Trinity\Booking\Booking\CancelBooking;
use Trinity\Booking\Booking\DecisionTokenSigner;
use Trinity\Booking\Booking\Exceptions\BookingNotFound;
use Trinity\Booking\Plugin;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class PublicCancelController
{
    public function __construct(
        private readonly DecisionTokenSigner $signer,
        private readonly CancelBooking $cancel,
    ) {
    }

    public function registerRoutes(): void
    {
        register_rest_route(
            Plugin::REST_NAMESPACE,
            '/cancel',
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'handle'],
                'permission_callback' => '__return_true',
                'args' => [
                    'uid' => ['type' => 'string', 'required' => true],
                    'exp' => ['type' => 'integer', 'required' => true],
                    'sig' => ['type' => 'string', 'required' => true],
                ],
            ]
        );
    }

    public function handle(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $uid = (string) $request['uid'];
        $exp = (int) $request['exp'];
        $sig = (string) $request['sig'];
        $payload = 'cancel|' . $uid;

        if (!$this->signer->verify($payload, $exp, $sig)) {
            return new WP_Error('tb_invalid_token', 'Lien invalide ou expiré.', ['status' => 403]);
        }

        try {
            $this->cancel->execute($uid);
        } catch (BookingNotFound $e) {
            return new WP_Error('tb_not_found', 'Réservation introuvable.', ['status' => 404]);
        }

        return new WP_REST_Response(['status' => 'cancelled'], 200);
    }
}

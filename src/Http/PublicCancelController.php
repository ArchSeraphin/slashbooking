<?php
declare(strict_types=1);

namespace Slash\Booking\Http;

use Slash\Booking\Booking\CancelBooking;
use Slash\Booking\Booking\DecisionTokenSigner;
use Slash\Booking\Booking\Exceptions\BookingNotFound;
use Slash\Booking\Plugin;
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
            return new WP_Error('sb_invalid_token', __('Lien invalide ou expiré.', 'slashbooking'), ['status' => 403]);
        }

        try {
            $this->cancel->execute($uid);
        } catch (BookingNotFound $e) {
            return new WP_Error('sb_not_found', __('Réservation introuvable.', 'slashbooking'), ['status' => 404]);
        }

        return new WP_REST_Response(['status' => 'cancelled'], 200);
    }
}

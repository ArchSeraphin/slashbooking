<?php
declare(strict_types=1);

namespace Trinity\Booking\Http;

use Trinity\Booking\Booking\ConfirmBooking;
use Trinity\Booking\Booking\DecisionTokenSigner;
use Trinity\Booking\Booking\Exceptions\BookingNotFound;
use Trinity\Booking\Booking\RejectBooking;
use Trinity\Booking\Plugin;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class DecisionController
{
    public function __construct(
        private readonly DecisionTokenSigner $signer,
        private readonly ConfirmBooking $confirm,
        private readonly RejectBooking  $reject,
    ) {
    }

    public function registerRoutes(): void
    {
        register_rest_route(
            Plugin::REST_NAMESPACE,
            '/decide',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'handle'],
                'permission_callback' => '__return_true',
                'args' => [
                    'booking' => ['type' => 'integer', 'required' => true],
                    'action'  => ['type' => 'string',  'required' => true, 'enum' => ['confirm', 'reject']],
                    'exp'     => ['type' => 'integer', 'required' => true],
                    'sig'     => ['type' => 'string',  'required' => true],
                ],
            ]
        );
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $id     = (int) $request['booking'];
        $action = (string) $request['action'];
        $exp    = (int) $request['exp'];
        $sig    = (string) $request['sig'];

        if (!in_array($action, ['confirm', 'reject'], true)) {
            return $this->htmlResponse(400, '<h1>' . esc_html__('Action invalide', 'trinity-booking') . '</h1>');
        }

        $payload = 'decide|' . $id . '|' . $action;
        if (!$this->signer->verify($payload, $exp, $sig)) {
            return $this->htmlResponse(
                403,
                '<h1>' . esc_html__('Lien invalide ou expiré', 'trinity-booking') . '</h1>'
                . '<p>' . esc_html__('Demandez un nouveau lien.', 'trinity-booking') . '</p>',
            );
        }

        try {
            if ($action === 'confirm') {
                $this->confirm->execute($id);
                $message = '<h1>' . esc_html__('RDV confirmé ✓', 'trinity-booking') . '</h1>'
                    . '<p>' . esc_html__('Le client a été notifié.', 'trinity-booking') . '</p>';
            } else {
                $this->reject->execute($id);
                $message = '<h1>' . esc_html__('RDV refusé', 'trinity-booking') . '</h1>'
                    . '<p>' . esc_html__('Le client a été notifié.', 'trinity-booking') . '</p>';
            }
        } catch (BookingNotFound $e) {
            return $this->htmlResponse(404, '<h1>' . esc_html__('Réservation introuvable', 'trinity-booking') . '</h1>');
        } catch (\DomainException $e) {
            return $this->htmlResponse(
                409,
                '<h1>' . esc_html__('Impossible', 'trinity-booking') . '</h1>'
                . '<p>' . esc_html($e->getMessage()) . '</p>',
            );
        }

        return $this->htmlResponse(200, $message);
    }

    private function htmlResponse(int $status, string $body): WP_REST_Response
    {
        return new WP_REST_Response(
            $this->wrapHtml($body),
            $status,
            ['Content-Type' => 'text/html; charset=UTF-8'],
        );
    }

    private function wrapHtml(string $inner): string
    {
        $title = esc_html__('Décision RDV', 'trinity-booking');
        return <<<HTML
<!doctype html><html lang="fr"><head><meta charset="utf-8"><title>{$title}</title>
<style>body{font-family:system-ui,sans-serif;max-width:560px;margin:80px auto;padding:0 16px;color:#111}</style>
</head><body>{$inner}</body></html>
HTML;
    }
}

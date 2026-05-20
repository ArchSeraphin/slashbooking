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
            return $this->htmlResponse(400, '<h1>Action invalide</h1>');
        }

        $payload = 'decide|' . $id . '|' . $action;
        if (!$this->signer->verify($payload, $exp, $sig)) {
            return $this->htmlResponse(403, '<h1>Lien invalide ou expiré</h1><p>Demandez un nouveau lien.</p>');
        }

        try {
            if ($action === 'confirm') {
                $this->confirm->execute($id);
                $message = '<h1>RDV confirmé ✓</h1><p>Le client a été notifié.</p>';
            } else {
                $this->reject->execute($id);
                $message = '<h1>RDV refusé</h1><p>Le client a été notifié.</p>';
            }
        } catch (BookingNotFound $e) {
            return $this->htmlResponse(404, '<h1>Réservation introuvable</h1>');
        } catch (\DomainException $e) {
            return $this->htmlResponse(
                409,
                '<h1>Impossible</h1><p>' . esc_html($e->getMessage()) . '</p>',
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

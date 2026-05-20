<?php
declare(strict_types=1);

namespace Trinity\Booking\Http;

use DateTimeImmutable;
use DateTimeZone;
use Trinity\Booking\Admin\Capabilities;
use Trinity\Booking\Notifications\Events\EventKey;
use Trinity\Booking\Notifications\MailDispatcher;
use Trinity\Booking\Notifications\TemplateRenderer;
use Trinity\Booking\Persistence\MailTemplateRepository;
use Trinity\Booking\Plugin;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class AdminMailTemplateController
{
    public function __construct(
        private readonly MailTemplateRepository $repo,
        private readonly TemplateRenderer $renderer,
        private readonly MailDispatcher $dispatcher,
    ) {
    }

    public function registerRoutes(): void
    {
        $cap = static fn (): bool => current_user_can(Capabilities::MANAGE);
        $ns  = Plugin::REST_NAMESPACE;

        register_rest_route($ns, '/admin/mail-templates', [
            ['methods' => 'GET', 'callback' => [$this, 'list'], 'permission_callback' => $cap],
        ]);
        register_rest_route($ns, '/admin/mail-templates/(?P<event_key>[a-z0-9_.]+)', [
            ['methods' => 'GET',    'callback' => [$this, 'get'],     'permission_callback' => $cap],
            ['methods' => 'POST',   'callback' => [$this, 'save'],    'permission_callback' => $cap],
            ['methods' => 'DELETE', 'callback' => [$this, 'restore'], 'permission_callback' => $cap],
        ]);
        register_rest_route($ns, '/admin/mail-templates/(?P<event_key>[a-z0-9_.]+)/preview', [
            ['methods' => 'POST', 'callback' => [$this, 'preview'], 'permission_callback' => $cap],
        ]);
        register_rest_route($ns, '/admin/mail-templates/(?P<event_key>[a-z0-9_.]+)/test', [
            ['methods' => 'POST', 'callback' => [$this, 'test'], 'permission_callback' => $cap],
        ]);
    }

    public function list(): WP_REST_Response
    {
        $items = [];
        foreach (EventKey::cases() as $key) {
            $t = $this->repo->getOrDefault($key);
            $items[] = [
                'event_key'  => $key->value,
                'subject'    => $t['subject'],
                'is_custom'  => $t['is_custom'],
                'enabled'    => $t['enabled'],
                'updated_at' => $t['updated_at'],
            ];
        }
        return new WP_REST_Response(['templates' => $items], 200);
    }

    /**
     * @return WP_REST_Response|WP_Error
     */
    public function get(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $key = $this->parseKey($req);
        if ($key instanceof WP_Error) {
            return $key;
        }
        $t = $this->repo->getOrDefault($key);
        return new WP_REST_Response([
            'event_key'  => $key->value,
            'subject'    => $t['subject'],
            'html_body'  => $t['html_body'],
            'text_body'  => $t['text_body'],
            'enabled'    => $t['enabled'],
            'is_custom'  => $t['is_custom'],
            'updated_at' => $t['updated_at'],
            'updated_by' => $t['updated_by'],
        ], 200);
    }

    /**
     * @return WP_REST_Response|WP_Error
     */
    public function save(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $key = $this->parseKey($req);
        if ($key instanceof WP_Error) {
            return $key;
        }

        $subject = (string) $req->get_param('subject');
        $html    = (string) $req->get_param('html_body');
        $text    = $req->get_param('text_body');
        $textVal = is_string($text) && $text !== '' ? $text : null;
        $enabled = (bool) $req->get_param('enabled');

        if (trim($subject) === '' || trim($html) === '') {
            return new WP_Error('tb_invalid_template', __('Sujet et corps HTML obligatoires.', 'trinity-booking'), ['status' => 400]);
        }

        $this->repo->save(
            $key,
            $subject,
            wp_kses_post($html),
            $textVal,
            $enabled,
            (int) get_current_user_id(),
        );

        return new WP_REST_Response(['saved' => true], 200);
    }

    /**
     * @return WP_REST_Response|WP_Error
     */
    public function restore(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $key = $this->parseKey($req);
        if ($key instanceof WP_Error) {
            return $key;
        }
        $this->repo->delete($key);
        return new WP_REST_Response(['restored' => true], 200);
    }

    /**
     * @return WP_REST_Response|WP_Error
     */
    public function preview(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $key = $this->parseKey($req);
        if ($key instanceof WP_Error) {
            return $key;
        }
        $subject = (string) $req->get_param('subject');
        $html    = (string) $req->get_param('html_body');
        $ctx     = $this->fakeContext($key);

        return new WP_REST_Response([
            'subject' => $this->renderer->render($subject, $ctx),
            'html'    => wp_kses_post($this->renderer->render($html, $ctx)),
        ], 200);
    }

    /**
     * @return WP_REST_Response|WP_Error
     */
    public function test(WP_REST_Request $req): WP_REST_Response|WP_Error
    {
        $key = $this->parseKey($req);
        if ($key instanceof WP_Error) {
            return $key;
        }
        $subject = (string) $req->get_param('subject');
        $html    = (string) $req->get_param('html_body');

        $ctx = $this->fakeContext($key);
        $renderedSubject = $this->renderer->render($subject, $ctx);
        $renderedHtml    = $this->renderer->render($html, $ctx);

        $user = wp_get_current_user();
        $to   = (string) ($user->user_email ?? get_option('admin_email'));
        $sent = $this->dispatcher->sendRaw($to, '[Test] ' . $renderedSubject, $renderedHtml, null);

        return new WP_REST_Response(['sent' => $sent, 'to' => $to], 200);
    }

    /**
     * @return EventKey|WP_Error
     */
    private function parseKey(WP_REST_Request $req): EventKey|WP_Error
    {
        $raw = (string) $req->get_param('event_key');
        $key = EventKey::tryFrom($raw);
        if ($key === null) {
            return new WP_Error('tb_unknown_event_key', __('Event key inconnue.', 'trinity-booking'), ['status' => 404]);
        }
        return $key;
    }

    /**
     * @return array<string, scalar|null>
     */
    private function fakeContext(EventKey $key): array
    {
        $tomorrow = (new DateTimeImmutable('tomorrow 10:00', new DateTimeZone('Europe/Paris')));
        return [
            'customer_name'    => 'Jeanne Dupont',
            'customer_email'   => 'jeanne.dupont@example.com',
            'customer_phone'   => '+33 6 12 34 56 78',
            'customer_address' => '15 rue de la République, 75011 Paris',
            'service_name'     => 'Photovoltaïque',
            'service_duration' => '1h30',
            'appointment_date' => $tomorrow->format('Y-m-d'),
            'appointment_time' => $tomorrow->format('H:i'),
            'appointment_end'  => $tomorrow->modify('+90 minutes')->format('H:i'),
            'timezone'         => 'Europe/Paris',
            'notes'            => 'Maison de 110 m² orientée sud.',
            'cancel_url'       => 'https://example.com/cancel?uid=preview',
            'confirm_url'      => 'https://example.com/confirm?id=preview',
            'reject_url'       => 'https://example.com/reject?id=preview',
            'ics_url'          => 'https://example.com/preview.ics',
            'site_name'        => (string) get_option('blogname', 'Trinity'),
            'site_url'         => (string) get_option('home', 'https://example.com'),
            'admin_email'      => (string) get_option('admin_email', 'admin@example.com'),
            'company_logo'     => '',
            'company_phone'    => '+33 1 23 45 67 89',
        ];
    }
}

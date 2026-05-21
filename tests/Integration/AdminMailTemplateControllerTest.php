<?php
declare(strict_types=1);

namespace Slash\Booking\Tests\Integration;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use Slash\Booking\Http\AdminMailTemplateController;
use Slash\Booking\Notifications\MailDispatcher;
use Slash\Booking\Notifications\TagRegistry;
use Slash\Booking\Notifications\TemplateRenderer;
use Slash\Booking\Persistence\MailTemplateRepository;
use Slash\Booking\Plugin;

/** @group rest */
final class AdminMailTemplateControllerTest extends WP_UnitTestCase
{
    /** @var WP_REST_Server */
    private $server;
    private MailTemplateRepository $repo;
    private int $admin;

    public function setUp(): void
    {
        parent::setUp();
        global $wp_rest_server, $wpdb;
        $wp_rest_server = $this->server = new WP_REST_Server();
        do_action('rest_api_init');

        $this->repo = new MailTemplateRepository($wpdb);
        $registry   = new TagRegistry();
        $renderer   = new TemplateRenderer($registry);
        $dispatcher = $this->createMock(MailDispatcher::class);
        $dispatcher->method('sendRaw')->willReturn(true);

        (new AdminMailTemplateController($this->repo, $renderer, $dispatcher))->registerRoutes();

        $this->admin = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($this->admin);
    }

    public function test_list_returns_all_six_templates(): void
    {
        $req = new WP_REST_Request('GET', '/' . Plugin::REST_NAMESPACE . '/admin/mail-templates');
        $res = $this->server->dispatch($req);

        $this->assertSame(200, $res->get_status());
        $data = $res->get_data();
        $this->assertCount(6, $data['templates']);
        foreach ($data['templates'] as $t) {
            $this->assertArrayHasKey('event_key', $t);
            $this->assertArrayHasKey('subject', $t);
            $this->assertArrayHasKey('is_custom', $t);
        }
    }

    public function test_get_single_returns_default_when_no_custom(): void
    {
        $req = new WP_REST_Request('GET', '/' . Plugin::REST_NAMESPACE . '/admin/mail-templates/booking.pending.client');
        $res = $this->server->dispatch($req);

        $this->assertSame(200, $res->get_status());
        $data = $res->get_data();
        $this->assertSame('booking.pending.client', $data['event_key']);
        $this->assertFalse($data['is_custom']);
        $this->assertNotEmpty($data['html_body']);
    }

    public function test_post_save_persists_custom_version(): void
    {
        $req = new WP_REST_Request('POST', '/' . Plugin::REST_NAMESPACE . '/admin/mail-templates/booking.pending.client');
        $req->set_body_params([
            'subject'   => 'Custom subject {{customer_name}}',
            'html_body' => '<p>Hello {{customer_name}}</p>',
            'text_body' => null,
            'enabled'   => true,
        ]);
        $res = $this->server->dispatch($req);

        $this->assertSame(200, $res->get_status());

        $req2 = new WP_REST_Request('GET', '/' . Plugin::REST_NAMESPACE . '/admin/mail-templates/booking.pending.client');
        $res2 = $this->server->dispatch($req2);
        $data = $res2->get_data();
        $this->assertTrue($data['is_custom']);
        $this->assertSame('Custom subject {{customer_name}}', $data['subject']);
    }

    public function test_delete_restores_default(): void
    {
        $req = new WP_REST_Request('POST', '/' . Plugin::REST_NAMESPACE . '/admin/mail-templates/booking.confirmed.client');
        $req->set_body_params(['subject' => 'X', 'html_body' => 'Y', 'enabled' => true]);
        $this->server->dispatch($req);

        $delReq = new WP_REST_Request('DELETE', '/' . Plugin::REST_NAMESPACE . '/admin/mail-templates/booking.confirmed.client');
        $delRes = $this->server->dispatch($delReq);
        $this->assertSame(200, $delRes->get_status());

        $getReq = new WP_REST_Request('GET', '/' . Plugin::REST_NAMESPACE . '/admin/mail-templates/booking.confirmed.client');
        $getRes = $this->server->dispatch($getReq);
        $this->assertFalse($getRes->get_data()['is_custom']);
    }

    public function test_preview_renders_with_fake_context(): void
    {
        $req = new WP_REST_Request('POST', '/' . Plugin::REST_NAMESPACE . '/admin/mail-templates/booking.confirmed.client/preview');
        $req->set_body_params([
            'subject'   => 'Test {{customer_name}}',
            'html_body' => '<p>Hello {{customer_name}} on {{appointment_date}}</p>',
        ]);
        $res = $this->server->dispatch($req);

        $this->assertSame(200, $res->get_status());
        $data = $res->get_data();
        $this->assertStringContainsString('Test', $data['subject']);
        $this->assertStringContainsString('<p>Hello', $data['html']);
        $this->assertStringNotContainsString('{{customer_name}}', $data['html']);
    }

    public function test_test_send_returns_ok(): void
    {
        $req = new WP_REST_Request('POST', '/' . Plugin::REST_NAMESPACE . '/admin/mail-templates/booking.pending.client/test');
        $req->set_body_params([
            'subject'   => 'Hello {{customer_name}}',
            'html_body' => '<p>Hi {{customer_name}}</p>',
        ]);
        $res = $this->server->dispatch($req);

        $this->assertSame(200, $res->get_status());
        $this->assertTrue($res->get_data()['sent']);
    }

    public function test_forbidden_for_non_admin(): void
    {
        wp_set_current_user(0);
        $req = new WP_REST_Request('GET', '/' . Plugin::REST_NAMESPACE . '/admin/mail-templates');
        $res = $this->server->dispatch($req);
        $this->assertSame(401, $res->get_status());
    }
}

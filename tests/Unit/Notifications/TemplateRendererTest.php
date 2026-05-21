<?php
declare(strict_types=1);

namespace Slash\Booking\Tests\Unit\Notifications;

use PHPUnit\Framework\TestCase;
use Slash\Booking\Notifications\TagRegistry;
use Slash\Booking\Notifications\TemplateRenderer;

final class TemplateRendererTest extends TestCase
{
    private TemplateRenderer $r;

    protected function setUp(): void
    {
        $this->r = new TemplateRenderer(new TagRegistry());
    }

    public function test_replaces_known_tag(): void
    {
        $out = $this->r->render('Bonjour {{customer_name}}', ['customer_name' => 'Jean']);
        self::assertSame('Bonjour Jean', $out);
    }

    public function test_html_escapes_by_default(): void
    {
        $out = $this->r->render('Nom : {{customer_name}}', ['customer_name' => '<b>X</b>']);
        self::assertSame('Nom : &lt;b&gt;X&lt;/b&gt;', $out);
    }

    public function test_raw_tag_is_not_escaped(): void
    {
        $url = 'https://x.tld/cancel?sig=abc&exp=1';
        $out = $this->r->render('Lien {{cancel_url}}', ['cancel_url' => $url]);
        self::assertSame("Lien {$url}", $out);
    }

    public function test_unknown_tag_left_intact(): void
    {
        $out = $this->r->render('Hello {{nope}}', []);
        self::assertSame('Hello {{nope}}', $out);
    }

    public function test_multiple_tags_in_subject(): void
    {
        $out = $this->r->render('{{service_name}} - {{customer_name}}', [
            'service_name' => 'PV', 'customer_name' => 'Jean',
        ]);
        self::assertSame('PV - Jean', $out);
    }

    public function test_missing_value_for_known_tag_renders_empty(): void
    {
        $out = $this->r->render('A{{customer_name}}B', []);
        self::assertSame('AB', $out);
    }
}

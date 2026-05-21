<?php
declare(strict_types=1);

namespace Slash\Booking\Tests\Unit\Notifications;

use PHPUnit\Framework\TestCase;
use Slash\Booking\Notifications\TextBodyGenerator;

final class TextBodyGeneratorTest extends TestCase
{
    public function test_strips_tags_and_normalises_whitespace(): void
    {
        $html = '<p>Bonjour <strong>Jean</strong>,</p><p>Votre RDV.</p>';
        $out = (new TextBodyGenerator())->fromHtml($html);
        self::assertSame("Bonjour Jean,\n\nVotre RDV.", $out);
    }

    public function test_converts_br_to_newline(): void
    {
        $out = (new TextBodyGenerator())->fromHtml('Ligne 1<br>Ligne 2');
        self::assertSame("Ligne 1\nLigne 2", $out);
    }

    public function test_keeps_link_href_inline(): void
    {
        $html = 'Clic <a href="https://x.tld/c?sig=1">ici</a> pour annuler.';
        $out = (new TextBodyGenerator())->fromHtml($html);
        self::assertSame('Clic ici (https://x.tld/c?sig=1) pour annuler.', $out);
    }

    public function test_decodes_entities(): void
    {
        $out = (new TextBodyGenerator())->fromHtml('Coût : 30&nbsp;€ &amp; plus');
        self::assertSame('Coût : 30 € & plus', $out);
    }
}

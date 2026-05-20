<?php
declare(strict_types=1);

namespace Trinity\Booking\Tests\Unit\Http;

use PHPUnit\Framework\TestCase;
use Trinity\Booking\Booking\DecisionTokenSigner;
use Trinity\Booking\Http\UrlBuilder;

final class UrlBuilderTest extends TestCase
{
    private UrlBuilder $b;
    private DecisionTokenSigner $signer;

    protected function setUp(): void
    {
        $this->signer = new DecisionTokenSigner('a-very-long-test-secret-32bytes-ok');
        $this->b = new UrlBuilder($this->signer, 'https://t.tld/wp-json/trinity-booking/v1');
    }

    public function test_cancel_url_has_uid_exp_sig(): void
    {
        $url = $this->b->cancelUrl('uid-123', 1900000000);
        self::assertStringContainsString('/cancel?', $url);
        self::assertStringContainsString('uid=uid-123', $url);
        self::assertStringContainsString('exp=1900000000', $url);
        self::assertStringContainsString('sig=', $url);
    }

    public function test_cancel_url_signature_is_verifiable(): void
    {
        $exp = 1900000000;
        $url = $this->b->cancelUrl('uid-abc', $exp);
        // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- unit test runs WP-free, wp_parse_url not available.
        parse_str(parse_url($url, PHP_URL_QUERY) ?: '', $q);
        self::assertTrue($this->signer->verify('cancel|uid-abc', $exp, (string) $q['sig']));
    }

    public function test_decision_urls_carry_action(): void
    {
        $confirm = $this->b->decisionUrl(42, 'confirm', 1900000000);
        $reject  = $this->b->decisionUrl(42, 'reject', 1900000000);
        self::assertStringContainsString('action=confirm', $confirm);
        self::assertStringContainsString('action=reject', $reject);
        self::assertStringContainsString('booking=42', $confirm);
    }

    public function test_decision_url_signature_is_verifiable(): void
    {
        $exp = 1900000000;
        $url = $this->b->decisionUrl(42, 'confirm', $exp);
        // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- unit test runs WP-free, wp_parse_url not available.
        parse_str(parse_url($url, PHP_URL_QUERY) ?: '', $q);
        self::assertTrue($this->signer->verify('decide|42|confirm', $exp, (string) $q['sig']));
    }
}

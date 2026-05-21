<?php
declare(strict_types=1);

namespace Slash\Booking\Tests\Unit\Booking;

use PHPUnit\Framework\TestCase;
use Slash\Booking\Booking\DecisionTokenSigner;

final class DecisionTokenSignerTest extends TestCase
{
    private DecisionTokenSigner $signer;

    protected function setUp(): void
    {
        $this->signer = new DecisionTokenSigner('test-secret-32-bytes-min-length-ok');
    }

    public function test_sign_and_verify_round_trip(): void
    {
        $exp = time() + 3600;
        $sig = $this->signer->sign('booking|42|confirm', $exp);
        self::assertTrue($this->signer->verify('booking|42|confirm', $exp, $sig));
    }

    public function test_verify_rejects_wrong_signature(): void
    {
        $exp = time() + 3600;
        self::assertFalse($this->signer->verify('booking|42|confirm', $exp, 'bogus'));
    }

    public function test_verify_rejects_expired(): void
    {
        $past = time() - 60;
        $sig = $this->signer->sign('booking|42|confirm', $past);
        self::assertFalse($this->signer->verify('booking|42|confirm', $past, $sig));
    }

    public function test_uses_constant_time_comparison(): void
    {
        $exp = time() + 60;
        $sig = $this->signer->sign('payload', $exp);
        self::assertTrue($this->signer->verify('payload', $exp, $sig));
    }
}

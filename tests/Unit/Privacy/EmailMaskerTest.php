<?php
declare(strict_types=1);

namespace Slash\Booking\Tests\Unit\Privacy;

use PHPUnit\Framework\TestCase;
use Slash\Booking\Privacy\EmailMasker;

final class EmailMaskerTest extends TestCase
{
    public function test_masks_standard_email(): void
    {
        $this->assertSame('j***@e***', EmailMasker::mask('john.doe@example.com'));
    }

    public function test_masks_short_local_part(): void
    {
        $this->assertSame('a***@e***', EmailMasker::mask('a@example.com'));
    }

    public function test_masks_short_domain(): void
    {
        $this->assertSame('j***@d***', EmailMasker::mask('john@d.fr'));
    }

    public function test_returns_empty_string_for_invalid_email(): void
    {
        $this->assertSame('', EmailMasker::mask(''));
        $this->assertSame('', EmailMasker::mask('not-an-email'));
        $this->assertSame('', EmailMasker::mask('@example.com'));
        $this->assertSame('', EmailMasker::mask('john@'));
    }

    public function test_lowercases_before_masking(): void
    {
        $this->assertSame('j***@e***', EmailMasker::mask('JOHN@EXAMPLE.COM'));
    }
}

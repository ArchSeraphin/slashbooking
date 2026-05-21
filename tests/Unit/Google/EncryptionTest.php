<?php
declare(strict_types=1);

namespace Slash\Booking\Tests\Unit\Google;

use PHPUnit\Framework\TestCase;
use Slash\Booking\Google\Encryption;
use Slash\Booking\Google\Exceptions\EncryptionFailure;

final class EncryptionTest extends TestCase
{
    private string $key;

    protected function setUp(): void
    {
        $this->key = random_bytes(32);
    }

    public function test_round_trip(): void
    {
        $enc = new Encryption($this->key);
        $cipher = $enc->encrypt('hello world');
        self::assertNotSame('hello world', $cipher);
        self::assertSame('hello world', $enc->decrypt($cipher));
    }

    public function test_different_ciphertext_each_time(): void
    {
        $enc = new Encryption($this->key);
        self::assertNotSame($enc->encrypt('x'), $enc->encrypt('x'));
    }

    public function test_decrypt_with_wrong_key_throws(): void
    {
        $enc1 = new Encryption($this->key);
        $cipher = $enc1->encrypt('secret');
        $enc2 = new Encryption(random_bytes(32));
        $this->expectException(EncryptionFailure::class);
        $enc2->decrypt($cipher);
    }

    public function test_decrypt_tampered_throws(): void
    {
        $enc = new Encryption($this->key);
        $cipher = $enc->encrypt('secret');
        $tampered = base64_encode('garbage' . base64_decode($cipher, true));
        $this->expectException(EncryptionFailure::class);
        $enc->decrypt($tampered);
    }

    public function test_short_key_throws(): void
    {
        $this->expectException(EncryptionFailure::class);
        new Encryption('too-short');
    }
}

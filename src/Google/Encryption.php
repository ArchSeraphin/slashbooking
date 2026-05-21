<?php
declare(strict_types=1);

namespace Slash\Booking\Google;

use Slash\Booking\Google\Exceptions\EncryptionFailure;

final class Encryption
{
    private const NONCE_BYTES = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
    private const KEY_BYTES   = SODIUM_CRYPTO_SECRETBOX_KEYBYTES;

    public function __construct(private readonly string $key)
    {
        if (\strlen($this->key) !== self::KEY_BYTES) {
            throw new EncryptionFailure(sprintf(
                'Encryption key must be %d bytes, got %d.',
                self::KEY_BYTES,
                \strlen($this->key),
            ));
        }
    }

    public function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(self::NONCE_BYTES);
        $cipher = sodium_crypto_secretbox($plaintext, $nonce, $this->key);
        return base64_encode($nonce . $cipher);
    }

    public function decrypt(string $payload): string
    {
        $bin = base64_decode($payload, true);
        if ($bin === false || \strlen($bin) <= self::NONCE_BYTES) {
            throw new EncryptionFailure('Invalid ciphertext payload.');
        }
        $nonce = substr($bin, 0, self::NONCE_BYTES);
        $cipher = substr($bin, self::NONCE_BYTES);
        $plain = sodium_crypto_secretbox_open($cipher, $nonce, $this->key);
        if ($plain === false) {
            throw new EncryptionFailure('Decryption failed (wrong key or tampered ciphertext).');
        }
        return $plain;
    }
}

<?php

declare(strict_types=1);

namespace Trinity\Booking\Google;

final class EncryptionKeyResolver
{
    public const CONSTANT = 'TRINITY_BOOKING_ENC_KEY';
    public const OPTION   = 'tb_enc_key';

    public function resolve(): string
    {
        if (defined(self::CONSTANT)) {
            $hex = (string) constant(self::CONSTANT);
            $bin = $this->hexToBin($hex);
            if ($bin !== null) {
                return $bin;
            }
        }

        $stored = get_option(self::OPTION);
        if (is_string($stored) && $stored !== '') {
            $bin = $this->hexToBin($stored);
            if ($bin !== null) {
                return $bin;
            }
        }

        $bin = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        update_option(self::OPTION, bin2hex($bin), false);
        return $bin;
    }

    public function usingFallback(): bool
    {
        return !defined(self::CONSTANT);
    }

    private function hexToBin(string $hex): ?string
    {
        $hex = trim($hex);
        if (strlen($hex) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES * 2 || !ctype_xdigit($hex)) {
            return null;
        }
        $bin = hex2bin($hex);
        return $bin === false ? null : $bin;
    }
}

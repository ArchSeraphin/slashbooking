<?php
declare(strict_types=1);

namespace Trinity\Booking\Privacy;

final class EmailMasker
{
    /**
     * Returns a privacy-safe representation of an e-mail address for logs.
     * Format: "<first-char>***@<first-char>***"
     *
     * Returns empty string if the input is not a valid e-mail.
     */
    public static function mask(string $email): string
    {
        $email = strtolower(trim($email));
        if ($email === '' || !str_contains($email, '@')) {
            return '';
        }
        [$local, $domain] = explode('@', $email, 2);
        if ($local === '' || $domain === '') {
            return '';
        }
        return $local[0] . '***@' . $domain[0] . '***';
    }
}

<?php
declare(strict_types=1);

namespace Trinity\Booking\Http;

use Trinity\Booking\Booking\DecisionTokenSigner;

final class UrlBuilder
{
    public function __construct(
        private readonly DecisionTokenSigner $signer,
        private readonly string $restBaseUrl,
    ) {
    }

    public function cancelUrl(string $publicUid, int $expiresAtUnix): string
    {
        $payload = 'cancel|' . $publicUid;
        $sig = $this->signer->sign($payload, $expiresAtUnix);
        return $this->restBaseUrl . '/cancel?' . http_build_query([
            'uid' => $publicUid,
            'exp' => $expiresAtUnix,
            'sig' => $sig,
        ]);
    }

    public function decisionUrl(int $bookingId, string $action, int $expiresAtUnix): string
    {
        $payload = 'decide|' . $bookingId . '|' . $action;
        $sig = $this->signer->sign($payload, $expiresAtUnix);
        return $this->restBaseUrl . '/decide?' . http_build_query([
            'booking' => $bookingId,
            'action'  => $action,
            'exp'     => $expiresAtUnix,
            'sig'     => $sig,
        ]);
    }
}

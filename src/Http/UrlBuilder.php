<?php
declare(strict_types=1);

namespace Slash\Booking\Http;

use Closure;
use Slash\Booking\Booking\DecisionTokenSigner;

final class UrlBuilder
{
    /**
     * @param Closure(): string $restBaseUrl resolves the REST namespace base URL on-demand.
     *                                       Lazy because rest_url() requires $wp_rewrite initialized,
     *                                       which is not yet available at plugin file load time.
     */
    public function __construct(
        private readonly DecisionTokenSigner $signer,
        private readonly Closure $restBaseUrl,
    ) {
    }

    public function cancelUrl(string $publicUid, int $expiresAtUnix): string
    {
        $payload = 'cancel|' . $publicUid;
        $sig = $this->signer->sign($payload, $expiresAtUnix);
        return ($this->restBaseUrl)() . '/cancel?' . http_build_query([
            'uid' => $publicUid,
            'exp' => $expiresAtUnix,
            'sig' => $sig,
        ]);
    }

    public function decisionUrl(int $bookingId, string $action, int $expiresAtUnix): string
    {
        $payload = 'decide|' . $bookingId . '|' . $action;
        $sig = $this->signer->sign($payload, $expiresAtUnix);
        return ($this->restBaseUrl)() . '/decide?' . http_build_query([
            'booking' => $bookingId,
            'action'  => $action,
            'exp'     => $expiresAtUnix,
            'sig'     => $sig,
        ]);
    }
}

<?php
declare(strict_types=1);

namespace Tds\Ext\Billing\Service;

/**
 * Verifies a Stripe webhook signature (the `Stripe-Signature` header) against the
 * raw request body + the endpoint's signing secret, WITHOUT the Stripe SDK.
 *
 * Stripe signs `"{t}.{payload}"` with HMAC-SHA256 under the webhook secret and
 * sends `t=<unix>,v1=<hex>[,v1=<hex>…]`. We recompute the HMAC, constant-time
 * compare it against each `v1`, and reject a timestamp outside the tolerance
 * window (replay guard). Pure + static → fully unit-testable (construct a valid
 * signature in the test), which matters because the live Stripe calls can't be.
 *
 * @see https://stripe.com/docs/webhooks/signatures
 */
final class WebhookVerifier
{
    /**
     * @param string $payload   raw request body (unmodified bytes)
     * @param string $sigHeader the `Stripe-Signature` header value
     * @param string $secret    the endpoint signing secret (whsec_…)
     * @param int    $tolerance max allowed |now − t| in seconds (0 = skip the time check)
     * @param int|null $now      injectable clock for tests (defaults to time())
     */
    public static function verify(
        string $payload,
        string $sigHeader,
        string $secret,
        int $tolerance = 300,
        ?int $now = null,
    ): bool {
        if ($secret === '' || $sigHeader === '') {
            return false;
        }
        [$timestamp, $signatures] = self::parseHeader($sigHeader);
        if ($timestamp === null || $signatures === []) {
            return false;
        }
        if ($tolerance > 0) {
            $now ??= time();
            if (abs($now - $timestamp) > $tolerance) {
                return false;
            }
        }
        $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
        foreach ($signatures as $sig) {
            if (hash_equals($expected, $sig)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return array{0: int|null, 1: string[]} [timestamp, v1 signatures]
     */
    private static function parseHeader(string $header): array
    {
        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $header) as $part) {
            $kv = explode('=', trim($part), 2);
            if (count($kv) !== 2) {
                continue;
            }
            [$key, $value] = $kv;
            if ($key === 't' && ctype_digit($value)) {
                $timestamp = (int) $value;
            } elseif ($key === 'v1' && $value !== '') {
                $signatures[] = $value;
            }
        }
        return [$timestamp, $signatures];
    }
}

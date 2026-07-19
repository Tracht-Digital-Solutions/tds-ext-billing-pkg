<?php
declare(strict_types=1);

namespace Tds\Ext\Billing\Tests;

use PHPUnit\Framework\TestCase;
use Tds\Ext\Billing\Service\WebhookVerifier;

final class WebhookVerifierTest extends TestCase
{
    private const SECRET = 'whsec_test_secret';

    /** Build a valid Stripe-Signature header for a payload at time $t. */
    private function sign(string $payload, int $t, string $secret = self::SECRET): string
    {
        $sig = hash_hmac('sha256', $t . '.' . $payload, $secret);
        return "t={$t},v1={$sig}";
    }

    public function testAcceptsValidSignature(): void
    {
        $now = 1_700_000_000;
        $payload = '{"type":"invoice.paid"}';
        self::assertTrue(WebhookVerifier::verify($payload, $this->sign($payload, $now), self::SECRET, 300, $now));
    }

    public function testRejectsTamperedPayload(): void
    {
        $now = 1_700_000_000;
        $header = $this->sign('{"type":"invoice.paid"}', $now);
        self::assertFalse(WebhookVerifier::verify('{"type":"invoice.void"}', $header, self::SECRET, 300, $now));
    }

    public function testRejectsWrongSecret(): void
    {
        $now = 1_700_000_000;
        $payload = '{"a":1}';
        self::assertFalse(WebhookVerifier::verify($payload, $this->sign($payload, $now), 'whsec_other', 300, $now));
    }

    public function testRejectsExpiredTimestamp(): void
    {
        $t = 1_700_000_000;
        $payload = '{"a":1}';
        // now is 10 minutes past t, tolerance 5 minutes → reject (replay guard).
        self::assertFalse(WebhookVerifier::verify($payload, $this->sign($payload, $t), self::SECRET, 300, $t + 600));
    }

    public function testToleranceZeroSkipsTimeCheck(): void
    {
        $t = 1_700_000_000;
        $payload = '{"a":1}';
        self::assertTrue(WebhookVerifier::verify($payload, $this->sign($payload, $t), self::SECRET, 0, $t + 999_999));
    }

    public function testRejectsMalformedHeaderAndEmpty(): void
    {
        self::assertFalse(WebhookVerifier::verify('{}', 'not-a-header', self::SECRET, 0));
        self::assertFalse(WebhookVerifier::verify('{}', '', self::SECRET, 0));
        self::assertFalse(WebhookVerifier::verify('{}', 't=1,v1=abc', '', 0));
    }

    public function testAcceptsAnyOfMultipleV1Signatures(): void
    {
        $now = 1_700_000_000;
        $payload = '{"a":1}';
        $good = hash_hmac('sha256', $now . '.' . $payload, self::SECRET);
        $header = "t={$now},v1=deadbeef,v1={$good}";
        self::assertTrue(WebhookVerifier::verify($payload, $header, self::SECRET, 300, $now));
    }
}

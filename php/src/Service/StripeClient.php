<?php
declare(strict_types=1);

namespace Tds\Ext\Billing\Service;

/**
 * Thin Stripe API client (plain ext-curl, no SDK — the extension convention).
 * Auth is the secret key as a Bearer token; requests are form-encoded per the
 * Stripe API. Covers the invoice flow the panel needs: create a customer, add
 * invoice items, create + finalize an invoice (returning the hosted pay URL).
 *
 * The live calls can't be exercised without a Stripe account, so they're kept
 * small + guarded; the signed-webhook path ({@see WebhookVerifier}) is the
 * unit-tested part.
 *
 * @see https://stripe.com/docs/api
 */
final class StripeClient
{
    public function __construct(
        private readonly string $secretKey,
        private readonly string $baseUrl = 'https://api.stripe.com/v1',
    ) {
    }

    /** False when no secret key is configured — the feature is then disabled (503). */
    public function isConfigured(): bool
    {
        return $this->secretKey !== '';
    }

    /**
     * Create a finalized invoice for a customer with the given line items.
     *
     * @param array<int,array{description:string,quantity:int,unit_amount_cents:int}> $items
     * @return array{stripe_invoice_id:string,hosted_invoice_url:?string,payment_intent_id:?string,status:string}
     * @throws StripeException
     */
    public function createInvoice(
        string $customerName,
        ?string $customerEmail,
        array $items,
        string $currency,
        int $daysUntilDue,
    ): array {
        $currency = strtolower($currency);
        $customer = $this->post('/customers', array_filter([
            'name' => $customerName,
            'email' => $customerEmail,
        ], static fn ($v): bool => $v !== null && $v !== ''));
        $customerId = (string) ($customer['id'] ?? '');

        foreach ($items as $item) {
            $amount = (int) $item['unit_amount_cents'] * max(1, (int) $item['quantity']);
            $this->post('/invoiceitems', [
                'customer' => $customerId,
                'amount' => $amount,
                'currency' => $currency,
                'description' => $item['description'],
            ]);
        }

        $invoice = $this->post('/invoices', [
            'customer' => $customerId,
            'collection_method' => 'send_invoice',
            'days_until_due' => $daysUntilDue,
            'currency' => $currency,
        ]);
        $invoiceId = (string) ($invoice['id'] ?? '');

        $final = $this->post('/invoices/' . rawurlencode($invoiceId) . '/finalize', []);
        return [
            'stripe_invoice_id' => (string) ($final['id'] ?? $invoiceId),
            'hosted_invoice_url' => isset($final['hosted_invoice_url']) ? (string) $final['hosted_invoice_url'] : null,
            'payment_intent_id' => isset($final['payment_intent']) ? (string) $final['payment_intent'] : null,
            'status' => (string) ($final['status'] ?? 'open'),
        ];
    }

    /**
     * POST a form-encoded request. Returns the decoded body on 2xx, throws otherwise.
     *
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     * @throws StripeException
     */
    private function post(string $path, array $params): array
    {
        $ch = curl_init($this->baseUrl . $path);
        if ($ch === false) {
            throw new StripeException('Stripe-Anfrage konnte nicht initialisiert werden.', 0);
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($params, '', '&'),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->secretKey,
                'Content-Type: application/x-www-form-urlencoded',
            ],
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new StripeException('Stripe nicht erreichbar: ' . $err, 0);
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        $body = json_decode((string) $raw, true);
        $body = is_array($body) ? $body : [];
        if ($status < 200 || $status >= 300) {
            $msg = isset($body['error']['message']) && is_string($body['error']['message'])
                ? $body['error']['message']
                : 'HTTP ' . $status;
            throw new StripeException('Stripe: ' . $msg, $status);
        }
        return $body;
    }
}

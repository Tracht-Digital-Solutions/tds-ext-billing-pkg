<?php
declare(strict_types=1);

namespace Tds\Ext\Billing\Service;

use Tds\Frontend\Contract\Stripe\StripeApi;
use Tds\Frontend\Contract\Stripe\StripeException;

/**
 * Thin Stripe API client (plain ext-curl, no SDK — the extension convention).
 * Auth is the secret key as a Bearer token; requests are form-encoded per the
 * Stripe API. Covers the invoice flow the panel needs: create a customer, add
 * invoice items, create + finalize an invoice (returning the hosted pay URL).
 *
 * The live calls can't be exercised without a Stripe account, so they're kept
 * small + guarded; the signed-webhook path ({@see \Tds\Frontend\Contract\Stripe\StripeWebhook}) is the
 * unit-tested part.
 *
 * @see https://stripe.com/docs/api
 */
final class StripeClient
{
    /**
     * The transport is the platform's StripeApi (tds-frontend-contract):
     * the central account from Einstellungen → Zahlungen, or a module key
     * that overrides it. This class keeps only the domain call.
     */
    public function __construct(private readonly StripeApi $api)
    {
    }

    /** False when no secret key is configured — the feature is then disabled (503). */
    public function isConfigured(): bool
    {
        return $this->api->isConfigured();
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
        $customer = $this->api->post('/customers', array_filter([
            'name' => $customerName,
            'email' => $customerEmail,
        ], static fn ($v): bool => $v !== null && $v !== ''));
        $customerId = (string) ($customer['id'] ?? '');

        foreach ($items as $item) {
            $amount = (int) $item['unit_amount_cents'] * max(1, (int) $item['quantity']);
            $this->api->post('/invoiceitems', [
                'customer' => $customerId,
                'amount' => $amount,
                'currency' => $currency,
                'description' => $item['description'],
            ]);
        }

        $invoice = $this->api->post('/invoices', [
            'customer' => $customerId,
            'collection_method' => 'send_invoice',
            'days_until_due' => $daysUntilDue,
            'currency' => $currency,
        ]);
        $invoiceId = (string) ($invoice['id'] ?? '');

        $final = $this->api->post('/invoices/' . rawurlencode($invoiceId) . '/finalize', []);
        return [
            'stripe_invoice_id' => (string) ($final['id'] ?? $invoiceId),
            'hosted_invoice_url' => isset($final['hosted_invoice_url']) ? (string) $final['hosted_invoice_url'] : null,
            'payment_intent_id' => isset($final['payment_intent']) ? (string) $final['payment_intent'] : null,
            'status' => (string) ($final['status'] ?? 'open'),
        ];
    }

}

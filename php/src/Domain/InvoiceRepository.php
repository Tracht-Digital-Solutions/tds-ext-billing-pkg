<?php
declare(strict_types=1);

namespace Tds\Ext\Billing\Domain;

use PDO;

/**
 * Invoice + line-item data access (`billing_invoice` / `billing_invoice_item`)
 * via the core shared PDO. `customer_id` references the tds-ext-customers
 * directory (no cross-domain FK). Totals are stored (summed from items at write).
 */
final class InvoiceRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Create a draft invoice with line items. Total is summed from the items.
     *
     * @param array<int,array{description:string,quantity:int,unit_amount_cents:int}> $items
     */
    public function createDraft(?int $customerId, string $currency, ?string $description, ?string $dueDate, array $items): int
    {
        $total = 0;
        foreach ($items as $it) {
            $total += (int) $it['unit_amount_cents'] * max(1, (int) $it['quantity']);
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO billing_invoice (customer_id, currency, status, description, total_cents, due_date)
             VALUES (:cid, :cur, :status, :desc, :total, :due)'
        );
        $stmt->execute([
            ':cid' => $customerId,
            ':cur' => strtoupper($currency),
            ':status' => 'draft',
            ':desc' => $description,
            ':total' => $total,
            ':due' => $dueDate,
        ]);
        $id = (int) $this->pdo->lastInsertId();

        $ins = $this->pdo->prepare(
            'INSERT INTO billing_invoice_item (invoice_id, description, quantity, unit_amount_cents, sort_order)
             VALUES (:iid, :desc, :qty, :unit, :sort)'
        );
        $sort = 0;
        foreach ($items as $it) {
            $ins->execute([
                ':iid' => $id,
                ':desc' => mb_substr($it['description'], 0, 300),
                ':qty' => max(1, (int) $it['quantity']),
                ':unit' => (int) $it['unit_amount_cents'],
                ':sort' => $sort++,
            ]);
        }
        return $id;
    }

    /** @return array<string,mixed>|null Invoice with its items. */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM billing_invoice WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }
        $invoice = self::map($row);
        $items = $this->pdo->prepare(
            'SELECT description, quantity, unit_amount_cents FROM billing_invoice_item
             WHERE invoice_id = :id ORDER BY sort_order ASC'
        );
        $items->execute([':id' => $id]);
        $invoice['items'] = array_map(static fn (array $r): array => [
            'description' => (string) $r['description'],
            'quantity' => (int) $r['quantity'],
            'unit_amount_cents' => (int) $r['unit_amount_cents'],
        ], $items->fetchAll());
        return $invoice;
    }

    /** @return list<array<string,mixed>> */
    public function adminList(): array
    {
        $rows = $this->pdo->query(
            'SELECT * FROM billing_invoice ORDER BY created_at DESC LIMIT 500'
        )->fetchAll();
        return array_map([self::class, 'map'], $rows);
    }

    /** @return list<array<string,mixed>> Invoices owned by a customer (portal). */
    public function listForCustomer(int $customerId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM billing_invoice WHERE customer_id = :cid AND status <> 'draft'
             ORDER BY created_at DESC"
        );
        $stmt->execute([':cid' => $customerId]);
        return array_map([self::class, 'map'], $stmt->fetchAll());
    }

    /** Record the Stripe result on send + move to `open`. */
    public function markSent(int $id, string $stripeInvoiceId, ?string $paymentIntentId, ?string $hostedUrl): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE billing_invoice
             SET status = 'open', stripe_invoice_id = :sid, stripe_payment_intent_id = :pi, hosted_invoice_url = :url
             WHERE id = :id"
        );
        $stmt->execute([':id' => $id, ':sid' => $stripeInvoiceId, ':pi' => $paymentIntentId, ':url' => $hostedUrl]);
    }

    /** Mark paid by Stripe invoice id (from the webhook). Returns the local id or null. */
    public function markPaidByStripeId(string $stripeInvoiceId): ?int
    {
        $find = $this->pdo->prepare('SELECT id FROM billing_invoice WHERE stripe_invoice_id = :sid');
        $find->execute([':sid' => $stripeInvoiceId]);
        $id = $find->fetchColumn();
        if ($id === false) {
            return null;
        }
        $upd = $this->pdo->prepare("UPDATE billing_invoice SET status = 'paid', paid_at = NOW() WHERE id = :id");
        $upd->execute([':id' => (int) $id]);
        return (int) $id;
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM billing_invoice WHERE id = :id');
        $stmt->execute([':id' => $id]);
    }

    public function openCount(): int
    {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM billing_invoice WHERE status = 'open'")->fetchColumn();
    }

    /** @param array<string,mixed> $r */
    private static function map(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'customer_id' => $r['customer_id'] !== null ? (int) $r['customer_id'] : null,
            'currency' => (string) $r['currency'],
            'status' => (string) $r['status'],
            'description' => $r['description'] !== null ? (string) $r['description'] : null,
            'total_cents' => (int) $r['total_cents'],
            'due_date' => $r['due_date'] !== null ? (string) $r['due_date'] : null,
            'stripe_invoice_id' => $r['stripe_invoice_id'] !== null ? (string) $r['stripe_invoice_id'] : null,
            'hosted_invoice_url' => $r['hosted_invoice_url'] !== null ? (string) $r['hosted_invoice_url'] : null,
            'paid_at' => $r['paid_at'] !== null ? (string) $r['paid_at'] : null,
            'created_at' => (string) $r['created_at'],
        ];
    }
}

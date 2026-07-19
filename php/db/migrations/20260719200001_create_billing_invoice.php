<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Billing/invoices schema. Class name prefixed with the module id (`Billing*`)
 * and migration version globally unique — the in-process auto-migrator includes
 * every module's migrations into ONE process + ONE phinxlog. MySQL-8-safe
 * (unsigned ids). `customer_id` references the tds-ext-customers `customer`
 * table but carries NO cross-domain FK (another extension owns it — same rule as
 * ticket.customer_id); nullable for a customerless draft.
 */
final class CreateBillingInvoice extends AbstractMigration
{
    public function change(): void
    {
        $this->table('billing_invoice', ['signed' => false])
            ->addColumn('customer_id', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('currency', 'char', ['limit' => 3, 'default' => 'EUR'])
            ->addColumn('status', 'enum', ['values' => ['draft', 'open', 'paid', 'void'], 'default' => 'draft'])
            ->addColumn('description', 'string', ['limit' => 500, 'null' => true])
            ->addColumn('total_cents', 'integer', ['default' => 0])
            ->addColumn('due_date', 'date', ['null' => true])
            ->addColumn('stripe_invoice_id', 'string', ['limit' => 60, 'null' => true])
            ->addColumn('stripe_payment_intent_id', 'string', ['limit' => 60, 'null' => true])
            ->addColumn('hosted_invoice_url', 'string', ['limit' => 500, 'null' => true])
            ->addColumn('paid_at', 'datetime', ['null' => true])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP', 'update' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['customer_id'])
            ->addIndex(['stripe_invoice_id'])
            ->create();

        $this->table('billing_invoice_item', ['signed' => false])
            ->addColumn('invoice_id', 'integer', ['signed' => false])
            ->addColumn('description', 'string', ['limit' => 300])
            ->addColumn('quantity', 'integer', ['default' => 1])
            ->addColumn('unit_amount_cents', 'integer', ['default' => 0])
            ->addColumn('sort_order', 'integer', ['default' => 0])
            ->addIndex(['invoice_id'])
            ->addForeignKey('invoice_id', 'billing_invoice', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
            ->create();
    }
}

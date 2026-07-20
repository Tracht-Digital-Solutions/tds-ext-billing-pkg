# AGENTS.md — tds-ext-billing

The **Stripe billing/invoices** panel extension. Read `tds-panel-contract`'s
AGENTS.md first; `tds-ext-lexware` / `tds-ext-customers` are the worked references
for the container-first Module + settings-store + curl-client pattern.

> Status (2026-07-20): **built locally, NOT pushed and NOT published.** The GitHub repo
> does not exist yet. Go-live: create the public repo + `PACKAGE_TOKEN`, push, Release →
> publish `@0.1.x`, wire into the admin product (and the customer product for the portal
> pay link), then point a Stripe webhook at `/billing/webhook` and set the Stripe keys via
> `/einstellungen`. `Service\WebhookVerifier` (HMAC-SHA256 + replay guard) is fully
> unit-tested since live Stripe calls can't be. See the root `MIGRATION-STATUS.md`
> (issue #4).

## What it does

Admins (`billing:read`/`billing:write`) draft invoices with line items for a
customer, **send them to Stripe** (creates a finalized, payable invoice via the
Stripe API), and a **signed Stripe webhook** marks them paid. Portal customers
see their own invoices + the hosted pay link.

- Tables `billing_invoice` + `billing_invoice_item` (module owns them). Money in
  integer cents; total summed from items at write.
- `customer_id` references the **tds-ext-customers** `customer` directory with NO
  cross-domain FK (soft, like `ticket.customer_id`); queried defensively at send
  time (try/catch — the customers extension may be absent). No hard `dependsOn`.
- Routes: widget `/billing/summary`; admin `GET/POST /admin/invoices`,
  `GET /admin/invoices/{id}`, `POST /admin/invoices/{id}/send`, `DELETE …`; portal
  `GET /billing/invoices` (+`/{id}`) scoped to the active company; **`POST
  /billing/webhook`** (unauthenticated, signature-verified).

## Key gotchas (don't regress)

- **`Service\WebhookVerifier` is the security-critical, UNIT-TESTED core** — Stripe
  signs `"{t}.{payload}"` HMAC-SHA256; we recompute + constant-time compare each
  `v1` + enforce a timestamp tolerance (replay guard). Pure/static so it's fully
  testable even though the live Stripe calls aren't. The webhook route verifies the
  **raw** body (`(string) $req->getBody()`), never the parsed body.
- **`Service\StripeClient` is plain ext-curl** (no SDK), form-encoded, Bearer
  secret key. `isConfigured()` false (no key) → routes 503. Live calls are
  deploy-verified only.
- **Config via the core `SettingsStore` (ns=`billing`)**: `stripe_secret_key` +
  `stripe_webhook_secret` (secret), `default_currency`, `days_until_due`; DB-first
  with env fallback (`STRIPE_SECRET_KEY` / `STRIPE_WEBHOOK_SECRET` / …). Reads use
  explicit `getenv() === false` checks (the `?? … ?:` precedence trap).
- Migration class prefix `Billing*`; migration **version** also unique across
  extensions (shared `phinxlog`). MySQL-8-safe (`signed=>false`).

## Commands

```bash
composer install && composer test    # phpunit: WebhookVerifier (real HMAC) + Module RBAC (DB-free)
npm install --no-package-lock && npm run type-check && npm run build
```

Register `new BillingModule()` in `tds-core-panel-api`'s `Modules::enabled()`; add
the manifest to the admin (and, for the portal invoice view, customer) target's
`panelHost({ extensions })`.

# tds-ext-billing-pkg

**Stripe billing/invoices** for the TDS panel. A build-time-composed extension for
the panel platform (`tds-panel-contract-pkg` + `tds-core-panel-*`).

## Features

- Admin drafts invoices with line items for a customer, **sends to Stripe**
  (creates a finalized, payable invoice), then a **signed Stripe webhook** marks
  them paid. Portal customers see their own invoices + the hosted pay link.
- Open-invoice widget + a `/rechnungen` admin page + Stripe settings.

Admin-facing writes (`billing:write`); reads `billing:read`. Ships in the **admin**
target (and the customer target for the portal invoice view).

## Configure

Runtime settings in the core store (ns `billing`, admin `/admin/settings/billing`
or the Einstellungen panel): `stripe_secret_key` + `stripe_webhook_secret`
(secret), `default_currency` (EUR), `days_until_due` (14). Each falls back to an
env var (`STRIPE_SECRET_KEY`, …). Point a Stripe webhook endpoint at
`…/billing/webhook` for `invoice.paid` / `invoice.payment_succeeded`. No secret
key ⇒ send/webhook routes 503.

## Develop

```bash
npm install --no-package-lock   # tds-panel-contract-pkg from GitHub Packages (NPM_TOKEN)
npm run type-check && npm run build
composer install                # contract from its public VCS repo
composer test                   # phpunit: WebhookVerifier (real HMAC) + Module RBAC (DB-free)
```

Enable it: add the manifest to the target `astro.config.mjs`
(`panelHost({ extensions })`) + `new BillingModule()` in `tds-core-panel-api`'s
`Modules::enabled()`.

## Versioning

Semver; the release workflow bumps `package.json` + `composer.json` in lockstep +
pushes an annotated tag (the Composer release ref). npm → GitHub Packages (public).

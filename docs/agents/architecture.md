# Architecture

## Routes

| Route | Gate | Purpose |
|---|---|---|
| `GET /billing/summary` | `billing:read` | Widget: open invoices, configured state |
| `GET /admin/invoices` | `billing:read` | Admin list |
| `POST /admin/invoices` | `billing:write` | Create a draft |
| `GET /admin/invoices/{id:[0-9]+}` | `billing:read` | Admin detail |
| `POST /admin/invoices/{id:[0-9]+}/send` | `billing:write` | Send to Stripe |
| `DELETE /admin/invoices/{id:[0-9]+}` | `billing:write` | Delete a draft |
| `GET /billing/invoices` | `billing:read` **or** `invoices:read` | Portal list, active company only |
| `GET /billing/invoices/{id:[0-9]+}` | `billing:read` **or** `invoices:read` | Portal detail |
| `POST /billing/webhook` | none, signature-verified | Stripe events |

- `invoices:read` is the portal key. The auth API's customer system group carries
  `invoices:read`, not `billing:read`, so the portal routes accept either. Admin routes
  stay on `billing:read` / `billing:write`.
- Admins bypass permission checks (core `UserContext`).

## Data model

- Tables `billing_invoice` and `billing_invoice_item`, migration `CreateBillingInvoice`.
  Unsigned ids (MySQL 8).
- Money in integer **cents**; the total is summed from items at write time.
- `customer_id` references the `tds-ext-customers-pkg` directory with **no** foreign key
  and no hard `dependsOn`. It is read defensively at send time (try/catch), because the
  customers extension may be absent.

## Stripe

- `Service\StripeClient` wraps a `StripeApi` from the contract
  (`Tds\Frontend\Contract\Stripe`). Key resolution, in order:
  1. this module's own `billing.stripe_secret_key` (optional; blank = central account),
  2. the central `StripeApi` bound by the core,
  3. `STRIPE_SECRET_KEY` from the environment.
- `isConfigured()` false → Stripe routes answer **503**.
- **Webhook:** `StripeWebhook::verify()` (contract) checks the HMAC-SHA256 of
  `"{t}.{payload}"` with a constant-time compare of each `v1` and a timestamp tolerance
  (replay guard). The route verifies the **raw** body (`(string) $req->getBody()`). A
  missing webhook secret → 503; a bad signature → 400. `invoice.paid` and
  `invoice.payment_succeeded` mark the invoice paid.
- Live Stripe calls can't be unit-tested; they are verified on deploy.

## Settings (core `SettingsStore`, namespace `billing`)

| Key | Secret | Env fallback |
|---|---|---|
| `stripe_secret_key` | yes | `STRIPE_SECRET_KEY` (via the central account) |
| `stripe_webhook_secret` | yes | `STRIPE_WEBHOOK_SECRET` |
| `default_currency` | no | — |
| `days_until_due` | no | — |

DB first, environment second. Env reads use explicit `getenv() === false` checks to avoid
the `?? … ?:` precedence trap.

The two secrets are **not interchangeable**: the API key authenticates calls *to* Stripe,
the webhook secret verifies calls *from* it. The settings island keeps their masked states
apart; a shared state would report the webhook secret as configured whenever the API key
is. Both are masked on read, and **blank on save keeps the existing value**.

## Admin island rules (`BillingAdmin`)

Invoices sent to Stripe become real money owed, so:

- **Send and delete exist only on a `draft`.** This is the only guard against re-sending
  an open invoice (double charge) or deleting one Stripe knows about (desynced ledgers).
- **Euros in, cents out**, with `Math.max(1, …)` on quantity; a negative quantity on a
  Stripe line item turns an invoice into a refund.
- **A line without an amount never reaches Stripe.** The empty starter row would otherwise
  add a phantom 0 € position.
- **Unassigned `customer_id` is `null`, not 0** (`Number("")` is 0).
- The send path **reloads the list either way**; the invoice may have reached Stripe even
  if the response failed.
- Send and delete outcomes are `toast.success` / `toast.danger`. Load failure and form
  validation stay in-flow as `.tds-alert--danger`.

## Widget

Shows `—` on a failure, never `0`; `0` would claim every invoice is settled.

## Motion

Invoice line items are an `AnimatedList` from `tds-shared/motion/react` (peer `>=0.38.7`).
Index keys are acceptable here because rows are only appended, never removed from the
middle. The invoice table stays static.

## Wiring

`new BillingModule()` is registered in `tds-core-frontend-api`'s `Modules::enabled()`. The
manifest is listed in the admin product's `frontendHost({ extensions })`, and in the
customer product's for the portal view.

## Sale events

After the webhook marks an invoice paid, it calls `Commerce\SaleEvents::paid()` (contract ≥ 1.15)
with `source = billing`, the invoice id and `total_cents`. It runs on every delivery and is
guarded, so it can never fail the webhook. The referral programme uses it to mature a
commission recorded against that invoice.

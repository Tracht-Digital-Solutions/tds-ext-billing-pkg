# AGENTS.md — tds-ext-billing-pkg

The **Stripe billing/invoices** frontend extension. Read `tds-frontend-contract-pkg`'s
AGENTS.md first; `tds-ext-lexware-pkg` / `tds-ext-customers-pkg` are the worked references
for the container-first Module + settings-store + curl-client pattern.

> Status (2026-07-20): **published @0.1.1** (public repo + GitHub Packages `@latest`, tag
> `v0.1.1`). Remaining go-live: wire into the admin product's `astro.config` (dep `^0.1.1`
> + the extensions array; and the customer product for the portal pay link), bump+release
> those products, then point a Stripe webhook at `/billing/webhook` and set the Stripe keys
> via `/einstellungen`. `Service\WebhookVerifier` (HMAC-SHA256 + replay guard) is fully
> unit-tested since live Stripe calls can't be. See the root `MIGRATION-STATUS.md`
> (issue #4).

## What it does

Admins (`billing:read`/`billing:write`) draft invoices with line items for a
customer, **send them to Stripe** (creates a finalized, payable invoice via the
Stripe API), and a **signed Stripe webhook** marks them paid. Portal customers
see their own invoices + the hosted pay link.

- Tables `billing_invoice` + `billing_invoice_item` (module owns them). Money in
  integer cents; total summed from items at write.
- `customer_id` references the **tds-ext-customers-pkg** `customer` directory with NO
  cross-domain FK (soft, like `ticket.customer_id`); queried defensively at send
  time (try/catch — the customers extension may be absent). No hard `dependsOn`.
- Routes: widget `/billing/summary`; admin `GET/POST /admin/invoices`,
  `GET /admin/invoices/{id}`, `POST /admin/invoices/{id}/send`, `DELETE …`; portal
  `GET /billing/invoices` (+`/{id}`) scoped to the active company; **`POST
  /billing/webhook`** (unauthenticated, signature-verified).

## Key gotchas (don't regress)

- **Never guard a container binding with `!$c->has(X::class)` — the dashboard
  billing widget 500'd for months because of it.** PHP-DI answers `has()` out of
  its definition sources, and *autowiring is one of them*: for any concrete,
  instantiable class the answer is always `true`, bound or not. So the guard that
  wrapped `InvoiceRepository` **and** `StripeClient` never ran, and the container
  quietly autowired instead. For the repository that is invisible — its only
  argument is the bound `PDO`. For `StripeClient` it is fatal, because its
  constructor takes a string:

  ```
  Entry "…\Service\StripeClient" cannot be resolved:
  Parameter $secretKey of __construct() has no value defined or guessable
  ```

  `GET /billing/summary` — the widget every admin dashboard renders — answered
  **500**, and the settings-store factory that reads `billing.stripe_secret_key`
  had never run once. Nothing went red: this repo's CI runs type-check + build,
  not tests, and a PHP-DI entry is built lazily. The module owns these classes
  and nothing else defines them, so **bind unconditionally**. Pinned by
  `ExtensionBindingsTest` in `tds-core-frontend-api`.

- **Call the API with `apiFetch` from `@tracht-digital-solutions/tds-shared/api`,
  never a relative `fetch`.** Every island used to define its own
  `const api = (path, init) => fetch(path, { credentials: "include", ...init })`
  with a RELATIVE path. In a product that resolves against the product's own
  static host (`management.`/`app.tracht-digital.de`), not the API — and the
  static host answers unknown paths with its SPA fallback, i.e. **200 + HTML**.
  So `res.ok` is `true`, `res.json()` throws, and the usual
  `.catch(() => setRows([]))` renders a calm, permanent empty state with no
  error and no console warning. `apiFetch` resolves the base from
  `<meta name="tds-api-base">` (written by the frontend host) and routes 401s
  through the host's confirm-against-`/me` backstop, which extension calls
  previously skipped entirely.
  The island tests match on the request PATH (`pathOf()`), which a relative
  fetch satisfies just as well — so one assertion per suite pins the **absolute
  host**. That is the line that fails if this ever regresses.


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

## Tests (frontend)
- **CI runs `test:run` since 2026-08-25 — before that, none of these suites
  ever ran on a runner.** `_build.yml` had type-check, lint:primitives and
  build. That included the `ApiDocSource` parity test, whose entire job is to
  fail when a route gains or loses documentation.
- **The suites used to run against a tds-shared a dozen minors old, and the
  first honest run cost 30 failures across the twelve shipping extensions.**
  This package declares tds-shared as a **peer** with a `>=0.19.0` floor, so a
  fresh install resolved 0.19.0 while every product build composes the current
  one. Three separate behaviours had moved underneath the tests, and each is
  worth knowing because a new suite will hit them again:
  - `apiFetch` consults the host-side runtime config (`/tds-runtime.json`)
    before it resolves a URL, so `fetch.mock.calls[0]` is that probe, not the
    endpoint. Call **`primeRuntimeConfig(null)`** in `beforeEach` — the panel
    products never ship that file (they render `<meta name="tds-api-base">`),
    so "absent" is also what happens in production.
  - `apiFetch` is **async**: the request leaves on a later microtask than the
    render. Reading `mock.calls` on the line after `render(...)` yields
    `undefined`; `await waitFor(() => expect(fetch).toHaveBeenCalled())` first.
  - A multipart upload now carries an **empty** `headers` object rather than
    `undefined`. Identical to the browser — the boundary is still the
    browser's to set — so assert "no content-type header", never
    "headers is undefined".


```bash
npm run test:run    # vitest, 125 tests (jsdom per-file via a @vitest-environment docblock)
```

`islands/BillingAdmin.test.tsx` — this island hands invoices to **Stripe**,
where they become real money owed by a real customer, so the assertions
concentrate on what cannot be walked back:

- **Senden and Löschen exist only on a `draft`.** That conditional is the only
  guard against re-sending an open invoice (double-charging) or deleting one
  Stripe already knows about (desyncing the two ledgers). Asserted for `open`,
  `paid` and `void`.
- **Amounts are typed in EUROS and sent in CENTS**, pinned at the edges —
  including the `Math.max(1, …)` quantity clamp, because a negative quantity on
  a Stripe line item turns an invoice into a refund.
- **A line with no amount never reaches Stripe.** The empty starter row is
  always in the form; submitting it would put a phantom 0 € position on every
  invoice.
- **`customer_id` is NULL, not 0**, when unassigned — `Number("")` is 0, which
  would attach the invoice to customer 0.
- The send path **re-reads the list either way**: the invoice may have reached
  Stripe even when the response failed, and only the reload shows the truth.
- **A failed send must not look like a successful one.** `send()` funnelled
  progress, success AND failure through one `.tds-alert` with no hue modifier —
  so "Fehler: card_declined" rendered in the same info blue as "An Stripe
  gesendet.", on the screen where money moves. Outcomes are now `toast.success`
  / `toast.danger` (tds-shared `>=0.16.0`); the delete path, which used to close
  the dialog and say nothing at all on failure, reports too. What stays in-flow
  is the **load failure** (persistent state) and form **validation** — both now
  in `.tds-alert--danger`, because that banner only carries failures now.
  Never mount a `ToastHost` here; the frontend host owns the only one.

`islands/BillingSettings.test.tsx` — the two Stripe secrets are **not
interchangeable**: the API key authenticates our calls *to* Stripe, the webhook
secret verifies calls *from* it. A shared masked state would report the webhook
secret as configured purely because the API key is, and payment confirmation
would look healthy while it silently is not. Both are asserted to stay apart,
both stored as secrets, and both honouring the store's contract (masked on read,
**blank on save = keep existing**).

`islands/WidgetBody.test.tsx` — `—` on a failure, never `0`: the latter claims
every invoice is settled.

`src/index.test.ts` + `tests/packaging.test.ts` — the manifest as a product
build sees it, and that every specifier resolves, is exported, and ships.

Error-path tests deliberately answer with a POPULATED body and a non-OK status.
Against an EMPTY error body the `res.ok` check is unobservable.

> The quantity test that matters uses **`-3` via `fireEvent`**: `Number("0") || 1`
> already yields 1, so a zero never reaches the clamp, and a `min="1"` number
> input will not accept a typed minus.

Verified by mutation: 64 deliberate breakages introduced, 64 caught.

## Commands

```bash
composer install && composer test    # phpunit: WebhookVerifier (real HMAC) + Module RBAC (DB-free)
npm install --no-package-lock && npm run type-check && npm run test:run && npm run build
```

Register `new BillingModule()` in `tds-core-frontend-api`'s `Modules::enabled()`; add
the manifest to the admin (and, for the portal invoice view, customer) target's
`frontendHost({ extensions })`.

## Mobile layout

This package ships **no CSS**, so every layout decision is a shared class or a
Tailwind utility, and neither is checked by anything at runtime. Two rules:

- **A row of more than two things — or any row holding a full-width field —
  goes on `.tds-row`, `.tds-list__row` or `.tds-toolbar`.** All three wrap.
  A hand-rolled `flex` does not, and on a 375px screen the overflow is not
  even visible: `body { overflow-x: hidden }` clips it, so the content simply
  is not there.
- **A `<table>` needs `tds-table` and nothing else.** The primitive turns
  itself into a horizontal scroller below 40rem; an extra `overflow-x`
  wrapper or an inline style is redundant. A table with no focusable cell
  also needs `tabindex="0"` + `role="region"` + a label, or its scrollport
  cannot be reached by keyboard.

`npm run lint:primitives` enforces the class part of this (including a
`<table>` without `tds-table` and a flex/grid table cell, which silently
drops the cell out of the column algorithm). It is a **regex scan**, so a tag
name written inside a comment counts as markup — name elements in prose.

## API-Referenz (`php/docs/api.php`)

This module implements the contract's optional `ApiDocSource`: `php/docs/api.php`
returns one entry per route (summary, params, responses, required permission),
and the admin frontend's API reference joins it onto the introspected Slim routes
by `"<METHOD> <pattern>"`. Two things to know before editing a route:

- **`pattern` must be the Slim pattern verbatim**, inline regex included
  (`/admin/invoices/{id:[0-9]+}/send`). A prettified path silently produces an orphan doc *and* an
  undocumented route rather than an error.
- **`php/tests/BillingApiDocsTest.php` asserts both directions** — the documented
  set and the registered set must be the same set, every path placeholder must
  be described, and a named permission must exist in `permissions()`. Adding or
  renaming a route without touching `docs/api.php` fails there. That is the
  point: prose next to code rots, and a reference full of confident, wrong
  detail is worse than the bare route list it replaced.

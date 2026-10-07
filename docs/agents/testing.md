# Testing

```bash
npm run test:run   # vitest; islands opt into jsdom per file via a @vitest-environment docblock
composer test      # phpunit
```

CI (`_build.yml`) runs both suites, plus type-check, `lint:primitives` and build, on
every run.

## Test environment against the current tds-shared

tds-shared is a **peer** dependency, so a fresh standalone install may resolve an old
version while every product build composes the current one. Keep these in mind:

- `apiFetch` first consults the host runtime config (`/tds-runtime.json`), so
  `fetch.mock.calls[0]` is that probe. Call **`primeRuntimeConfig(null)`** in
  `beforeEach`; panel products never ship that file, so "absent" matches production.
- `apiFetch` is **async**. `await waitFor(() => expect(fetch).toHaveBeenCalled())`
  before reading `mock.calls`.
- A multipart upload carries an **empty** `headers` object. Assert "no content-type
  header", never "headers is undefined".
- Error-path tests answer with a **populated** body and a non-OK status. Against an
  empty error body the ok-check is unobservable.
- At least one assertion per suite pins the **absolute API host** (see
  [frontend-conventions.md](frontend-conventions.md#api-calls-apifetch-never-a-relative-fetch)).

## Suites

| Suite | Sharpest assertions |
|---|---|
| `islands/BillingAdmin.test.tsx` | Send/delete only on `draft` (asserted for `open`, `paid`, `void`); euros → cents at the edges; quantity clamp; no amount-less line; `customer_id` null; reload after send either way; failures are danger toasts |
| `islands/BillingSettings.test.tsx` | Two secrets stay apart; both stored as secrets; masked on read; blank save keeps the value |
| `islands/BillingPortal.test.tsx` | Portal list and pay link |
| `islands/WidgetBody.test.tsx` | `—` on failure, never `0` |
| `src/index.test.ts` + `tests/packaging.test.ts` | Manifest as a product build sees it; every specifier resolves, is exported and ships |
| `php/tests/WebhookVerifierTest.php` | Real HMAC verification and replay guard |
| `php/tests/BillingModuleTest.php`, `BillingApiDocsTest.php` | Module RBAC (DB-free) and API-doc parity |

The quantity test that matters uses **`-3` via `fireEvent`**: `Number("0") || 1` already
yields 1, so a zero never reaches the clamp, and a `min="1"` number input won't accept a
typed minus.

Mutation check: 64 breakages, 64 caught.

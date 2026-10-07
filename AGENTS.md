# AGENTS.md — tds-ext-billing-pkg

Frontend extension for **Stripe invoicing**. Admins draft invoices with line items for a
customer and send them to Stripe as finalized, payable invoices. A signed Stripe webhook
marks them paid. Portal customers see their own invoices and the hosted pay link. It
contributes a PHP `Module` to `tds-core-frontend-api` and admin/portal views, settings and
a widget to the panel products.

Read `tds-frontend-contract-pkg/AGENTS.md` first. `tds-ext-lexware-pkg` and
`tds-ext-customers-pkg` show the same container-first Module + settings-store pattern.

## Commands

```bash
npm install --no-package-lock   # never npm ci
npm run type-check
npm run lint:primitives
npm run test:run                # vitest
npm run build                   # tsup
composer install && composer test   # phpunit (webhook HMAC, module RBAC; DB-free)
```

## Hard rules

- **Bind container entries unconditionally.** Never guard with `!$c->has(X::class)`; PHP-DI autowiring makes `has()` always true.
- The webhook verifies the **raw** body, never the parsed one.
- Send and delete exist only on a `draft` invoice.
- Amounts are typed in euros and sent in cents; quantity is clamped to ≥ 1.
- Send/delete outcomes are toasts; load failure and validation stay in-flow as `.tds-alert--danger`.
- Call the API with `apiFetch`, never a relative `fetch`. Never mount a `ToastHost`.
- Every route change updates `php/docs/api.php`. Migration names stay unique across extensions.
- No CSS; shared `tds-shared` classes only. Versions move via the release workflow only.

## Topic files

| File | Read before |
|---|---|
| [docs/agents/architecture.md](docs/agents/architecture.md) | Changing routes, permissions, Stripe wiring, settings or the data model |
| [docs/agents/backend.md](docs/agents/backend.md) | Touching PHP, DI bindings, migrations or `php/docs/api.php` |
| [docs/agents/frontend-conventions.md](docs/agents/frontend-conventions.md) | Touching any island, page or widget markup |
| [docs/agents/testing.md](docs/agents/testing.md) | Writing or changing tests |
| [docs/agents/release.md](docs/agents/release.md) | Releasing or changing CI |

Workspace rules: `../CLAUDE.md`. Cross-repo state: `../MIGRATION-STATUS.md`.

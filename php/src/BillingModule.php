<?php
declare(strict_types=1);

namespace Tds\Ext\Billing;

use PDO;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Tds\Ext\Billing\Domain\InvoiceRepository;
use Tds\Ext\Billing\Service\StripeClient;
use Tds\Frontend\Contract\Stripe\StripeException;
use Tds\Frontend\Contract\Stripe\StripeWebhook;
use Tds\Frontend\Contract\AbstractModule;
use Tds\Frontend\Contract\Commerce\SaleEvent;
use Tds\Frontend\Contract\Commerce\SaleEvents;
use Tds\Frontend\Contract\SetupStatusSource;
use Tds\Frontend\Contract\ApiDocSource;
use Tds\Frontend\Contract\PermissionDef;
use Tds\Frontend\Contract\SettingDef;
use Tds\Frontend\Contract\SettingsStore;
use Tds\Frontend\Contract\UserContext;
use Tds\Frontend\Contract\ModuleHttp;
use Tds\Frontend\Contract\Stripe\StripeWebhookDef;
use Tds\Frontend\Contract\Stripe\StripeWebhookSource;
use Tds\Frontend\Contract\Stripe\StripeApi;
use Tds\Frontend\Contract\Stripe\CurlStripeApi;

/**
 * Backend Module for Stripe billing/invoices. Admins draft invoices (line items,
 * for a customer from the tds-ext-customers directory), send them to Stripe
 * (creates a finalized, payable invoice), and a signed Stripe webhook marks them
 * paid. Portal customers see their own invoices + the hosted pay link.
 *
 * Auth via the core {@see UserContext}: reads `billing:read`, mutations
 * `billing:write` (admins bypass); the webhook is unauthenticated but
 * signature-verified. Config (Stripe keys, defaults) via the core
 * {@see SettingsStore} (ns=`billing`), DB-first with env fallback.
 */
final class BillingModule extends AbstractModule implements ApiDocSource, StripeWebhookSource, SetupStatusSource
{
    /** Kept from register() for setupItems(), which the base calls without one. */
    private ?\Psr\Container\ContainerInterface $setupContainer = null;

    use ModuleHttp;

    private const NS = 'billing';

    public function id(): string
    {
        return 'billing';
    }

    /** @return PermissionDef[] */
    public function permissions(): array
    {
        return [
            new PermissionDef('billing:read', 'Rechnungen ansehen', 'billing'),
            new PermissionDef('billing:write', 'Rechnungen erstellen & senden', 'billing'),
            // The portal key — see requirePortalRead() and the TS manifest.
            new PermissionDef('invoices:read', 'Eigene Rechnungen ansehen (Portal)', 'billing'),
        ];
    }

    /** @return string[] */
    public function migrations(): array
    {
        return [__DIR__ . '/../db/migrations'];
    }

    /** @return SettingDef[] */
    public function settings(): array
    {
        return [
            new SettingDef('stripe_secret_key', 'Stripe Secret Key (optional — leer = zentrales Konto)', true, 'billing'),
            new SettingDef('stripe_webhook_secret', 'Stripe Webhook Secret', true, 'billing'),
            new SettingDef('default_currency', 'Standard-Währung', false, 'billing', 'EUR'),
            new SettingDef('days_until_due', 'Zahlungsziel (Tage)', false, 'billing', '14'),
        ];
    }

    /** Listed in the admin panel under Einstellungen → Zahlungen (Stripe). */
    public function stripeWebhooks(): array
    {
        return [new StripeWebhookDef(
            'Rechnungen',
            '/billing/webhook',
            ['invoice.paid', 'invoice.payment_succeeded'],
            self::NS,
            'stripe_webhook_secret',
        )];
    }

    /**
     * What the panel's setup wizard should say about this module. Uses the
     * same check the feature itself runs, never a secret.
     *
     * @return list<array<string,string>>
     */
    public function setupItems(\Tds\Frontend\Contract\UserContext $user): array
    {
        $c = $this->setupContainer;
        if ($c === null) {
            return [];
        }
        $items = [];
        try {
            $secret = self::store($c)?->getSecret(self::NS, 'stripe_webhook_secret');
            $items[] = [
                'id' => 'billing:stripe-webhook',
                'module' => 'billing',
                'title' => 'Rechnungen: Stripe-Webhook',
                'description' => 'Ohne Webhook-Geheimnis meldet Stripe keine Zahlung zurück: bezahlte Rechnungen bleiben als offen stehen.',
                'state' => ($secret !== null && $secret !== '') || self::env('STRIPE_WEBHOOK_SECRET', '') !== '' ? 'ok' : 'missing',
                'level' => 'recommended',
                'href' => '/einstellungen#settings-stripe',
            ];
        } catch (\Throwable) {
        }
        return $items;
    }

    public function register(App $app): void
    {
        $c = $app->getContainer();
        $this->setupContainer = $c;
        // NEVER guard these with `!$c->has(X)`. PHP-DI answers `has()` from its
        // definition sources, and autowiring is one of them: for any *concrete,
        // instantiable* class the answer is always true, whether or not anyone
        // ever bound it. So the guard skipped both bindings and the container
        // silently autowired instead — invisible for the repository (its only
        // argument is the bound PDO, so the object is identical), fatal for the
        // StripeClient, whose constructor takes a string PHP-DI cannot guess:
        // `/billing/summary` — the dashboard widget — answered 500 with
        // `Parameter $secretKey of __construct() has no value defined or
        // guessable`, and the settings-store factory never ran at all. The
        // module owns these classes; nothing else defines them.
        if ($c !== null) {
            $c->set(InvoiceRepository::class, static fn ($c) => new InvoiceRepository($c->get(PDO::class)));
            $c->set(StripeClient::class, static function ($c): StripeClient {
                // Its own key overrides the platform account; otherwise the
                // central one from Einstellungen → Zahlungen (Stripe), which
                // itself falls back to STRIPE_SECRET_KEY on the host.
                $own = (string) (self::store($c)?->getSecret(self::NS, 'stripe_secret_key') ?? '');
                if ($own !== '') {
                    return new StripeClient(new CurlStripeApi($own));
                }
                $central = $c->has(StripeApi::class) ? $c->get(StripeApi::class) : null;
                return new StripeClient($central instanceof StripeApi
                    ? $central
                    : new CurlStripeApi(self::env('STRIPE_SECRET_KEY', '')));
            });
        }

        // Widget summary.
        $app->get('/billing/summary', function (Request $req, Response $res) use ($c): Response {
            $user = $c->get(UserContext::class);
            if (($deny = self::requirePortalRead($user, $res)) !== null) {
                return $deny;
            }
            // A portal user sees their company's count. It was the GLOBAL
            // number of open invoices for anyone holding billing:read.
            if ($user->isAdmin()) {
                $open = $c->get(InvoiceRepository::class)->openCount();
            } else {
                $cid = $user->activeCompanyId();
                $open = $cid === null ? 0 : $c->get(InvoiceRepository::class)->openCount((int) $cid);
            }
            // Whether Stripe is configured is the operator's concern; a customer
            // tile that said "Stripe nicht konfiguriert" told them nothing they
            // could act on.
            return self::json($res, $user->isAdmin()
                ? ['configured' => $c->get(StripeClient::class)->isConfigured(), 'open' => $open]
                : ['open' => $open]);
        });

        // --- Admin ------------------------------------------------------------
        $app->get('/admin/invoices', function (Request $req, Response $res) use ($c): Response {
            if (($deny = self::requireAdmin($c->get(UserContext::class), $res)) !== null) {
                return $deny;
            }
            return self::json($res, ['invoices' => $c->get(InvoiceRepository::class)->adminList()]);
        });

        $app->post('/admin/invoices', function (Request $req, Response $res) use ($c): Response {
            if (($deny = self::requireAdmin($c->get(UserContext::class), $res)) !== null) {
                return $deny;
            }
            $body = (array) $req->getParsedBody();
            $items = self::items($body['items'] ?? null);
            if ($items === []) {
                return self::json($res, ['error' => 'At least one line item (description + unit_amount_cents) is required'], 422);
            }
            $id = $c->get(InvoiceRepository::class)->createDraft(
                isset($body['customer_id']) && $body['customer_id'] !== '' ? (int) $body['customer_id'] : null,
                self::currency($c, $body['currency'] ?? null),
                self::optional($body['description'] ?? null, 500),
                self::optional($body['due_date'] ?? null, 10),
                $items,
            );
            return self::json($res, ['id' => $id], 201);
        });

        $app->get('/admin/invoices/{id:[0-9]+}', function (Request $req, Response $res, array $args) use ($c): Response {
            if (($deny = self::requireAdmin($c->get(UserContext::class), $res)) !== null) {
                return $deny;
            }
            $invoice = $c->get(InvoiceRepository::class)->find((int) $args['id']);
            return $invoice === null ? self::json($res, ['error' => 'Not found'], 404) : self::json($res, $invoice);
        });

        $app->post('/admin/invoices/{id:[0-9]+}/send', function (Request $req, Response $res, array $args) use ($c): Response {
            if (($deny = self::requireAdmin($c->get(UserContext::class), $res)) !== null) {
                return $deny;
            }
            $repo = $c->get(InvoiceRepository::class);
            $pdo = $c->get(PDO::class);
            // One send per invoice at a time: a double click used to create
            // two Stripe invoices, both checks having seen `draft`. A named
            // lock needs no schema change and is released with the connection.
            $lockName = 'tds-billing-send-' . (int) $args['id'];
            $lock = $pdo->prepare('SELECT GET_LOCK(:n, 0)');
            $lock->execute([':n' => $lockName]);
            if ((int) $lock->fetchColumn() !== 1) {
                return self::json($res, ['error' => 'Diese Rechnung wird gerade gesendet.'], 409);
            }
            $release = static function () use ($pdo, $lockName): void {
                $pdo->prepare('SELECT RELEASE_LOCK(:n)')->execute([':n' => $lockName]);
            };
            $invoice = $repo->find((int) $args['id']);
            if ($invoice === null) {
                $release();
                return self::json($res, ['error' => 'Not found'], 404);
            }
            if ($invoice['status'] !== 'draft') {
                $release();
                return self::json($res, ['error' => 'Nur Entwürfe können gesendet werden.'], 409);
            }
            $client = $c->get(StripeClient::class);
            if (!$client->isConfigured()) {
                $release();
                return self::json($res, ['error' => 'Stripe Secret Key nicht konfiguriert'], 503);
            }
            $body = (array) $req->getParsedBody();
            [$name, $email] = self::customerContact($pdo, $invoice['customer_id'], $body);
            if ($name === '') {
                $release();
                return self::json($res, ['error' => 'Kein Kunde/Name für die Rechnung (customer_id oder name/email angeben).'], 422);
            }
            try {
                $result = $client->createInvoice(
                    $name,
                    $email,
                    $invoice['items'],
                    $invoice['currency'],
                    (int) self::setting($c, 'days_until_due', 'STRIPE_DAYS_UNTIL_DUE', '14'),
                );
            } catch (StripeException $e) {
                $release();
                return self::json($res, ['error' => $e->getMessage()], 502);
            }
            $repo->markSent((int) $args['id'], $result['stripe_invoice_id'], $result['payment_intent_id'], $result['hosted_invoice_url']);
            $release();
            return self::json($res, [
                'stripe_invoice_id' => $result['stripe_invoice_id'],
                'hosted_invoice_url' => $result['hosted_invoice_url'],
                'status' => $result['status'],
            ], 201);
        });

        $app->delete('/admin/invoices/{id:[0-9]+}', function (Request $req, Response $res, array $args) use ($c): Response {
            if (($deny = self::requireAdmin($c->get(UserContext::class), $res)) !== null) {
                return $deny;
            }
            $repo = $c->get(InvoiceRepository::class);
            if ($repo->delete((int) $args['id'])) {
                return self::json($res, ['ok' => true]);
            }
            return $repo->find((int) $args['id']) === null
                ? self::json($res, ['error' => 'Not found'], 404)
                : self::json($res, ['error' => 'Gesendete oder bezahlte Rechnungen werden nicht gelöscht.'], 409);
        });

        // --- Portal (customer's own invoices) ---------------------------------
        $app->get('/billing/invoices', function (Request $req, Response $res) use ($c): Response {
            $user = $c->get(UserContext::class);
            if (($deny = self::requirePortalRead($user, $res)) !== null) {
                return $deny;
            }
            $cid = $user->activeCompanyId();
            $invoices = $cid === null ? [] : $c->get(InvoiceRepository::class)->listForCustomer($cid);
            return self::json($res, ['invoices' => $invoices]);
        });

        $app->get('/billing/invoices/{id:[0-9]+}', function (Request $req, Response $res, array $args) use ($c): Response {
            $user = $c->get(UserContext::class);
            if (($deny = self::requirePortalRead($user, $res)) !== null) {
                return $deny;
            }
            $invoice = $c->get(InvoiceRepository::class)->find((int) $args['id']);
            // Drafts are internal: the list hides them, and so must the
            // detail — guessing an id opened a draft before it was sent.
            if (
                $invoice === null
                || (!$user->isAdmin() && ($invoice['customer_id'] !== $user->activeCompanyId() || $invoice['status'] === 'draft'))
            ) {
                return self::json($res, ['error' => 'Not found'], 404);
            }
            return self::json($res, $invoice);
        });

        // --- Stripe webhook (unauthenticated; signature-verified) -------------
        $app->post('/billing/webhook', function (Request $req, Response $res) use ($c): Response {
            $secret = self::store($c)?->getSecret(self::NS, 'stripe_webhook_secret');
            if ($secret === null || $secret === '') {
                $secret = self::env('STRIPE_WEBHOOK_SECRET', '');
            }
            if ($secret === '') {
                return self::json($res, ['error' => 'Webhook secret not configured'], 503);
            }
            $payload = (string) $req->getBody();
            if (!StripeWebhook::verify($payload, $req->getHeaderLine('Stripe-Signature'), $secret)) {
                return self::json($res, ['error' => 'Invalid signature'], 400);
            }
            $event = json_decode($payload, true);
            $type = is_array($event) ? (string) ($event['type'] ?? '') : '';
            if (in_array($type, ['invoice.paid', 'invoice.payment_succeeded'], true)) {
                $stripeId = (string) ($event['data']['object']['id'] ?? '');
                if ($stripeId !== '') {
                    $repo = $c->get(InvoiceRepository::class);
                    $id = $repo->markPaidByStripeId($stripeId);
                    if ($id !== null) {
                        self::reportPaid($c, $id, $repo);
                    }
                }
            }
            return self::json($res, ['received' => true]);
        });
    }

    // --- helpers ---------------------------------------------------------------

    /**
     * Resolve a customer name + email for a Stripe invoice: request body override,
     * else the tds-ext-customers `customer` row (queried defensively — the table
     * may be absent). @return array{0:string,1:?string} [name, email]
     */
    private static function customerContact(PDO $pdo, ?int $customerId, array $body): array
    {
        $name = trim((string) ($body['name'] ?? ''));
        $email = strtolower(trim((string) ($body['email'] ?? '')));
        if ($name === '' && $customerId !== null) {
            try {
                // `company`: tds-ext-customers renamed the table
                // (20260719100002). Against `customer` the query failed, the
                // catch swallowed it, and sending by customer_id was a 422.
                $stmt = $pdo->prepare('SELECT name, email FROM company WHERE id = :id');
                $stmt->execute([':id' => $customerId]);
                $row = $stmt->fetch();
                if ($row !== false) {
                    $name = (string) $row['name'];
                    if ($email === '' && $row['email'] !== null) {
                        $email = (string) $row['email'];
                    }
                }
            } catch (\Throwable) {
                // customers extension not present — fall back to the body values.
            }
        }
        return [$name, $email === '' ? null : $email];
    }

    /**
     * Normalise line items. @param mixed $raw
     * @return array<int,array{description:string,quantity:int,unit_amount_cents:int}>
     */
    private static function items(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $it) {
            if (!is_array($it)) {
                continue;
            }
            $desc = trim((string) ($it['description'] ?? ''));
            $unit = (int) ($it['unit_amount_cents'] ?? 0);
            if ($desc === '' || $unit <= 0) {
                continue;
            }
            $out[] = [
                'description' => mb_substr($desc, 0, 300),
                'quantity' => max(1, (int) ($it['quantity'] ?? 1)),
                'unit_amount_cents' => $unit,
            ];
        }
        return $out;
    }

    private static function currency(ContainerInterface $c, mixed $value): string
    {
        $v = strtoupper(trim((string) ($value ?? '')));
        if (preg_match('/^[A-Z]{3}$/', $v) === 1) {
            return $v;
        }
        return strtoupper(self::setting($c, 'default_currency', 'STRIPE_DEFAULT_CURRENCY', 'EUR'));
    }

    private static function setting(ContainerInterface $c, string $key, string $envKey, string $default): string
    {
        $v = self::store($c)?->get(self::NS, $key);
        if ($v !== null && $v !== '') {
            return $v;
        }
        return self::env($envKey, $default);
    }

    /**
     * Tell other modules (the referral programme) that an invoice is paid —
     * on every delivery: listeners are idempotent by (source, id), and a
     * failure here must never turn the webhook into a retry loop.
     */
    private static function reportPaid(ContainerInterface $c, int $id, InvoiceRepository $repo): void
    {
        try {
            $events = $c->has(SaleEvents::class) ? $c->get(SaleEvents::class) : null;
            if (!$events instanceof SaleEvents) {
                return;
            }
            $invoice = $repo->find($id);
            $events->paid(new SaleEvent('billing', (string) $id, (int) ($invoice['total_cents'] ?? 0)));
        } catch (\Throwable $e) {
            error_log('[tds-billing] sale event failed: ' . $e->getMessage());
        }
    }

    private static function store(ContainerInterface $c): ?SettingsStore
    {
        return $c->has(SettingsStore::class) ? $c->get(SettingsStore::class) : null;
    }

    /** Env read with explicit default — avoids the `?? getenv() ?: $d` precedence trap ("0"/""). */
    private static function env(string $key, string $default): string
    {
        $v = getenv($key);
        return $v === false ? $default : $v;
    }

    private static function optional(mixed $value, int $limit): ?string
    {
        $v = trim((string) ($value ?? ''));
        return $v === '' ? null : mb_substr($v, 0, $limit);
    }

    /**
     * Route documentation for the admin frontend's API reference. Kept in its
     * own file so the prose does not sit in the middle of the wiring.
     *
     * @return list<array<string, mixed>>
     */
    public function apiDocs(): array
    {
        return require __DIR__ . '/../docs/api.php';
    }

    /**
     * The portal's own invoices: `billing:read` OR the portal key `invoices:read`.
     *
     * Every other portal module took over the portal's permission names
     * (`projects:read`, `documents:read`, `tickets:read`, …); billing alone
     * introduced `billing:read`. The auth API's system groups — Vollzugriff,
     * Buchhaltung, Nur Lesen — and tds-shared's PORTAL_PERMISSIONS grant
     * `invoices:read`, so a customer in exactly the group meant for invoices
     * was refused their own. Both keys open only the ACTIVE company's sent
     * invoices; the admin routes stay on `billing:read`/`billing:write`.
     */
    private static function requirePortalRead(UserContext $user, Response $res): ?Response
    {
        if (!$user->isAuthenticated()) {
            return self::json($res, ['error' => 'Unauthorized'], 401);
        }
        if (!$user->has('billing:read') && !$user->has('invoices:read')) {
            return self::json($res, ['error' => 'Forbidden'], 403);
        }
        return null;
    }
}

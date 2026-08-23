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
use Tds\Ext\Billing\Service\StripeException;
use Tds\Ext\Billing\Service\WebhookVerifier;
use Tds\Frontend\Contract\AbstractModule;
use Tds\Frontend\Contract\ApiDocSource;
use Tds\Frontend\Contract\PermissionDef;
use Tds\Frontend\Contract\SettingDef;
use Tds\Frontend\Contract\SettingsStore;
use Tds\Frontend\Contract\UserContext;

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
final class BillingModule extends AbstractModule implements ApiDocSource
{
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
            new SettingDef('stripe_secret_key', 'Stripe Secret Key', true, 'billing'),
            new SettingDef('stripe_webhook_secret', 'Stripe Webhook Secret', true, 'billing'),
            new SettingDef('default_currency', 'Standard-Währung', false, 'billing', 'EUR'),
            new SettingDef('days_until_due', 'Zahlungsziel (Tage)', false, 'billing', '14'),
        ];
    }

    public function register(App $app): void
    {
        $c = $app->getContainer();
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
                $key = self::store($c)?->getSecret(self::NS, 'stripe_secret_key');
                if ($key === null || $key === '') {
                    $key = self::env('STRIPE_SECRET_KEY', '');
                }
                return new StripeClient($key);
            });
        }

        // Widget summary.
        $app->get('/billing/summary', function (Request $req, Response $res) use ($c): Response {
            if (($deny = self::require($c->get(UserContext::class), 'billing:read', $res)) !== null) {
                return $deny;
            }
            return self::json($res, [
                'configured' => $c->get(StripeClient::class)->isConfigured(),
                'open' => $c->get(InvoiceRepository::class)->openCount(),
            ]);
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
            $invoice = $repo->find((int) $args['id']);
            if ($invoice === null) {
                return self::json($res, ['error' => 'Not found'], 404);
            }
            if ($invoice['status'] !== 'draft') {
                return self::json($res, ['error' => 'Nur Entwürfe können gesendet werden.'], 409);
            }
            $client = $c->get(StripeClient::class);
            if (!$client->isConfigured()) {
                return self::json($res, ['error' => 'Stripe Secret Key nicht konfiguriert'], 503);
            }
            $body = (array) $req->getParsedBody();
            [$name, $email] = self::customerContact($c->get(PDO::class), $invoice['customer_id'], $body);
            if ($name === '') {
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
                return self::json($res, ['error' => $e->getMessage()], 502);
            }
            $repo->markSent((int) $args['id'], $result['stripe_invoice_id'], $result['payment_intent_id'], $result['hosted_invoice_url']);
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
            $c->get(InvoiceRepository::class)->delete((int) $args['id']);
            return self::json($res, ['ok' => true]);
        });

        // --- Portal (customer's own invoices) ---------------------------------
        $app->get('/billing/invoices', function (Request $req, Response $res) use ($c): Response {
            $user = $c->get(UserContext::class);
            if (($deny = self::require($user, 'billing:read', $res)) !== null) {
                return $deny;
            }
            $cid = $user->activeCompanyId();
            $invoices = $cid === null ? [] : $c->get(InvoiceRepository::class)->listForCustomer($cid);
            return self::json($res, ['invoices' => $invoices]);
        });

        $app->get('/billing/invoices/{id:[0-9]+}', function (Request $req, Response $res, array $args) use ($c): Response {
            $user = $c->get(UserContext::class);
            if (($deny = self::require($user, 'billing:read', $res)) !== null) {
                return $deny;
            }
            $invoice = $c->get(InvoiceRepository::class)->find((int) $args['id']);
            if ($invoice === null || (!$user->isAdmin() && $invoice['customer_id'] !== $user->activeCompanyId())) {
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
            if (!WebhookVerifier::verify($payload, $req->getHeaderLine('Stripe-Signature'), $secret)) {
                return self::json($res, ['error' => 'Invalid signature'], 400);
            }
            $event = json_decode($payload, true);
            $type = is_array($event) ? (string) ($event['type'] ?? '') : '';
            if (in_array($type, ['invoice.paid', 'invoice.payment_succeeded'], true)) {
                $stripeId = (string) ($event['data']['object']['id'] ?? '');
                if ($stripeId !== '') {
                    $c->get(InvoiceRepository::class)->markPaidByStripeId($stripeId);
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
                $stmt = $pdo->prepare('SELECT name, email FROM customer WHERE id = :id');
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

    private static function require(UserContext $user, string $permission, Response $res): ?Response
    {
        if (!$user->isAuthenticated()) {
            return self::json($res, ['error' => 'Unauthorized'], 401);
        }
        if (!$user->has($permission)) {
            return self::json($res, ['error' => 'Forbidden'], 403);
        }
        return null;
    }

    private static function requireAdmin(UserContext $user, Response $res): ?Response
    {
        if (!$user->isAuthenticated()) {
            return self::json($res, ['error' => 'Unauthorized'], 401);
        }
        if (!$user->isAdmin()) {
            return self::json($res, ['error' => 'Forbidden'], 403);
        }
        return null;
    }

    private static function json(Response $res, mixed $data, int $status = 200): Response
    {
        $res->getBody()->write(json_encode($data, JSON_THROW_ON_ERROR));
        return $res->withStatus($status)->withHeader('Content-Type', 'application/json');
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
}

<?php
declare(strict_types=1);

namespace Tds\Ext\Billing\Tests;

use DI\Container;
use PHPUnit\Framework\TestCase;
use Slim\App;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tds\Ext\Billing\BillingModule;
use Tds\Frontend\Contract\Commerce\SaleEvent;
use Tds\Frontend\Contract\Commerce\SaleEvents;
use Tds\Frontend\Contract\Commerce\SaleListener;
use Tds\Frontend\Contract\UserContext;

/** A configurable UserContext double. */
final class FakeUser implements UserContext
{
    /** @param string[] $perms */
    public function __construct(
        private bool $auth = true,
        private bool $admin = false,
        private array $perms = [],
        private ?int $company = null,
    ) {
    }

    public function isAuthenticated(): bool
    {
        return $this->auth;
    }

    public function userId(): ?int
    {
        return 1;
    }

    public function email(): ?string
    {
        return null;
    }

    public function isAdmin(): bool
    {
        return $this->admin;
    }

    /** @return string[] */
    public function permissions(): array
    {
        return $this->perms;
    }

    public function has(string $permission): bool
    {
        return $this->admin || in_array($permission, $this->perms, true);
    }

    public function activeCompanyId(): ?int
    {
        return $this->company;
    }
}

/**
 * Route + RBAC coverage without a DB: auth + payload/config checks short-circuit
 * before any repository or Stripe access.
 */
final class BillingModuleTest extends TestCase
{
    private function appWith(UserContext $user): App
    {
        $container = new Container();
        $container->set(UserContext::class, $user);
        AppFactory::setContainer($container);
        $app = AppFactory::create();
        $app->addBodyParsingMiddleware();
        $app->addRoutingMiddleware();
        (new BillingModule())->register($app);
        return $app;
    }

    private function get(App $app, string $path): \Psr\Http\Message\ResponseInterface
    {
        return $app->handle((new ServerRequestFactory())->createServerRequest('GET', $path));
    }

    /** @param array<string,mixed> $body */
    private function post(App $app, string $path, array $body, array $headers = []): \Psr\Http\Message\ResponseInterface
    {
        $req = (new ServerRequestFactory())->createServerRequest('POST', $path)
            ->withHeader('Content-Type', 'application/json')
            ->withParsedBody($body);
        foreach ($headers as $k => $v) {
            $req = $req->withHeader($k, $v);
        }
        return $app->handle($req);
    }

    public function testMetadata(): void
    {
        $m = new BillingModule();
        self::assertSame('billing', $m->id());
        self::assertSame(['billing:read', 'billing:write', 'invoices:read'], array_map(static fn ($p): string => $p->id, $m->permissions()));
        self::assertDirectoryExists($m->migrations()[0]);
        self::assertSame(
            ['stripe_secret_key', 'stripe_webhook_secret', 'default_currency', 'days_until_due'],
            array_map(static fn ($s): string => $s->key, $m->settings()),
        );
    }

    public function testSummaryRequiresAuth(): void
    {
        self::assertSame(401, $this->get($this->appWith(new FakeUser(auth: false)), '/billing/summary')->getStatusCode());
    }

    public function testSummaryServesThePortalWithoutOperatorDetail(): void
    {
        // A customer holding the portal key gets their count — and nothing
        // about whether Stripe is configured, which is not theirs to act on.
        $res = $this->get($this->appWith(new FakeUser(perms: ['invoices:read'], company: null)), '/billing/summary');
        self::assertSame(200, $res->getStatusCode());
        self::assertSame(['open' => 0], json_decode((string) $res->getBody(), true));
    }

    public function testAdminListRequiresAdmin(): void
    {
        self::assertSame(403, $this->get($this->appWith(new FakeUser(perms: ['billing:read'])), '/admin/invoices')->getStatusCode());
    }

    public function testCreateInvoiceValidatesItems(): void
    {
        // admin passes; empty items rejected before any DB access.
        $res = $this->post($this->appWith(new FakeUser(admin: true)), '/admin/invoices', ['items' => []]);
        self::assertSame(422, $res->getStatusCode());
    }

    public function testPortalListRequiresPermission(): void
    {
        self::assertSame(403, $this->get($this->appWith(new FakeUser(perms: [])), '/billing/invoices')->getStatusCode());
    }

    public function testPortalListAcceptsThePortalKey(): void
    {
        // The auth API's system groups (Vollzugriff, Buchhaltung, Nur Lesen)
        // grant `invoices:read`, the portal's own key — not `billing:read`.
        $res = $this->get($this->appWith(new FakeUser(perms: ['invoices:read'], company: null)), '/billing/invoices');
        self::assertSame(200, $res->getStatusCode());
        // …and it opens nothing on the admin side.
        self::assertSame(403, $this->get($this->appWith(new FakeUser(perms: ['invoices:read'])), '/admin/invoices')->getStatusCode());
    }

    public function testPortalListEmptyWithoutCompany(): void
    {
        // reader with no active company → [] without touching the DB.
        $res = $this->get($this->appWith(new FakeUser(perms: ['billing:read'], company: null)), '/billing/invoices');
        self::assertSame(200, $res->getStatusCode());
        self::assertSame(['invoices' => []], json_decode((string) $res->getBody(), true));
    }

    public function testWebhookRequiresConfiguredSecret(): void
    {
        putenv('STRIPE_WEBHOOK_SECRET');
        $res = $this->post($this->appWith(new FakeUser(auth: false)), '/billing/webhook', []);
        self::assertSame(503, $res->getStatusCode());
    }

    public function testWebhookRejectsBadSignature(): void
    {
        putenv('STRIPE_WEBHOOK_SECRET=whsec_x');
        $res = $this->post(
            $this->appWith(new FakeUser(auth: false)),
            '/billing/webhook',
            ['type' => 'invoice.paid'],
            ['Stripe-Signature' => 't=1,v1=bogus'],
        );
        self::assertSame(400, $res->getStatusCode());
        putenv('STRIPE_WEBHOOK_SECRET');
    }

    public function testAPaidInvoiceIsReportedAsASaleOnEveryDelivery(): void
    {
        // SQLite stands in for the one table the webhook touches; NOW() is
        // MySQL's, so it is registered here. Only the columns the repository
        // reads — this checks the hand-over, not the schema.
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        $pdo->sqliteCreateFunction('NOW', static fn (): string => '2026-10-11 12:00:00', 0);
        $pdo->exec('CREATE TABLE billing_invoice (id INTEGER PRIMARY KEY, customer_id INTEGER, currency TEXT,
            status TEXT, description TEXT, total_cents INTEGER, due_date TEXT, stripe_invoice_id TEXT,
            hosted_invoice_url TEXT, paid_at TEXT, created_at TEXT)');
        $pdo->exec('CREATE TABLE billing_invoice_item (invoice_id INTEGER, description TEXT, quantity INTEGER,
            unit_amount_cents INTEGER, sort_order INTEGER)');
        $pdo->exec("INSERT INTO billing_invoice VALUES (55, NULL, 'EUR', 'open', 'Website', 120000, NULL,
            'in_123', NULL, NULL, '2026-10-01 10:00:00')");

        $seen = new \ArrayObject();
        $listener = new class ($seen) implements SaleListener {
            public function __construct(private readonly \ArrayObject $seen)
            {
            }

            public function onSalePaid(SaleEvent $sale): void
            {
                $this->seen[] = "{$sale->source}:{$sale->sourceId}:{$sale->netCents}";
            }

            public function onSaleReversed(string $source, string $sourceId): void
            {
            }
        };

        $container = new Container();
        $container->set(UserContext::class, new FakeUser(auth: false));
        $container->set(\PDO::class, $pdo);
        $container->set(SaleEvents::class, new SaleEvents([$listener]));
        AppFactory::setContainer($container);
        $app = AppFactory::create();
        $app->addRoutingMiddleware();
        (new BillingModule())->register($app);

        putenv('STRIPE_WEBHOOK_SECRET=whsec_x');
        $payload = '{"type":"invoice.paid","data":{"object":{"id":"in_123"}}}';
        $t = time();
        $sig = 't=' . $t . ',v1=' . hash_hmac('sha256', $t . '.' . $payload, 'whsec_x');
        foreach ([1, 2] as $_) {
            $req = (new ServerRequestFactory())->createServerRequest('POST', '/billing/webhook')
                ->withHeader('Stripe-Signature', $sig);
            $req->getBody()->write($payload);
            self::assertSame(200, $app->handle($req)->getStatusCode());
        }
        putenv('STRIPE_WEBHOOK_SECRET');

        self::assertSame(['billing:55:120000', 'billing:55:120000'], $seen->getArrayCopy());
        self::assertSame('paid', $pdo->query('SELECT status FROM billing_invoice WHERE id = 55')->fetchColumn());
    }
}

<?php
declare(strict_types=1);

namespace Tds\Ext\Billing\Tests;

use DI\Container;
use PHPUnit\Framework\TestCase;
use Slim\App;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tds\Ext\Billing\BillingModule;
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
        self::assertSame(['billing:read', 'billing:write'], array_map(static fn ($p): string => $p->id, $m->permissions()));
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
}

<?php

declare(strict_types=1);

namespace ContabilFlow\Tests\Integration\Http;

use ContabilFlow\Core\Response;
use ContabilFlow\Tests\Support\Http\HttpTestCase;
use ContabilFlow\Tests\Support\Http\RouteCatalog;
use ContabilFlow\Tests\Support\Http\TestBrowser;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Varre TODAS as rotas: o catálogo bate com o Router, e cada rota exige login,
 * token CSRF, perfil e devolve os cabeçalhos de segurança.
 *
 * @phpstan-type Route array{method: string, pattern: string, uri: string, access: string, json?: bool}
 */
final class RouteSecurityTest extends HttpTestCase
{
    /** @param Route $route */
    private function call(TestBrowser $browser, array $route, bool $csrf = true, bool $json = false): Response
    {
        $headers = ($json || !empty($route['json'])) ? ['Accept' => 'application/json'] : [];

        return $route['method'] === 'GET'
            ? $browser->get($route['uri'], $headers)
            : $browser->send($route['method'], $route['uri'], [], $headers, $csrf);
    }

    // ---------- o catálogo cobre tudo ----------

    public function testCatalogMatchesEveryRouteRegisteredInTheRouter(): void
    {
        $registered = array_map(
            static fn(array $r): string => $r['method'] . ' ' . $r['pattern'],
            $this->browser()->app()->router->routes()
        );
        $catalog = array_map(
            static fn(array $r): string => $r['method'] . ' ' . $r['pattern'],
            RouteCatalog::ROUTES
        );
        sort($registered);
        sort($catalog);

        $this->assertSame($registered, $catalog, 'Rota nova precisa entrar no RouteCatalog e ser testada.');
    }

    public function testCatalogHasNoDuplicatedEntries(): void
    {
        $keys = array_map(static fn(array $r): string => $r['method'] . ' ' . $r['pattern'], RouteCatalog::ROUTES);

        $this->assertSame($keys, array_values(array_unique($keys)));
    }

    // ---------- login obrigatório ----------

    /** @param Route $route */
    #[DataProvider('nonPublicRoutes')]
    public function testAnonymousVisitorIsSentToLoginOrGets401OnJsonRoutes(array $route): void
    {
        $browser = $this->browser();
        $browser->token(); // visitante que já abriu uma página (tem token), como um navegador real

        $response = $this->call($browser, $route);

        if (!empty($route['json'])) {
            $this->assertSame(401, $response->status);

            return;
        }
        $this->assertRedirectTo('/login', $response);
    }

    /** @param Route $route */
    #[DataProvider('nonPublicRoutes')]
    public function testAnonymousApiClientsGet401WhenAskingForJson(array $route): void
    {
        $response = $this->call($this->browser(), $route, true, true);

        if ($route['method'] === 'GET' || !empty($route['json'])) {
            $this->assertSame(401, $response->status);
            $this->assertSame('UNAUTHENTICATED', $this->json($response)['error']['code']);
        } else {
            $this->assertContains($response->status, [401, 419]);
        }
    }

    /** @param Route $route */
    #[DataProvider('unsafeNonPublicRoutes')]
    public function testAnonymousPostWithoutTokenIsRejectedByCsrfBeforeAnythingElse(array $route): void
    {
        $response = $this->call($this->browser(), $route, false);

        $this->assertSame(419, $response->status);
    }

    // ---------- usuário logado passa ----------

    /** @param Route $route */
    #[DataProvider('userRoutes')]
    public function testLoggedInStaffReachesEveryUserRoute(array $route): void
    {
        foreach ([$this->contador(), $this->admin()] as $browser) {
            $response = $this->call($browser, $route);

            $this->assertLessThan(500, $response->status, "{$route['method']} {$route['uri']}");
            $this->assertNotContains($response->status, [401, 403, 405, 419], "{$route['method']} {$route['uri']}");
        }
    }

    // ---------- CSRF ----------

    /** @param Route $route */
    #[DataProvider('unsafeNonPublicRoutes')]
    public function testEveryStateChangingRouteRejectsRequestsWithoutCsrfToken(array $route): void
    {
        $browser = $this->admin();

        $response = $this->call($browser, $route, false);

        $this->assertSame(419, $response->status, "{$route['method']} {$route['uri']}");
    }

    /** @param Route $route */
    #[DataProvider('unsafeNonPublicRoutes')]
    public function testCsrfFailureIsJsonForApiClients(array $route): void
    {
        $response = $this->call($this->admin(), $route, false, true);

        $this->assertSame(419, $response->status);
        $this->assertSame('CSRF_INVALID', $this->json($response)['error']['code']);
    }

    public function testAWrongTokenIsRejectedEvenWhenAnotherSessionsTokenIsUsed(): void
    {
        $alice = $this->contador();
        $mallory = $this->admin();

        $response = $alice->post('/empresas', ['_csrf' => $mallory->token()], [], false);

        $this->assertSame(419, $response->status);
    }

    public function testTokenFromTheHeaderIsAcceptedForApiCalls(): void
    {
        $response = $this->contador()->api('POST', '/api/obrigacoes/1/lembretes', ['canal' => 'copiado', 'mensagem' => 'x']);

        $this->assertSame(201, $response->status);
    }

    // ---------- perfil ----------

    /** @param Route $route */
    #[DataProvider('adminRoutes')]
    public function testAdminOnlyRoutesAreForbiddenForAccountants(array $route): void
    {
        $response = $this->call($this->contador(), $route);

        $this->assertSame(403, $response->status, "{$route['method']} {$route['uri']}");
    }

    /** @param Route $route */
    #[DataProvider('adminRoutes')]
    public function testAdminOnlyRoutesAreOpenToAdministrators(array $route): void
    {
        $response = $this->call($this->admin(), $route);

        $this->assertLessThan(500, $response->status);
        $this->assertNotContains($response->status, [401, 403, 405, 419]);
    }

    public function testForbiddenResponsesAreJsonForApiClientsAndNeverLeakTheRoute(): void
    {
        $response = $this->contador()->get('/usuarios', ['Accept' => 'application/json']);

        $this->assertSame(403, $response->status);
        $this->assertSame('FORBIDDEN', $this->json($response)['error']['code']);
    }

    public function testNavigationShowsAdminLinksOnlyToAdministrators(): void
    {
        $admin = $this->admin()->get('/')->body;
        $contador = $this->contador()->get('/')->body;

        $this->assertStringContainsString('href="/usuarios"', $admin);
        $this->assertStringContainsString('href="/avaliacoes/resumo"', $admin);
        $this->assertStringNotContainsString('href="/usuarios"', $contador);
        $this->assertStringNotContainsString('href="/avaliacoes/resumo"', $contador);
        $this->assertStringContainsString('href="/avaliacao"', $contador);
    }

    // ---------- cabeçalhos de segurança ----------

    /** @param Route $route */
    #[DataProvider('allRoutes')]
    public function testEveryResponseCarriesTheSecurityHeaders(array $route): void
    {
        $response = $this->call($this->admin(), $route);

        $this->assertStringContainsString("default-src 'self'", $response->headers['Content-Security-Policy'] ?? '');
        $this->assertStringContainsString("frame-ancestors 'none'", $response->headers['Content-Security-Policy'] ?? '');
        $this->assertSame('DENY', $response->headers['X-Frame-Options'] ?? null);
        $this->assertSame('nosniff', $response->headers['X-Content-Type-Options'] ?? null);
        $this->assertSame('strict-origin-when-cross-origin', $response->headers['Referrer-Policy'] ?? null);
    }

    public function testSecurityHeadersAlsoCoverRedirectsAndErrors(): void
    {
        $browser = $this->browser();

        foreach ([$browser->get('/empresas'), $browser->get('/nao-existe'), $browser->post('/empresas', [], [], false)] as $r) {
            $this->assertSame('DENY', $r->headers['X-Frame-Options'] ?? null, 'status ' . $r->status);
        }
    }

    public function testPagesNeverUseInlineScriptsOrStylesBecauseTheCspForbidsThem(): void
    {
        $admin = $this->admin();
        $pages = ['/', '/empresas', '/empresas/1', '/empresas/nova', '/obrigacoes', '/obrigacoes/1', '/obrigacoes/nova',
            '/usuarios', '/usuarios/novo', '/avaliacao', '/avaliacoes/resumo', '/login'];

        foreach ($pages as $page) {
            $body = $admin->get($page)->body;

            $this->assertDoesNotMatchRegularExpression('/<script(?![^>]*\ssrc=)/i', $body, $page);
            $this->assertDoesNotMatchRegularExpression('/\sstyle=/i', $body, $page);
            $this->assertDoesNotMatchRegularExpression('/\son(click|load|error|submit)=/i', $body, $page);
            $this->assertStringNotContainsString('https://', $body, $page);
        }
    }

    // ---------- roteamento ----------

    public function testUnknownPathsAre404ForLoggedInUsers(): void
    {
        $admin = $this->admin();

        foreach (['/nao-existe', '/empresas/abc', '/obrigacoes/xyz/editar', '/api/obrigacoes/1/outra', '/usuarios/1', '/.env'] as $path) {
            $this->assertSame(404, $admin->get($path)->status, $path);
        }
    }

    public function testWrongMethodsAre405WithAnAllowHeader(): void
    {
        $admin = $this->admin();

        $delete = $admin->delete('/empresas');
        $put = $admin->put('/obrigacoes');
        $postDetail = $admin->post('/dashboard');

        $this->assertSame(405, $delete->status);
        $this->assertSame('GET, POST', $delete->headers['Allow'] ?? null);
        $this->assertSame(405, $put->status);
        $this->assertSame(405, $postDetail->status);
    }

    public function testMethodOverrideIsOnlyHonouredOnPost(): void
    {
        $admin = $this->admin();

        $response = $admin->get('/empresas/3?_method=DELETE');

        $this->assertSame(200, $response->status);
        $this->assertSame(3, $this->rows('empresas'));
    }

    public function testUnknownMethodOverrideValuesAreIgnored(): void
    {
        $admin = $this->admin();

        $response = $admin->post('/empresas/1', ['_method' => 'TRACE']);

        $this->assertSame(405, $response->status); // continua POST, e POST /empresas/1 não existe
    }

    public function testStaticAssetsAndEnvAreNotServedByTheApp(): void
    {
        $this->assertSame(404, $this->admin()->get('/.env')->status);
        $this->assertSame(404, $this->admin()->get('/../.env')->status);
    }

    // ---------- provedores ----------

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function nonPublicRoutes(): array
    {
        return RouteCatalog::nonPublic();
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function unsafeNonPublicRoutes(): array
    {
        return RouteCatalog::unsafeNonPublic();
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function adminRoutes(): array
    {
        return RouteCatalog::adminOnly();
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function userRoutes(): array
    {
        return RouteCatalog::provider(RouteCatalog::USER);
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function allRoutes(): array
    {
        return RouteCatalog::all();
    }
}

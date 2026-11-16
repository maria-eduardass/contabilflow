<?php

declare(strict_types=1);

namespace ContabilFlow\Tests\Unit;

use ContabilFlow\Controllers\DashboardController;
use ContabilFlow\Core\Csrf;
use ContabilFlow\Core\Request;
use ContabilFlow\Core\Session;
use ContabilFlow\Core\View;
use ContabilFlow\Services\DashboardService;
use ContabilFlow\Services\SemaforoService;
use ContabilFlow\Tests\Support\DashboardScenario;
use ContabilFlow\Tests\Support\FixedClock;
use ContabilFlow\Tests\Support\InMemoryEmpresaRepository;
use ContabilFlow\Tests\Support\InMemoryObrigacaoRepository;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class DashboardControllerTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $sessionData = ['user_id' => 1, 'perfil' => 'contador', 'nome' => 'Ana'];
    private Session $session;
    private InMemoryEmpresaRepository $empresas;
    private InMemoryObrigacaoRepository $guias;
    private DashboardController $controller;
    private int $empresa;

    protected function setUp(): void
    {
        $this->session = new Session($this->sessionData);
        $this->empresas = new InMemoryEmpresaRepository();
        $this->guias = new InMemoryObrigacaoRepository($this->empresas);
        $this->empresa = $this->empresas->seed('Padaria Alfa', '11222333000181');
        $semaforo = new SemaforoService(new FixedClock(new DateTimeImmutable(DashboardScenario::HOJE)));

        $this->controller = new DashboardController(
            new DashboardService($this->guias, $semaforo),
            $semaforo,
            new View(__DIR__ . '/../../views'),
            $this->session,
            new Csrf($this->session)
        );
    }

    public function testPageShowsEmptyStatesWithoutGuides(): void
    {
        $response = $this->controller->index(Request::create('GET', '/'));

        $this->assertSame(200, $response->status);
        $this->assertStringContainsString('Nenhuma guia vence nos próximos 7 dias', $response->body);
        $this->assertStringContainsString('R$ 0,00', $response->body);
        $this->assertStringContainsString('Ana', $response->body);
    }

    public function testPageShowsCardsLinkedToFilteredListsAndTotals(): void
    {
        DashboardScenario::load($this->guias, $this->empresa, 1);

        $body = $this->controller->index(Request::create('GET', '/'))->body;

        foreach (['preto', 'vermelho', 'amarelo', 'verde'] as $cor) {
            $this->assertStringContainsString('href="/obrigacoes?semaforo=' . $cor . '"', $body);
        }
        $this->assertMatchesRegularExpression('/display-6[^>]*>3</', $body);   // vencidas
        $this->assertStringContainsString('R$ 900,00', $body);
        $this->assertStringContainsString('9 guia(s) pendentes ou enviadas', $body);
        $this->assertStringContainsString('href="/obrigacoes?status=paga"', $body);
    }

    public function testPageListsGuidesDueThisWeekWithBadgesAndLinks(): void
    {
        DashboardScenario::load($this->guias, $this->empresa, 1);

        $body = $this->controller->index(Request::create('GET', '/'))->body;

        $this->assertStringContainsString('Vencendo esta semana', $body);
        $this->assertStringContainsString('Padaria Alfa', $body);
        $this->assertStringContainsString('16/11/2026', $body);
        $this->assertStringContainsString('23/11/2026', $body);
        $this->assertStringNotContainsString('24/11/2026', $body); // +8 dias: fora da semana
        $this->assertStringNotContainsString('Nenhuma guia vence', $body);
        $this->assertStringContainsString('text-bg-danger', $body);
        $this->assertStringContainsString('text-bg-warning', $body);
    }

    public function testPageHasMenuLogoutAndNewGuideShortcut(): void
    {
        $body = $this->controller->index(Request::create('GET', '/'))->body;

        $this->assertStringContainsString('action="/logout"', $body);
        $this->assertStringContainsString('href="/obrigacoes/nova"', $body);
        $this->assertStringContainsString('href="/obrigacoes"', $body);
    }

    public function testPageEscapesCompanyNames(): void
    {
        $this->empresas->rows[$this->empresa] = new \ContabilFlow\Models\Empresa(
            $this->empresa,
            '<script>alert(1)</script>',
            null,
            '11222333000181',
            'mei',
            null,
            null,
            null,
            true,
            1
        );
        $this->guias->seed($this->empresa, 'DAS', '2026-10-01', '2026-11-17');

        $body = $this->controller->index(Request::create('GET', '/'))->body;

        $this->assertStringNotContainsString('<script>alert(1)', $body);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $body);
    }

    public function testFlashMessagesAreShownOnce(): void
    {
        $this->session->flash('success', 'Bem-vinda!');

        $first = $this->controller->index(Request::create('GET', '/'))->body;
        $second = $this->controller->index(Request::create('GET', '/'))->body;

        $this->assertStringContainsString('Bem-vinda!', $first);
        $this->assertStringNotContainsString('Bem-vinda!', $second);
    }

    public function testApiReturnsIndicatorsAsJson(): void
    {
        DashboardScenario::load($this->guias, $this->empresa, 1);

        $response = $this->controller->indicadores(Request::create('GET', '/api/dashboard/indicadores'));

        $this->assertSame(200, $response->status);
        $this->assertSame('application/json; charset=UTF-8', $response->headers['Content-Type']);
        $this->assertSame([
            'vencidas' => 3, 'ate2dias' => 2, 'ate7dias' => 2, 'noPrazo' => 2, 'pagasMes' => 2,
            'valorPendente' => '900.00',
        ], json_decode($response->body, true));
    }
}

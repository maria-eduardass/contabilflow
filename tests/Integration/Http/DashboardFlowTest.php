<?php

declare(strict_types=1);

namespace ContabilFlow\Tests\Integration\Http;

use ContabilFlow\Tests\Support\Http\HttpTestCase;

/**
 * Painel inicial e API de indicadores com os dados do seed (hoje = 16/11/2026):
 * 1 vencida, 2 em até 2 dias, 2 de 3 a 7 dias, 2 no prazo, 1 paga no mês e R$ 2.862,00 em aberto.
 */
final class DashboardFlowTest extends HttpTestCase
{
    private const SEED_NUMBERS = [
        'vencidas' => 1, 'ate2dias' => 2, 'ate7dias' => 2, 'noPrazo' => 2, 'pagasMes' => 1, 'valorPendente' => '2862.00',
    ];

    /** @return array<string, mixed> */
    private function indicators(\ContabilFlow\Tests\Support\Http\TestBrowser $browser): array
    {
        return $this->json($browser->get('/api/dashboard/indicadores', ['Accept' => 'application/json']));
    }

    private function listedGuides(\ContabilFlow\Tests\Support\Http\TestBrowser $browser, string $query): int
    {
        return substr_count($browser->get('/obrigacoes?' . $query)->body, '<span class="badge text-bg-');
    }

    public function testApiReturnsTheSeedIndicators(): void
    {
        $this->assertSame(self::SEED_NUMBERS, $this->indicators($this->admin()));
    }

    public function testPageShowsTheCardsTotalsAndLinks(): void
    {
        $response = $this->contador()->get('/');

        $this->assertSame(200, $response->status);
        $this->assertBodyContains('R$ 2.862,00', $response);
        $this->assertBodyContains('7 guia(s) pendentes ou enviadas', $response);
        foreach (['preto', 'vermelho', 'amarelo', 'verde'] as $cor) {
            $this->assertBodyContains('href="/obrigacoes?semaforo=' . $cor . '"', $response);
        }
        $this->assertMatchesRegularExpression('/display-6 fw-semibold text-body">1</', $response->body);
        $this->assertBodyContains('href="/obrigacoes?status=paga"', $response);
    }

    public function testThisWeekListHasTheFourOpenGuidesSortedByDueDate(): void
    {
        $body = $this->admin()->get('/')->body;

        $positions = [];
        foreach (['16/11/2026', '17/11/2026', '21/11/2026', '23/11/2026'] as $date) {
            $positions[$date] = strpos($body, '<td>' . $date . '</td>');
            $this->assertNotFalse($positions[$date], $date);
        }
        $sorted = $positions;
        asort($sorted);
        $this->assertSame(array_keys($positions), array_keys($sorted));
        $this->assertStringNotContainsString('<td>13/11/2026</td>', $body); // vencida não entra na semana
        $this->assertStringNotContainsString('<td>06/12/2026</td>', $body); // no prazo não entra
    }

    public function testDashboardRedirectAliasAndNavigation(): void
    {
        $admin = $this->admin();

        $this->assertRedirectTo('/', $admin->get('/dashboard'));
        $this->assertBodyContains('href="/empresas"', $admin->get('/'));
    }

    public function testAdminAndAccountantSeeTheSameNumbers(): void
    {
        $this->assertSame($this->indicators($this->admin()), $this->indicators($this->contador()));
    }

    public function testEachCardMatchesTheNumberOfRowsInItsFilteredList(): void
    {
        $admin = $this->admin();
        $numbers = $this->indicators($admin);

        $this->assertSame($numbers['vencidas'], $this->listedGuides($admin, 'semaforo=preto'));
        $this->assertSame($numbers['ate2dias'], $this->listedGuides($admin, 'semaforo=vermelho'));
        $this->assertSame($numbers['ate7dias'], $this->listedGuides($admin, 'semaforo=amarelo'));
        $this->assertSame($numbers['noPrazo'], $this->listedGuides($admin, 'semaforo=verde'));
    }

    public function testNumbersFollowThePassingOfTime(): void
    {
        $admin = $this->admin();

        $this->clock->advance('+10 days'); // 26/11: tudo que vencia até 25/11 está vencido

        $numbers = $this->indicators($admin);
        $this->assertSame(5, $numbers['vencidas']);   // FGTS, DAS, ISS, DARF, OUTRA
        $this->assertSame(0, $numbers['ate2dias']);
        $this->assertSame(0, $numbers['ate7dias']);
        $this->assertSame(2, $numbers['noPrazo']);
    }

    public function testCreatingMarkingPaidAndCancellingUpdateTheIndicators(): void
    {
        $admin = $this->admin();
        $admin->post('/obrigacoes', [
            'empresa_id' => '1', 'tipo' => 'ISS', 'competencia' => '2026-10', 'vencimento' => '2026-11-01', 'valor' => '1000,00',
        ]);
        $created = (int) $this->scalar("SELECT id FROM obrigacoes WHERE tipo = 'ISS' AND empresa_id = 1");

        $after = $this->indicators($admin);
        $this->assertSame(2, $after['vencidas']);
        $this->assertSame('3862.00', $after['valorPendente']);

        $admin->patch("/obrigacoes/{$created}/status", ['status' => 'paga', 'versao' => '1']);
        $paid = $this->indicators($admin);
        $this->assertSame(1, $paid['vencidas']);
        $this->assertSame(2, $paid['pagasMes']);
        $this->assertSame('2862.00', $paid['valorPendente']);

        $admin->patch('/obrigacoes/2/status', ['status' => 'cancelada', 'versao' => '1']);
        $cancelled = $this->indicators($admin);
        $this->assertSame(0, $cancelled['vencidas']);
        $this->assertSame('2636.50', $cancelled['valorPendente']);
    }

    public function testEmptyDatabaseShowsZerosAndEmptyStates(): void
    {
        $this->pdo->exec('DELETE FROM obrigacoes');
        $admin = $this->admin();

        $page = $admin->get('/');

        $this->assertSame(
            ['vencidas' => 0, 'ate2dias' => 0, 'ate7dias' => 0, 'noPrazo' => 0, 'pagasMes' => 0, 'valorPendente' => '0.00'],
            $this->indicators($admin)
        );
        $this->assertBodyContains('Nenhuma guia vence nos próximos 7 dias', $page);
        $this->assertBodyContains('R$ 0,00', $page);
    }

    public function testApiIsJsonOnly(): void
    {
        $response = $this->admin()->get('/api/dashboard/indicadores');

        $this->assertStringContainsString('application/json', $response->headers['Content-Type']);
        $this->assertSame(self::SEED_NUMBERS, $this->json($response));
    }
}

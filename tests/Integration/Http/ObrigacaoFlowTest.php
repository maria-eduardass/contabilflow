<?php

declare(strict_types=1);

namespace ContabilFlow\Tests\Integration\Http;

use ContabilFlow\Tests\Support\Http\HttpTestCase;
use ContabilFlow\Tests\Support\Http\TestBrowser;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * CRUD, filtros, semáforo e transições de status das guias.
 *
 * Seed (hoje = 16/11/2026): 1 DAS +1 pendente | 2 FGTS -3 pendente | 3 INSS +20 pendente (empresa 1);
 * 4 DARF +5 pendente | 5 ISS 0 enviada | 6 ICMS -10 paga (empresa 2);
 * 7 DAS +30 pendente | 8 DCTFWEB +2 cancelada | 9 OUTRA +7 pendente (empresa 3).
 */
final class ObrigacaoFlowTest extends HttpTestCase
{
    /**
     * @param array<string, string> $override
     * @return array<string, string>
     */
    private function valid(array $override = []): array
    {
        return $override + [
            'empresa_id' => '1', 'tipo' => 'ICMS', 'descricao' => '', 'competencia' => '2026-10',
            'vencimento' => '2026-11-30', 'valor' => '1234,56', 'observacoes' => 'Pagar no banco',
        ];
    }

    /** @param array<string, string> $override */
    private function create(TestBrowser $browser, array $override = []): int
    {
        $browser->post('/obrigacoes', $this->valid($override));

        return (int) $this->scalar('SELECT MAX(id) FROM obrigacoes');
    }

    private function version(int $id): int
    {
        return (int) $this->scalar('SELECT versao FROM obrigacoes WHERE id = ?', [$id]);
    }

    private function statusOf(int $id): string
    {
        return (string) $this->scalar('SELECT status FROM obrigacoes WHERE id = ?', [$id]);
    }

    private function listedRows(TestBrowser $browser, string $query = ''): int
    {
        return substr_count($browser->get('/obrigacoes' . ($query === '' ? '' : '?' . $query))->body, '<span class="badge text-bg-');
    }

    // ---------- listagem e filtros ----------

    public function testListShowsAllGuidesWithTheirTrafficLightBadge(): void
    {
        $response = $this->contador()->get('/obrigacoes');

        $this->assertSame(200, $response->status);
        $this->assertBodyContains('Vencida', $response);
        $this->assertBodyContains('text-bg-dark', $response);
        $this->assertBodyContains('Vence em até 2 dias', $response);
        $this->assertBodyContains('Paga ou cancelada', $response);
        $this->assertBodyContains('9 guia(s)', $response);
        $this->assertBodyContains('R$ 150,00', $response);
        $this->assertBodyContains('Parcelamento de débitos', $response);
    }

    /** @return array<string, array{0: string, 1: int}> */
    public static function colorFilters(): array
    {
        return ['preto' => ['preto', 1], 'vermelho' => ['vermelho', 2], 'amarelo' => ['amarelo', 2], 'verde' => ['verde', 2], 'cinza' => ['cinza', 2]];
    }

    #[DataProvider('colorFilters')]
    public function testFilterByTrafficLightColor(string $color, int $expected): void
    {
        $this->assertSame($expected, $this->listedRows($this->admin(), 'semaforo=' . $color));
    }

    public function testFiltersByTypeStatusCompanyTextAndPeriod(): void
    {
        $admin = $this->admin();

        $this->assertSame(2, $this->listedRows($admin, 'tipo=DAS'));
        $this->assertSame(1, $this->listedRows($admin, 'status=paga'));
        $this->assertSame(1, $this->listedRows($admin, 'status=enviada'));
        $this->assertSame(3, $this->listedRows($admin, 'empresa_id=3'));
        $this->assertSame(1, $this->listedRows($admin, 'q=parcelamento'));
        $this->assertSame(3, $this->listedRows($admin, 'q=oficina'));
        $this->assertSame(2, $this->listedRows($admin, 'de=2026-11-20&ate=2026-11-30'));
        $this->assertSame(0, $this->listedRows($admin, 'empresa_id=1&tipo=DARF'));
        $this->assertSame(1, $this->listedRows($admin, 'tipo=DAS&semaforo=verde')); // DAS da empresa 3; o da empresa 1 é vermelho
    }

    public function testTextSearchTreatsWildcardsAndSqlAsPlainText(): void
    {
        $admin = $this->admin();

        $this->assertSame(0, $this->listedRows($admin, 'q=%25'));
        $this->assertSame(0, $this->listedRows($admin, 'q=' . rawurlencode("' OR '1'='1")));
        $this->assertSame(9, $this->rows('obrigacoes'));
    }

    public function testSortingByDueDateAndValue(): void
    {
        $admin = $this->admin();
        $asc = $admin->get('/obrigacoes')->body;
        $byValueDesc = $admin->get('/obrigacoes?sort=-valor')->body;

        $this->assertLessThan(strpos($asc, '17/11/2026'), strpos($asc, '13/11/2026'));
        $this->assertLessThan(strpos($byValueDesc, 'R$ 150,00'), strpos($byValueDesc, 'R$ 754,00'));
    }

    /** @return array<string, array{0: string}> */
    public static function invalidFilters(): array
    {
        return [
            'tipo' => ['tipo=XYZ'], 'status' => ['status=quase'], 'semaforo' => ['semaforo=roxo'], 'data' => ['de=2026-13-45'],
            'período invertido' => ['de=2026-12-01&ate=2026-11-01'], 'empresa' => ['empresa_id=abc'], 'sort' => ['sort=senha'],
            'page' => ['page=0'],
        ];
    }

    #[DataProvider('invalidFilters')]
    public function testInvalidFiltersAre422(string $query): void
    {
        $response = $this->admin()->get('/obrigacoes?' . $query);

        $this->assertSame(422, $response->status);
        $this->assertBodyContains('Filtro inválido', $response);
    }

    public function testPaginationOfManyGuides(): void
    {
        $admin = $this->admin();
        $types = ['DAS', 'DARF', 'INSS', 'FGTS', 'ISS', 'ICMS', 'DCTFWEB'];
        for ($m = 1; $m <= 3; $m++) {
            foreach ($types as $type) {
                $this->pdo->prepare('INSERT INTO obrigacoes (empresa_id, tipo, competencia, vencimento, criado_por) VALUES (3, ?, ?, ?, 1)')
                    ->execute([$type, sprintf('2025-%02d-01', $m), sprintf('2025-%02d-20', $m)]);
            }
        }

        $this->assertBodyContains('30 guia(s) · página 1 de 2', $admin->get('/obrigacoes'));
        $this->assertSame(10, $this->listedRows($admin, 'page=2'));
    }

    // ---------- criar ----------

    public function testNewFormPreselectsTheCompanyFromTheQueryString(): void
    {
        $response = $this->contador()->get('/obrigacoes/nova?empresa_id=2');

        $this->assertSame(200, $response->status);
        $this->assertMatchesRegularExpression('/<option value="2" selected>/', $response->body);
        $this->assertBodyContains('type="month"', $response);
    }

    public function testCreateStoresTheGuideNormalizedAndRedirectsToIt(): void
    {
        $admin = $this->admin();

        $response = $admin->post('/obrigacoes', $this->valid());

        $this->assertRedirectTo('/obrigacoes/10', $response);
        $row = $this->row('SELECT * FROM obrigacoes WHERE id = 10');
        $this->assertSame('ICMS', $row['tipo']);
        $this->assertSame('2026-10-01', $row['competencia']);
        $this->assertSame('2026-11-30', $row['vencimento']);
        $this->assertSame('1234.56', $row['valor']);
        $this->assertSame('pendente', $row['status']);
        $this->assertSame(1, (int) $row['criado_por']);
        $this->assertSame(1, (int) $row['versao']);
        $this->assertSame('Guia cadastrada.', $this->alertText($admin->follow($response)));
    }

    public function testCreateWithoutValueKeepsItNull(): void
    {
        $this->admin()->post('/obrigacoes', $this->valid(['valor' => '']));

        $this->assertNull($this->scalar('SELECT valor FROM obrigacoes WHERE id = 10'));
    }

    /** @return array<string, array{0: array<string, string>, 1: string}> */
    public static function invalidGuides(): array
    {
        return [
            'sem tipo' => [['tipo' => ''], 'Campo obrigatório'],
            'tipo inexistente' => [['tipo' => 'XYZ'], 'Valor não permitido'],
            'competência inválida' => [['competencia' => '2026-13'], 'Mês inválido'],
            'vencimento inválido' => [['vencimento' => '2026-02-30'], 'Data inválida'],
            'vencimento antes da competência' => [['vencimento' => '2026-09-30'], 'anterior à competência'],
            'valor negativo' => [['valor' => '-5'], 'valor numérico'],
            'valor com 3 casas' => [['valor' => '10,123'], 'valor numérico'],
            'OUTRA sem descrição' => [['tipo' => 'OUTRA', 'descricao' => ''], 'Informe a descrição'],
            'empresa inexistente' => [['empresa_id' => '999'], 'inexistente ou inativa'],
            'sem empresa' => [['empresa_id' => ''], 'Campo obrigatório'],
        ];
    }

    /** @param array<string, string> $override */
    #[DataProvider('invalidGuides')]
    public function testCreateRejectsInvalidDataWith422(array $override, string $message): void
    {
        $response = $this->admin()->post('/obrigacoes', $this->valid($override));

        $this->assertSame(422, $response->status);
        $this->assertBodyContains($message, $response);
        $this->assertSame(9, $this->rows('obrigacoes'));
    }

    public function testDuplicateGuideIs409ButOutraAndCancelledAreExempt(): void
    {
        $admin = $this->admin();

        $dup = $admin->post('/obrigacoes', $this->valid(['tipo' => 'DAS', 'competencia' => '2026-10', 'vencimento' => '2026-11-25']));
        $this->assertSame(409, $dup->status);
        $this->assertBodyContains('Já existe uma guia ativa deste tipo', $dup);

        // OUTRA pode repetir
        $this->assertRedirectStartsWith('/obrigacoes/', $admin->post('/obrigacoes', $this->valid(['tipo' => 'OUTRA', 'descricao' => 'A'])));
        $this->assertRedirectStartsWith('/obrigacoes/', $admin->post('/obrigacoes', $this->valid(['tipo' => 'OUTRA', 'descricao' => 'B'])));

        // cancelar libera a combinação
        $admin->patch('/obrigacoes/1/status', ['status' => 'cancelada', 'versao' => '1']);
        $again = $admin->post('/obrigacoes', $this->valid(['tipo' => 'DAS', 'competencia' => '2026-10', 'vencimento' => '2026-11-25']));
        $this->assertRedirectStartsWith('/obrigacoes/', $again);
    }

    public function testCreateIgnoresStatusCreatorAndVersionFromTheForm(): void
    {
        $this->admin()->post('/obrigacoes', $this->valid(['status' => 'paga', 'criado_por' => '2', 'versao' => '50', 'paga_em' => '2020-01-01']));

        $row = $this->row('SELECT status, criado_por, versao, paga_em FROM obrigacoes WHERE id = 10');
        $this->assertSame('pendente', $row['status']);
        $this->assertSame(1, (int) $row['criado_por']);
        $this->assertSame(1, (int) $row['versao']);
        $this->assertNull($row['paga_em']);
    }

    public function testCreateKeepsTheTypedValuesWhenInvalid(): void
    {
        $response = $this->admin()->post('/obrigacoes', $this->valid(['tipo' => '', 'observacoes' => 'Texto digitado', 'valor' => '77,70']));

        $this->assertBodyContains('Texto digitado', $response);
        $this->assertBodyContains('value="77,70"', $response);
        $this->assertBodyContains('value="2026-10"', $response);
    }

    // ---------- detalhe ----------

    public function testDetailShowsBadgeValuesAndOnlyTheAllowedActions(): void
    {
        $admin = $this->admin();

        $pending = $admin->get('/obrigacoes/2'); // FGTS vencida, pendente
        $this->assertBodyContains('Vencida', $pending);
        $this->assertBodyContains('R$ 225,50', $pending);
        $this->assertBodyContains('Marcar como enviada', $pending);
        $this->assertBodyContains('Marcar como paga', $pending);
        $this->assertBodyContains('Cancelar guia', $pending);
        $this->assertBodyNotContains('Reabrir como pendente', $pending);

        $paid = $admin->get('/obrigacoes/6');
        $this->assertBodyNotContains('name="_method"', $paid);
        $this->assertBodyNotContains('/editar', $paid);

        $cancelled = $admin->get('/obrigacoes/8');
        $this->assertBodyContains('Reabrir como pendente', $cancelled);
        $this->assertBodyNotContains('Marcar como paga', $cancelled);
    }

    public function testUnknownGuideIs404InEveryScreen(): void
    {
        $admin = $this->admin();

        $this->assertSame(404, $admin->get('/obrigacoes/999')->status);
        $this->assertSame(404, $admin->get('/obrigacoes/999/editar')->status);
        $this->assertSame(404, $admin->put('/obrigacoes/999', $this->valid(['versao' => '1']))->status);
        $this->assertSame(404, $admin->patch('/obrigacoes/999/status', ['status' => 'paga', 'versao' => '1'])->status);
        $this->assertSame(404, $admin->delete('/obrigacoes/999')->status);
    }

    // ---------- editar ----------

    public function testEditFormIsPrefilledAndCompanyIsReadOnly(): void
    {
        $response = $this->contador()->get('/obrigacoes/1/editar');

        $this->assertSame(200, $response->status);
        $this->assertBodyContains('name="_method" value="PUT"', $response);
        $this->assertBodyContains('name="versao" value="1"', $response);
        $this->assertBodyContains('value="150,00"', $response);
        $this->assertBodyContains('value="Padaria Pão Quente Ltda" class="form-control" disabled', $response);
        $this->assertBodyNotContains('name="empresa_id"', $response);
    }

    public function testUpdateSavesKeepsTheCompanyAndBumpsTheVersion(): void
    {
        $admin = $this->admin();

        $response = $admin->put('/obrigacoes/1', $this->valid(['tipo' => 'DAS', 'empresa_id' => '3', 'valor' => '99,90', 'versao' => '1']));

        $this->assertRedirectTo('/obrigacoes/1', $response);
        $row = $this->row('SELECT empresa_id, valor, versao FROM obrigacoes WHERE id = 1');
        $this->assertSame(1, (int) $row['empresa_id']);   // o campo empresa_id do POST é ignorado
        $this->assertSame('99.90', $row['valor']);
        $this->assertSame(2, (int) $row['versao']);
    }

    public function testTwoPeopleEditingTheSameGuideTheSecondGetsAConflict(): void
    {
        $ana = $this->admin();
        $bia = $this->contador();

        $ana->put('/obrigacoes/1', $this->valid(['tipo' => 'DAS', 'valor' => '10,00', 'versao' => '1']));
        $response = $bia->put('/obrigacoes/1', $this->valid(['tipo' => 'DAS', 'valor' => '20,00', 'versao' => '1']));

        $this->assertSame(409, $response->status);
        $this->assertBodyContains('alterada por outra pessoa', $response);
        $this->assertBodyContains('value="10,00"', $response);
        $this->assertBodyContains('name="versao" value="2"', $response);
        $this->assertSame('10.00', $this->scalar('SELECT valor FROM obrigacoes WHERE id = 1'));
    }

    public function testEditingAPaidGuideIsBlocked(): void
    {
        $admin = $this->admin();

        $form = $admin->get('/obrigacoes/6/editar');
        $update = $admin->put('/obrigacoes/6', $this->valid(['tipo' => 'ICMS', 'versao' => '1']));

        $this->assertRedirectTo('/obrigacoes/6', $form);
        $this->assertSame(422, $update->status);
        $this->assertBodyContains('já paga não pode ser editada', $update);
    }

    public function testUpdateIntoADuplicateCombinationIs409(): void
    {
        $response = $this->admin()->put('/obrigacoes/2', $this->valid(['tipo' => 'DAS', 'competencia' => '2026-10', 'versao' => '1']));

        $this->assertSame(409, $response->status);
        $this->assertBodyContains('Já existe uma guia ativa', $response);
    }

    public function testUpdateWithInvalidDataOrWithoutVersionIs422(): void
    {
        $admin = $this->admin();

        $this->assertSame(422, $admin->put('/obrigacoes/1', $this->valid(['tipo' => 'DAS', 'competencia' => 'x', 'versao' => '1']))->status);
        $this->assertSame(422, $admin->put('/obrigacoes/1', $this->valid(['tipo' => 'DAS']))->status);
        $this->assertSame(1, $this->version(1));
    }

    // ---------- transições de status ----------

    public function testFullLifecyclePendingSentPaid(): void
    {
        $admin = $this->admin();

        $sent = $admin->patch('/obrigacoes/1/status', ['status' => 'enviada', 'versao' => '1']);
        $this->assertRedirectTo('/obrigacoes/1', $sent);
        $this->assertSame('Guia marcada como enviada.', $this->alertText($admin->follow($sent)));
        $this->assertSame('2026-11-16 10:00:00', $this->scalar('SELECT enviada_em FROM obrigacoes WHERE id = 1'));

        $paid = $admin->patch('/obrigacoes/1/status', ['status' => 'paga', 'versao' => '2']);
        $this->assertRedirectTo('/obrigacoes/1', $paid);
        $row = $this->row('SELECT status, enviada_em, paga_em, versao FROM obrigacoes WHERE id = 1');
        $this->assertSame('paga', $row['status']);
        $this->assertSame('2026-11-16 10:00:00', $row['paga_em']);
        $this->assertSame('2026-11-16 10:00:00', $row['enviada_em']);
        $this->assertSame(3, (int) $row['versao']);
        $this->assertBodyContains('Paga ou cancelada', $admin->get('/obrigacoes/1'));
    }

    public function testPendingCanGoStraightToPaid(): void
    {
        $this->admin()->patch('/obrigacoes/3/status', ['status' => 'paga', 'versao' => '1']);

        $this->assertSame('paga', $this->statusOf(3));
    }

    public function testCancelThenReopenClearsTheSentDate(): void
    {
        $admin = $this->admin();
        $admin->patch('/obrigacoes/5/status', ['status' => 'cancelada', 'versao' => '1']); // ISS enviada
        $this->assertSame('cancelada', $this->statusOf(5));

        $admin->patch('/obrigacoes/5/status', ['status' => 'pendente', 'versao' => '2']);

        $row = $this->row('SELECT status, enviada_em FROM obrigacoes WHERE id = 5');
        $this->assertSame('pendente', $row['status']);
        $this->assertNull($row['enviada_em']);
    }

    /** @return array<string, array{0: int, 1: string}> guia do seed e status pedido */
    public static function invalidTransitions(): array
    {
        return [
            'paga -> pendente' => [6, 'pendente'],
            'paga -> cancelada' => [6, 'cancelada'],
            'paga -> enviada' => [6, 'enviada'],
            'paga -> paga' => [6, 'paga'],
            'pendente -> pendente' => [1, 'pendente'],
            'enviada -> pendente' => [5, 'pendente'],
            'enviada -> enviada' => [5, 'enviada'],
            'cancelada -> paga' => [8, 'paga'],
            'cancelada -> enviada' => [8, 'enviada'],
        ];
    }

    #[DataProvider('invalidTransitions')]
    public function testInvalidTransitionsAre422AndChangeNothing(int $guide, string $to): void
    {
        $before = $this->row('SELECT status, versao FROM obrigacoes WHERE id = ?', [$guide]);

        $response = $this->admin()->patch("/obrigacoes/{$guide}/status", ['status' => $to, 'versao' => (string) $before['versao']]);

        $this->assertSame(422, $response->status);
        $this->assertBodyContains('Transição inválida', $response);
        $this->assertSame($before, $this->row('SELECT status, versao FROM obrigacoes WHERE id = ?', [$guide]));
    }

    public function testDoubleSubmitAndConcurrentChangesGetAConflict(): void
    {
        $ana = $this->admin();
        $bia = $this->contador();

        $ana->patch('/obrigacoes/1/status', ['status' => 'paga', 'versao' => '1']);
        $second = $bia->patch('/obrigacoes/1/status', ['status' => 'cancelada', 'versao' => '1']); // Bia ainda via a versão 1

        $this->assertSame(409, $second->status);
        $this->assertBodyContains('alterada por outra pessoa e agora está paga', $second);
        $this->assertSame('paga', $this->statusOf(1));
        $this->assertSame(2, $this->version(1));
    }

    public function testReopeningThatCollidesWithAnActiveGuideIs409(): void
    {
        $admin = $this->admin();
        $admin->patch('/obrigacoes/1/status', ['status' => 'cancelada', 'versao' => '1']); // DAS out/2026 cancelada
        $admin->post('/obrigacoes', $this->valid(['tipo' => 'DAS', 'competencia' => '2026-10', 'vencimento' => '2026-11-25']));

        $response = $admin->patch('/obrigacoes/1/status', ['status' => 'pendente', 'versao' => '2']);

        $this->assertSame(409, $response->status);
        $this->assertBodyContains('Já existe uma guia ativa', $response);
        $this->assertSame('cancelada', $this->statusOf(1));
    }

    /** @return array<string, array{0: array<string, string>}> */
    public static function badStatusRequests(): array
    {
        return [
            'status desconhecido' => [['status' => 'quitada', 'versao' => '1']],
            'status vazio' => [['status' => '', 'versao' => '1']],
            'sem versão' => [['status' => 'paga']],
            'versão texto' => [['status' => 'paga', 'versao' => 'x']],
        ];
    }

    /** @param array<string, string> $post */
    #[DataProvider('badStatusRequests')]
    public function testBadStatusRequestsAre422(array $post): void
    {
        $response = $this->admin()->patch('/obrigacoes/1/status', $post);

        $this->assertSame(422, $response->status);
        $this->assertBodyContains('Pedido inválido', $response);
        $this->assertSame('pendente', $this->statusOf(1));
    }

    // ---------- excluir ----------

    public function testDeleteRemovesAGuideAndItsReminders(): void
    {
        $admin = $this->admin();
        $admin->api('POST', '/api/obrigacoes/1/lembretes', ['canal' => 'copiado', 'mensagem' => 'oi']);
        $this->assertSame(1, $this->rows('lembretes'));

        $response = $admin->delete('/obrigacoes/1');

        $this->assertRedirectTo('/obrigacoes', $response);
        $this->assertSame(0, $this->rows('obrigacoes', 'id = 1'));
        $this->assertSame(0, $this->rows('lembretes'));
        $this->assertSame('Guia excluída.', $this->alertText($admin->follow($response)));
    }

    public function testDeletingAPaidGuideIs409AndKeepsIt(): void
    {
        $response = $this->admin()->delete('/obrigacoes/6');

        $this->assertSame(409, $response->status);
        $this->assertBodyContains('Guia paga não pode ser excluída', $response);
        $this->assertSame(1, $this->rows('obrigacoes', 'id = 6'));
    }

    // ---------- segurança ----------

    public function testUserTextIsEscapedInTheListAndDetail(): void
    {
        $admin = $this->admin();
        $id = $this->create($admin, ['tipo' => 'OUTRA', 'descricao' => '<script>alert(1)</script>', 'observacoes' => '<img src=x onerror=alert(1)>']);

        foreach (['/obrigacoes', "/obrigacoes/{$id}", "/obrigacoes/{$id}/editar", '/'] as $page) {
            $body = $admin->get($page)->body;

            $this->assertStringNotContainsString('<script>alert(1)', $body, $page);
            $this->assertStringNotContainsString('<img src=x', $body, $page);
        }
    }

    public function testLoggedInAccountantCanDoEverythingWithGuides(): void
    {
        $contador = $this->contador();

        $id = $this->create($contador);
        $contador->patch("/obrigacoes/{$id}/status", ['status' => 'enviada', 'versao' => '1']);
        $contador->put("/obrigacoes/{$id}", $this->valid(['valor' => '1,00', 'versao' => '2']));
        $contador->delete("/obrigacoes/{$id}");

        $this->assertSame(0, $this->rows('obrigacoes', "id = {$id}"));
    }
}

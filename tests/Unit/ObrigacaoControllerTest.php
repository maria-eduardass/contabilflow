<?php

declare(strict_types=1);

namespace ContabilFlow\Tests\Unit;

use ContabilFlow\Controllers\ObrigacaoController;
use ContabilFlow\Core\Csrf;
use ContabilFlow\Core\Request;
use ContabilFlow\Core\Response;
use ContabilFlow\Core\Session;
use ContabilFlow\Core\View;
use ContabilFlow\Models\ObrigacaoStatus;
use ContabilFlow\Services\SemaforoService;
use ContabilFlow\Tests\Support\FixedClock;
use ContabilFlow\Tests\Support\InMemoryEmpresaRepository;
use ContabilFlow\Tests\Support\InMemoryObrigacaoRepository;
use ContabilFlow\Validation\Validator;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ObrigacaoControllerTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $sessionData = ['user_id' => 1, 'perfil' => 'contador', 'nome' => 'Ana'];
    private Session $session;
    private InMemoryEmpresaRepository $empresas;
    private InMemoryObrigacaoRepository $guias;
    private ObrigacaoController $controller;
    private int $empresa;

    protected function setUp(): void
    {
        $this->session = new Session($this->sessionData);
        $this->empresas = new InMemoryEmpresaRepository();
        $this->guias = new InMemoryObrigacaoRepository($this->empresas);
        $this->empresa = $this->empresas->seed('Padaria Alfa', '11222333000181');
        $clock = new FixedClock(new DateTimeImmutable('2026-11-16 10:00:00'));

        $this->controller = new ObrigacaoController(
            $this->guias,
            $this->empresas,
            new SemaforoService($clock),
            $clock,
            new View(__DIR__ . '/../../views'),
            $this->session,
            new Csrf($this->session),
            new Validator()
        );
    }

    /**
     * @param array<string, string> $override
     * @return array<string, string>
     */
    private function valid(array $override = []): array
    {
        return $override + [
            'empresa_id' => (string) $this->empresa, 'tipo' => 'DAS', 'descricao' => '',
            'competencia' => '2026-10', 'vencimento' => '2026-11-20', 'valor' => '1234,56',
            'observacoes' => 'Pagar no banco',
        ];
    }

    /** @param array<string, string> $query */
    private function index(array $query = []): Response
    {
        return $this->controller->index(Request::create('GET', '/obrigacoes?' . http_build_query($query)));
    }

    /** @param array<string, string> $post */
    private function store(array $post): Response
    {
        return $this->controller->store(Request::create('POST', '/obrigacoes', $post));
    }

    private function mudarStatus(int $id, string $to, int $versao): Response
    {
        return $this->controller->changeStatus(
            Request::create(
                'POST',
                "/obrigacoes/{$id}/status",
                ['_method' => 'PATCH', 'status' => $to, 'versao' => (string) $versao]
            ),
            ['id' => (string) $id]
        );
    }

    // ---------- listagem ----------

    public function testIndexListsGuidesWithTrafficLightBadgeAndFormatting(): void
    {
        $this->guias->seed($this->empresa, 'DAS', '2026-10-01', '2026-11-10'); // vencida
        $this->guias->seed($this->empresa, 'DARF', '2026-10-01', '2026-11-17'); // vermelho

        $body = $this->index()->body;

        $this->assertStringContainsString('Padaria Alfa', $body);
        $this->assertStringContainsString('Vencida', $body);
        $this->assertStringContainsString('text-bg-dark', $body);
        $this->assertStringContainsString('Vence em até 2 dias', $body);
        $this->assertStringContainsString('10/11/2026', $body);
        $this->assertStringContainsString('10/2026', $body);
        $this->assertStringContainsString('R$ 100,00', $body);
        $this->assertStringContainsString('href="/obrigacoes"', $body); // menu
    }

    public function testIndexShowsEmptyState(): void
    {
        $this->assertStringContainsString('Nenhuma guia encontrada', $this->index()->body);
    }

    public function testIndexPassesAllFiltersToRepository(): void
    {
        $this->index([
            'q' => 'alfa', 'empresa_id' => (string) $this->empresa, 'tipo' => 'DAS', 'status' => 'paga',
            'semaforo' => 'vermelho', 'de' => '2026-11-01', 'ate' => '2026-11-30', 'page' => '2', 'sort' => '-valor',
        ]);

        $filter = $this->guias->lastFilter;
        $this->assertNotNull($filter);
        $this->assertSame('alfa', $filter->q);
        $this->assertSame($this->empresa, $filter->empresaId);
        $this->assertSame('DAS', $filter->tipo);
        $this->assertSame(ObrigacaoStatus::Paga, $filter->status);
        $this->assertSame('2026-11-01', $filter->de?->format('Y-m-d'));
        $this->assertSame('2026-11-30', $filter->ate?->format('Y-m-d'));
        $this->assertSame(2, $filter->page);
        $this->assertSame('-valor', $filter->sort);
        // vermelho = hoje (16/11) até hoje+2, calculado pelo SemaforoService com o relógio fixo
        $this->assertTrue($filter->faixa?->emAberto);
        $this->assertSame('2026-11-16', $filter->faixa->de?->format('Y-m-d'));
        $this->assertSame('2026-11-18', $filter->faixa->ate?->format('Y-m-d'));
    }

    public function testIndexWithoutFiltersUsesDefaults(): void
    {
        $this->index();

        $filter = $this->guias->lastFilter;
        $this->assertNotNull($filter);
        $this->assertNull($filter->q);
        $this->assertNull($filter->empresaId);
        $this->assertNull($filter->status);
        $this->assertNull($filter->faixa);
        $this->assertSame(1, $filter->page);
        $this->assertSame('vencimento', $filter->sort);
    }

    /** @return array<string, array{0: array<string, string>}> */
    public static function invalidFilters(): array
    {
        return [
            'tipo' => [['tipo' => 'XYZ']],
            'status' => [['status' => 'quase']],
            'semaforo' => [['semaforo' => 'roxo']],
            'data' => [['de' => '2026-13-45']],
            'periodo invertido' => [['de' => '2026-12-01', 'ate' => '2026-11-01']],
            'empresa' => [['empresa_id' => 'abc']],
            'empresa zero' => [['empresa_id' => '0']],
            'sort' => [['sort' => 'senha']],
            'sort injection' => [['sort' => 'valor; DROP TABLE obrigacoes']],
            'page' => [['page' => '0']],
            'q longo' => [['q' => str_repeat('a', 101)]],
        ];
    }

    /** @param array<string, string> $query */
    #[DataProvider('invalidFilters')]
    public function testIndexRejectsInvalidFiltersWith422(array $query): void
    {
        $response = $this->index($query);

        $this->assertSame(422, $response->status);
        $this->assertStringContainsString('Filtro inválido', $response->body);
    }

    public function testIndexKeepsSelectedFiltersInTheForm(): void
    {
        $body = $this->index([
            'tipo' => 'DARF', 'status' => 'enviada', 'semaforo' => 'verde', 'de' => '2026-11-01',
        ])->body;

        $this->assertMatchesRegularExpression('/<option value="DARF" selected>/', $body);
        $this->assertMatchesRegularExpression('/<option value="enviada" selected>/', $body);
        $this->assertMatchesRegularExpression('/<option value="verde" selected>/', $body);
        $this->assertStringContainsString('value="2026-11-01"', $body);
    }

    public function testIndexEscapesCompanyAndDescription(): void
    {
        $this->empresas->rows[1] = new \ContabilFlow\Models\Empresa(
            1,
            '<img src=x onerror=alert(1)>',
            null,
            '11222333000181',
            'mei',
            null,
            null,
            null,
            true,
            1
        );
        $this->guias->seed(1, 'OUTRA');

        $body = $this->index()->body;

        $this->assertStringNotContainsString('<img src=x', $body);
        $this->assertStringContainsString('&lt;img src=x', $body);
    }

    // ---------- criar ----------

    public function testCreateFormListsOnlyActiveCompaniesAndPrefillsFromQuery(): void
    {
        $this->empresas->seed('Inativa Ltda', '11444777000161', 'mei', false);

        $response = $this->controller->create(
            Request::create('GET', '/obrigacoes/nova?empresa_id=' . $this->empresa)
        );

        $this->assertSame(200, $response->status);
        $this->assertStringContainsString('name="_csrf"', $response->body);
        $this->assertStringContainsString('Padaria Alfa', $response->body);
        $this->assertStringNotContainsString('Inativa Ltda', $response->body);
        $this->assertMatchesRegularExpression('/<option value="' . $this->empresa . '" selected>/', $response->body);
        $this->assertStringContainsString('type="month"', $response->body);
        $this->assertStringNotContainsString('name="_method"', $response->body);
    }

    public function testCreateIgnoresGarbageCompanyInQuery(): void
    {
        $response = $this->controller->create(Request::create('GET', '/obrigacoes/nova?empresa_id=abc'));

        $this->assertSame(200, $response->status);
        $this->assertDoesNotMatchRegularExpression('/<option value="[0-9]+" selected>/', $response->body);
    }

    public function testStoreCreatesGuideNormalizedAndRedirectsWithFlash(): void
    {
        $response = $this->store($this->valid());

        $this->assertSame(302, $response->status);
        $this->assertSame('/obrigacoes/1', $response->headers['Location']);
        $guia = $this->guias->find(1);
        $this->assertNotNull($guia);
        $this->assertSame($this->empresa, $guia->empresaId);
        $this->assertSame('DAS', $guia->tipo);
        $this->assertSame('2026-10-01', $guia->competencia->format('Y-m-d'));
        $this->assertSame('2026-11-20', $guia->vencimento->format('Y-m-d'));
        $this->assertSame('1234.56', $guia->valor);
        $this->assertSame(ObrigacaoStatus::Pendente, $guia->status);
        $this->assertSame(1, $guia->criadoPor);
        $this->assertSame([['type' => 'success', 'message' => 'Guia cadastrada.']], $this->session->pullFlash());
    }

    public function testStoreAcceptsEmptyValueAsNull(): void
    {
        $this->store($this->valid(['valor' => '']));

        $this->assertNull($this->guias->find(1)?->valor);
    }

    /** @return array<string, array{0: array<string, string>, 1: string}> */
    public static function invalidGuides(): array
    {
        return [
            'tipo vazio' => [['tipo' => ''], 'Campo obrigatório'],
            'tipo inexistente' => [['tipo' => 'XYZ'], 'Valor não permitido'],
            'competência inválida' => [['competencia' => '2026-13'], 'Mês inválido'],
            'competência vazia' => [['competencia' => ''], 'Campo obrigatório'],
            'vencimento inválido' => [['vencimento' => '2026-02-30'], 'Data inválida'],
            'vencimento antes da competência' => [['vencimento' => '2026-09-30'], 'anterior à competência'],
            'valor negativo' => [['valor' => '-5'], 'valor numérico'],
            'valor com 3 casas' => [['valor' => '10,123'], 'valor numérico'],
            'valor texto' => [['valor' => 'abc'], 'valor numérico'],
            'OUTRA sem descrição' => [['tipo' => 'OUTRA', 'descricao' => ''], 'Informe a descrição'],
            'descrição longa' => [['descricao' => str_repeat('a', 256)], 'Máximo de 255'],
            'empresa vazia' => [['empresa_id' => ''], 'Campo obrigatório'],
            'empresa inexistente' => [['empresa_id' => '999'], 'inexistente ou inativa'],
            'empresa texto' => [['empresa_id' => 'abc'], 'inexistente ou inativa'],
        ];
    }

    /** @param array<string, string> $override */
    #[DataProvider('invalidGuides')]
    public function testStoreRejectsInvalidDataWith422(array $override, string $expected): void
    {
        $response = $this->store($this->valid($override));

        $this->assertSame(422, $response->status);
        $this->assertStringContainsString($expected, $response->body);
        $this->assertSame([], $this->guias->rows);
    }

    public function testStoreRejectsInactiveCompany(): void
    {
        $inactive = $this->empresas->seed('Inativa', '11444777000161', 'mei', false);

        $response = $this->store($this->valid(['empresa_id' => (string) $inactive]));

        $this->assertSame(422, $response->status);
        $this->assertStringContainsString('inexistente ou inativa', $response->body);
    }

    public function testStoreKeepsTypedInputWhenInvalid(): void
    {
        $response = $this->store($this->valid(['tipo' => '', 'observacoes' => 'Texto digitado', 'valor' => '77,70']));

        $this->assertStringContainsString('Texto digitado', $response->body);
        $this->assertStringContainsString('value="77,70"', $response->body);
        $this->assertStringContainsString('value="2026-10"', $response->body);
    }

    public function testStoreDuplicateReturns409WithFieldErrorAndKeepsInput(): void
    {
        $this->guias->seed($this->empresa, 'DAS', '2026-10-01');

        $response = $this->store($this->valid(['observacoes' => 'Minha anotação']));

        $this->assertSame(409, $response->status);
        $this->assertStringContainsString('Já existe uma guia ativa deste tipo', $response->body);
        $this->assertStringContainsString('Minha anotação', $response->body);
        $this->assertCount(1, $this->guias->rows);
    }

    public function testStoreIgnoresStatusAndCreatorFromForm(): void
    {
        $this->store($this->valid(['status' => 'paga', 'criado_por' => '99', 'versao' => '50', 'id' => '7']));

        $guia = $this->guias->find(1);
        $this->assertSame(ObrigacaoStatus::Pendente, $guia?->status);
        $this->assertSame(1, $guia->criadoPor);
        $this->assertSame(1, $guia->versao);
        $this->assertSame([1], array_keys($this->guias->rows));
    }

    public function testStoreEscapesEchoedInput(): void
    {
        $response = $this->store($this->valid(['tipo' => '', 'observacoes' => '</textarea><script>alert(1)</script>']));

        $this->assertStringNotContainsString('<script>alert(1)', $response->body);
        $this->assertStringContainsString('&lt;/textarea&gt;', $response->body);
    }

    // ---------- ver ----------

    public function testShowDisplaysGuideBadgeAndStatusActions(): void
    {
        $id = $this->guias->seed($this->empresa, 'DAS', '2026-10-01', '2026-11-10');

        $response = $this->controller->show(Request::create('GET', '/obrigacoes/1'), ['id' => (string) $id]);

        $body = $response->body;
        $this->assertSame(200, $response->status);
        $this->assertStringContainsString('Vencida', $body);
        $this->assertStringContainsString('Marcar como enviada', $body);
        $this->assertStringContainsString('Marcar como paga', $body);
        $this->assertStringContainsString('Cancelar guia', $body);
        $this->assertStringNotContainsString('Reabrir como pendente', $body);
        $this->assertStringContainsString('name="_method" value="PATCH"', $body);
        $this->assertStringContainsString('name="versao" value="1"', $body);
        $this->assertStringContainsString('name="_csrf"', $body);
        $this->assertStringContainsString('/obrigacoes/1/editar', $body);
        $this->assertStringContainsString('name="_method" value="DELETE"', $body);
    }

    public function testShowOfPaidGuideOffersNoActionsEditOrDelete(): void
    {
        $id = $this->guias->seed($this->empresa, 'DAS', '2026-10-01', '2026-11-10', ObrigacaoStatus::Paga);

        $body = $this->controller->show(Request::create('GET', '/x'), ['id' => (string) $id])->body;

        $this->assertStringContainsString('Paga ou cancelada', $body);
        $this->assertStringNotContainsString('name="_method"', $body);
        $this->assertStringNotContainsString('/editar', $body);
        $this->assertStringNotContainsString('Excluir', $body);
    }

    public function testShowOfCancelledGuideOffersReopen(): void
    {
        $id = $this->guias->seed($this->empresa, 'DAS', '2026-10-01', '2026-11-10', ObrigacaoStatus::Cancelada);

        $body = $this->controller->show(Request::create('GET', '/x'), ['id' => (string) $id])->body;

        $this->assertStringContainsString('Reabrir como pendente', $body);
        $this->assertStringNotContainsString('Marcar como paga', $body);
    }

    public function testShowUnknownGuideReturns404(): void
    {
        $this->assertSame(404, $this->controller->show(Request::create('GET', '/x'), ['id' => '9'])->status);
    }

    // ---------- editar ----------

    public function testEditFormIsPrefilledAndCompanyIsReadOnly(): void
    {
        $id = $this->guias->seed($this->empresa);

        $response = $this->controller->edit(Request::create('GET', '/x'), ['id' => (string) $id]);

        $body = $response->body;
        $this->assertSame(200, $response->status);
        $this->assertStringContainsString('name="_method" value="PUT"', $body);
        $this->assertStringContainsString('name="versao" value="1"', $body);
        $this->assertStringContainsString('value="2026-10"', $body);
        $this->assertStringContainsString('value="2026-11-20"', $body);
        $this->assertStringContainsString('value="100,00"', $body);
        $this->assertStringContainsString('value="Padaria Alfa" class="form-control" disabled', $body);
        $this->assertStringNotContainsString('name="empresa_id"', $body);
    }

    public function testEditOfPaidGuideRedirectsToDetailWithWarning(): void
    {
        $id = $this->guias->seed($this->empresa, 'DAS', '2026-10-01', '2026-11-10', ObrigacaoStatus::Paga);

        $response = $this->controller->edit(Request::create('GET', '/x'), ['id' => (string) $id]);

        $this->assertSame(302, $response->status);
        $this->assertSame('/obrigacoes/' . $id, $response->headers['Location']);
        $this->assertSame('warning', $this->session->pullFlash()[0]['type']);
    }

    public function testEditUnknownGuideReturns404(): void
    {
        $this->assertSame(404, $this->controller->edit(Request::create('GET', '/x'), ['id' => '9'])->status);
    }

    public function testUpdateSavesAndKeepsCompanyEvenIfFormSendsAnother(): void
    {
        $other = $this->empresas->seed('Outra', '11444777000161');
        $this->store($this->valid());

        $response = $this->controller->update(
            Request::create('POST', '/obrigacoes/1', $this->valid([
                'empresa_id' => (string) $other, 'valor' => '50', 'versao' => '1',
            ])),
            ['id' => '1']
        );

        $this->assertSame(302, $response->status);
        $guia = $this->guias->find(1);
        $this->assertSame($this->empresa, $guia?->empresaId);
        $this->assertSame('50.00', $guia->valor);
        $this->assertSame(2, $guia->versao);
    }

    public function testUpdateOfInactiveCompanysGuideIsAllowed(): void
    {
        $this->store($this->valid());
        $this->empresas->rows[$this->empresa] = new \ContabilFlow\Models\Empresa(
            $this->empresa,
            'Padaria Alfa',
            null,
            '11222333000181',
            'mei',
            null,
            null,
            null,
            false,
            2
        );

        $response = $this->controller->update(
            Request::create('POST', '/obrigacoes/1', $this->valid(['versao' => '1'])),
            ['id' => '1']
        );

        $this->assertSame(302, $response->status);
    }

    public function testUpdateWithInvalidDataReturns422KeepingVersion(): void
    {
        $this->store($this->valid());

        $response = $this->controller->update(
            Request::create('POST', '/obrigacoes/1', $this->valid(['competencia' => 'x', 'versao' => '1'])),
            ['id' => '1']
        );

        $this->assertSame(422, $response->status);
        $this->assertStringContainsString('name="versao" value="1"', $response->body);
        $this->assertSame(1, $this->guias->find(1)?->versao);
    }

    public function testUpdateWithoutVersionIsRejected(): void
    {
        $this->store($this->valid());

        $response = $this->controller->update(Request::create('POST', '/obrigacoes/1', $this->valid()), ['id' => '1']);

        $this->assertSame(422, $response->status);
        $this->assertSame(1, $this->guias->find(1)?->versao);
    }

    public function testUpdateWithStaleVersionReturns409ShowingCurrentData(): void
    {
        $this->store($this->valid());
        $this->controller->update(
            Request::create('POST', '/obrigacoes/1', $this->valid(['valor' => '10', 'versao' => '1'])),
            ['id' => '1']
        );

        $response = $this->controller->update(
            Request::create('POST', '/obrigacoes/1', $this->valid(['valor' => '20', 'versao' => '1'])),
            ['id' => '1']
        );

        $this->assertSame(409, $response->status);
        $this->assertStringContainsString('alterada por outra pessoa', $response->body);
        $this->assertStringContainsString('value="10,00"', $response->body);
        $this->assertStringContainsString('name="versao" value="2"', $response->body);
        $this->assertSame('10.00', $this->guias->find(1)?->valor);
    }

    public function testUpdateOfPaidGuideReturns422(): void
    {
        $id = $this->guias->seed($this->empresa, 'DAS', '2026-10-01', '2026-11-10', ObrigacaoStatus::Paga);

        $response = $this->controller->update(
            Request::create('POST', '/x', $this->valid(['versao' => '1'])),
            ['id' => (string) $id]
        );

        $this->assertSame(422, $response->status);
        $this->assertStringContainsString('já paga não pode ser editada', $response->body);
    }

    public function testUpdateIntoDuplicateReturns409(): void
    {
        $this->guias->seed($this->empresa, 'DARF', '2026-10-01');
        $this->store($this->valid());

        $response = $this->controller->update(
            Request::create('POST', '/x', $this->valid(['tipo' => 'DARF', 'versao' => '1'])),
            ['id' => '2']
        );

        $this->assertSame(409, $response->status);
        $this->assertStringContainsString('Já existe uma guia ativa', $response->body);
    }

    public function testUpdateUnknownGuideReturns404(): void
    {
        $response = $this->controller->update(
            Request::create('POST', '/x', $this->valid(['versao' => '1'])),
            ['id' => '9']
        );

        $this->assertSame(404, $response->status);
    }

    // ---------- mudança de status ----------

    public function testMarkAsSentRedirectsWithFlash(): void
    {
        $id = $this->guias->seed($this->empresa);

        $response = $this->mudarStatus($id, 'enviada', 1);

        $this->assertSame(302, $response->status);
        $this->assertSame('/obrigacoes/' . $id, $response->headers['Location']);
        $guia = $this->guias->find($id);
        $this->assertSame(ObrigacaoStatus::Enviada, $guia?->status);
        $this->assertSame('2026-11-16 10:00:00', $guia->enviadaEm?->format('Y-m-d H:i:s'));
        $this->assertSame(
            [['type' => 'success', 'message' => 'Guia marcada como enviada.']],
            $this->session->pullFlash()
        );
    }

    public function testMarkAsPaidThenCancelFlowMessages(): void
    {
        $paga = $this->guias->seed($this->empresa, 'DAS');
        $cancelada = $this->guias->seed($this->empresa, 'DARF');

        $this->mudarStatus($paga, 'paga', 1);
        $this->mudarStatus($cancelada, 'cancelada', 1);

        $this->assertSame(ObrigacaoStatus::Paga, $this->guias->find($paga)?->status);
        $this->assertSame(ObrigacaoStatus::Cancelada, $this->guias->find($cancelada)?->status);
        $this->assertSame(
            ['Guia marcada como paga.', 'Guia cancelada.'],
            array_column($this->session->pullFlash(), 'message')
        );
    }

    public function testReopenCancelledGuide(): void
    {
        $id = $this->guias->seed($this->empresa, 'DAS', '2026-10-01', '2026-11-20', ObrigacaoStatus::Cancelada);

        $this->mudarStatus($id, 'pendente', 1);

        $this->assertSame(ObrigacaoStatus::Pendente, $this->guias->find($id)?->status);
        $this->assertSame('Guia reaberta.', $this->session->pullFlash()[0]['message']);
    }

    public function testInvalidTransitionReturns422AndChangesNothing(): void
    {
        $id = $this->guias->seed($this->empresa, 'DAS', '2026-10-01', '2026-11-10', ObrigacaoStatus::Paga);

        $response = $this->mudarStatus($id, 'pendente', 1);

        $this->assertSame(422, $response->status);
        $this->assertStringContainsString('Transição inválida', $response->body);
        $this->assertSame(ObrigacaoStatus::Paga, $this->guias->find($id)?->status);
    }

    public function testStatusChangeWithStaleVersionReturns409ShowingCurrentState(): void
    {
        $id = $this->guias->seed($this->empresa);
        $this->mudarStatus($id, 'paga', 1); // outra pessoa marcou como paga

        $response = $this->mudarStatus($id, 'cancelada', 1);

        $this->assertSame(409, $response->status);
        $this->assertStringContainsString('alterada por outra pessoa e agora está paga', $response->body);
        $this->assertSame(ObrigacaoStatus::Paga, $this->guias->find($id)?->status);
    }

    public function testDoubleSubmitOfTheSameChangeIsHarmless(): void
    {
        $id = $this->guias->seed($this->empresa);
        $this->mudarStatus($id, 'enviada', 1);

        $second = $this->mudarStatus($id, 'enviada', 1);

        $this->assertSame(409, $second->status);
        $this->assertSame(2, $this->guias->find($id)?->versao);
    }

    public function testReopeningThatCollidesWithAnotherActiveGuideReturns409(): void
    {
        $cancelada = $this->guias->seed($this->empresa, 'DAS', '2026-10-01', '2026-11-20', ObrigacaoStatus::Cancelada);
        $this->guias->seed($this->empresa, 'DAS', '2026-10-01');

        $response = $this->mudarStatus($cancelada, 'pendente', 1);

        $this->assertSame(409, $response->status);
        $this->assertStringContainsString('Já existe uma guia ativa', $response->body);
        $this->assertSame(ObrigacaoStatus::Cancelada, $this->guias->find($cancelada)?->status);
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
    public function testBadStatusRequestsReturn422(array $post): void
    {
        $id = $this->guias->seed($this->empresa);

        $response = $this->controller->changeStatus(
            Request::create('POST', '/x', ['_method' => 'PATCH'] + $post),
            ['id' => (string) $id]
        );

        $this->assertSame(422, $response->status);
        $this->assertStringContainsString('Pedido inválido', $response->body);
        $this->assertSame(ObrigacaoStatus::Pendente, $this->guias->find($id)?->status);
    }

    public function testStatusChangeOfUnknownGuideReturns404(): void
    {
        $this->assertSame(404, $this->mudarStatus(9, 'paga', 1)->status);
    }

    // ---------- excluir ----------

    public function testDestroyDeletesAndRedirectsWithFlash(): void
    {
        $id = $this->guias->seed($this->empresa);

        $response = $this->controller->destroy(Request::create('POST', '/x'), ['id' => (string) $id]);

        $this->assertSame(302, $response->status);
        $this->assertSame('/obrigacoes', $response->headers['Location']);
        $this->assertNull($this->guias->find($id));
        $this->assertSame('Guia excluída.', $this->session->pullFlash()[0]['message']);
    }

    public function testDestroyOfPaidGuideReturns409AndKeepsIt(): void
    {
        $id = $this->guias->seed($this->empresa, 'DAS', '2026-10-01', '2026-11-10', ObrigacaoStatus::Paga);

        $response = $this->controller->destroy(Request::create('POST', '/x'), ['id' => (string) $id]);

        $this->assertSame(409, $response->status);
        $this->assertStringContainsString('Guia paga não pode ser excluída', $response->body);
        $this->assertNotNull($this->guias->find($id));
    }

    public function testDestroyUnknownGuideReturns404(): void
    {
        $this->assertSame(404, $this->controller->destroy(Request::create('POST', '/x'), ['id' => '9'])->status);
    }

    // ---------- flash ----------

    public function testFlashIsShownOnceOnTheNextPage(): void
    {
        $this->store($this->valid());

        $first = $this->index()->body;
        $second = $this->index()->body;

        $this->assertStringContainsString('Guia cadastrada.', $first);
        $this->assertStringNotContainsString('Guia cadastrada.', $second);
    }
}

<?php

declare(strict_types=1);

namespace ContabilFlow\Tests\Unit;

use ContabilFlow\Controllers\EmpresaController;
use ContabilFlow\Core\Csrf;
use ContabilFlow\Core\Request;
use ContabilFlow\Core\Response;
use ContabilFlow\Core\Session;
use ContabilFlow\Core\View;
use ContabilFlow\Services\SemaforoService;
use ContabilFlow\Tests\Support\FixedClock;
use ContabilFlow\Tests\Support\InMemoryEmpresaRepository;
use ContabilFlow\Tests\Support\InMemoryObrigacaoRepository;
use ContabilFlow\Tests\Support\InMemoryUserRepository;
use ContabilFlow\Validation\Validator;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class EmpresaControllerTest extends TestCase
{
    private const CNPJ_A = '11222333000181';
    private const CNPJ_B = '11444777000161';

    /** @var array<string, mixed> */
    private array $sessionData = ['user_id' => 1, 'perfil' => 'contador', 'nome' => 'Ana'];
    private Session $session;
    private InMemoryEmpresaRepository $empresas;
    private InMemoryObrigacaoRepository $obrigacoes;
    private EmpresaController $controller;

    protected function setUp(): void
    {
        $this->session = new Session($this->sessionData);
        $this->empresas = new InMemoryEmpresaRepository();
        $this->obrigacoes = new InMemoryObrigacaoRepository($this->empresas);
        $users = new InMemoryUserRepository();
        $users->add(1, 'ana@x.com', 'h');
        $users->add(2, 'inativo@x.com', 'h', 'contador', false);

        $this->controller = new EmpresaController(
            $this->empresas,
            $users,
            new View(__DIR__ . '/../../views'),
            $this->session,
            new Csrf($this->session),
            new Validator(),
            $this->obrigacoes,
            new SemaforoService(new FixedClock(new DateTimeImmutable('2026-11-16 10:00:00')))
        );
    }

    /**
     * @param array<string, string> $override
     * @return array<string, string>
     */
    private function valid(array $override = []): array
    {
        return $override + [
            'razao_social' => 'Padaria Pão Quente Ltda', 'nome_fantasia' => 'Pão Quente',
            'cnpj' => '11.222.333/0001-81', 'regime' => 'simples', 'email' => 'Contato@Padaria.com',
            'whatsapp' => '(11) 98765-4321', 'responsavel_id' => '1', 'ativo' => '1',
        ];
    }

    /** @param array<string, string> $query */
    private function index(array $query = []): Response
    {
        return $this->controller->index(Request::create('GET', '/empresas?' . http_build_query($query)));
    }

    // ---------- listagem ----------

    public function testIndexListsCompaniesWithMaskedCnpjAndNavigation(): void
    {
        $this->empresas->seed('Padaria Alfa', self::CNPJ_A);

        $response = $this->index();

        $this->assertSame(200, $response->status);
        $this->assertStringContainsString('Padaria Alfa', $response->body);
        $this->assertStringContainsString('11.222.333/0001-81', $response->body);
        $this->assertStringContainsString('action="/logout"', $response->body);
        $this->assertStringContainsString('Ana', $response->body);
    }

    public function testIndexShowsEmptyState(): void
    {
        $this->assertStringContainsString('Nenhuma empresa encontrada', $this->index()->body);
    }

    public function testIndexPassesFiltersToRepository(): void
    {
        $this->index(['q' => 'padaria', 'regime' => 'mei', 'ativo' => '0', 'page' => '3', 'sort' => '-cnpj']);

        $filter = $this->empresas->lastFilter;
        $this->assertNotNull($filter);
        $this->assertSame('padaria', $filter->q);
        $this->assertSame('mei', $filter->regime);
        $this->assertFalse($filter->ativo);
        $this->assertSame(3, $filter->page);
        $this->assertSame('-cnpj', $filter->sort);
    }

    public function testIndexWithoutFiltersUsesDefaults(): void
    {
        $this->index();

        $filter = $this->empresas->lastFilter;
        $this->assertNotNull($filter);
        $this->assertNull($filter->q);
        $this->assertNull($filter->regime);
        $this->assertNull($filter->ativo);
        $this->assertSame(1, $filter->page);
        $this->assertSame('razao_social', $filter->sort);
    }

    /** @return array<string, array{0: array<string, string>}> */
    public static function invalidFilters(): array
    {
        return [
            'regime' => [['regime' => 'xpto']],
            'ativo' => [['ativo' => '2']],
            'sort' => [['sort' => 'senha_hash']],
            'sort injection' => [['sort' => 'razao_social; DROP TABLE empresas']],
            'page zero' => [['page' => '0']],
            'page text' => [['page' => 'abc']],
            'q too long' => [['q' => str_repeat('a', 101)]],
        ];
    }

    /** @param array<string, string> $query */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidFilters')]
    public function testIndexRejectsInvalidFiltersWith422(array $query): void
    {
        $response = $this->index($query);

        $this->assertSame(422, $response->status);
        $this->assertStringContainsString('Filtro inválido', $response->body);
    }

    public function testIndexEscapesCompanyNames(): void
    {
        $this->empresas->seed('<script>alert(1)</script>', self::CNPJ_A);

        $body = $this->index()->body;

        $this->assertStringNotContainsString('<script>alert(1)', $body);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $body);
    }

    public function testIndexShowsTotalOfFilteredResults(): void
    {
        for ($i = 1; $i <= 25; $i++) {
            $this->empresas->seed('Empresa ' . $i, sprintf('%014d', $i));
        }
        $response = $this->index(['q' => 'Empresa']);

        $this->assertStringContainsString('25 empresa(s)', $response->body);
    }

    // ---------- criar ----------

    public function testCreateFormIsRenderedWithCsrfAndActiveChecked(): void
    {
        $response = $this->controller->create(Request::create('GET', '/empresas/nova'));

        $this->assertSame(200, $response->status);
        $this->assertStringContainsString('name="_csrf"', $response->body);
        $this->assertStringContainsString('action="/empresas"', $response->body);
        $this->assertStringNotContainsString('name="_method"', $response->body);
        $this->assertMatchesRegularExpression('/name="ativo"[^>]*checked/', $response->body);
        $this->assertStringContainsString('Ana', $response->body); // opção de responsável
    }

    public function testStoreCreatesNormalizedCompanyAndRedirectsWithFlash(): void
    {
        $response = $this->controller->store(Request::create('POST', '/empresas', $this->valid()));

        $this->assertSame(302, $response->status);
        $this->assertSame('/empresas/1', $response->headers['Location']);
        $empresa = $this->empresas->find(1);
        $this->assertSame(self::CNPJ_A, $empresa?->cnpj);
        $this->assertSame('5511987654321', $empresa->whatsapp);
        $this->assertSame('contato@padaria.com', $empresa->email);
        $this->assertSame(1, $empresa->responsavelId);
        $this->assertSame([['type' => 'success', 'message' => 'Empresa cadastrada.']], $this->session->pullFlash());
    }

    public function testStoreWithInvalidDataReturns422KeepingInputAndShowingErrors(): void
    {
        $response = $this->controller->store(Request::create('POST', '/empresas', $this->valid([
            'razao_social' => '', 'cnpj' => '11.111.111/1111-11', 'nome_fantasia' => 'Mantida',
        ])));

        $this->assertSame(422, $response->status);
        $this->assertStringContainsString('Campo obrigatório', $response->body);
        $this->assertStringContainsString('CNPJ inválido', $response->body);
        $this->assertStringContainsString('value="Mantida"', $response->body);
        $this->assertStringContainsString('value="11.111.111/1111-11"', $response->body);
        $this->assertSame([], $this->empresas->rows);
    }

    public function testStoreWithDuplicateCnpjReturns409WithFieldError(): void
    {
        $this->empresas->seed('Existente', self::CNPJ_A);

        $response = $this->controller->store(Request::create('POST', '/empresas', $this->valid()));

        $this->assertSame(409, $response->status);
        $this->assertStringContainsString('CNPJ já cadastrado', $response->body);
        $this->assertStringContainsString('value="Padaria Pão Quente Ltda"', $response->body);
        $this->assertCount(1, $this->empresas->rows);
    }

    public function testStoreRejectsInactiveOrUnknownResponsible(): void
    {
        foreach (['2', '99', 'abc'] as $id) {
            $response = $this->controller->store(
                Request::create('POST', '/empresas', $this->valid(['responsavel_id' => $id]))
            );

            $this->assertSame(422, $response->status, $id);
            $this->assertStringContainsString('Responsável inválido', $response->body);
        }
    }

    public function testStoreIgnoresUncheckedActiveAndExtraFields(): void
    {
        $post = $this->valid(['id' => '99', 'versao' => '50', 'perfil' => 'admin']);
        unset($post['ativo']);

        $this->controller->store(Request::create('POST', '/empresas', $post));

        $this->assertSame([1], array_keys($this->empresas->rows));
        $this->assertFalse($this->empresas->find(1)?->ativo);
        $this->assertSame(1, $this->empresas->find(1)->versao);
    }

    public function testStoreEscapesEchoedInput(): void
    {
        $response = $this->controller->store(Request::create('POST', '/empresas', $this->valid([
            'razao_social' => '"><script>alert(1)</script>', 'cnpj' => 'x',
        ])));

        $this->assertStringNotContainsString('<script>alert(1)', $response->body);
    }

    // ---------- ver ----------

    public function testShowDisplaysCompanyAndDeleteFormWithCsrf(): void
    {
        $id = $this->empresas->seed('Padaria Alfa', self::CNPJ_A);

        $response = $this->controller->show(Request::create('GET', '/empresas/1'), ['id' => (string) $id]);

        $this->assertSame(200, $response->status);
        $this->assertStringContainsString('Padaria Alfa', $response->body);
        $this->assertStringContainsString('name="_method" value="DELETE"', $response->body);
        $this->assertStringContainsString('name="_csrf"', $response->body);
    }

    public function testShowListsTheCompanysGuidesWithBadgeAndLinkToCreateMore(): void
    {
        $id = $this->empresas->seed('Padaria Alfa', self::CNPJ_A);
        $outra = $this->empresas->seed('Outra', self::CNPJ_B);
        $this->obrigacoes->seed($id, 'DAS', '2026-10-01', '2026-11-10');
        $this->obrigacoes->seed($outra, 'DARF');

        $body = $this->controller->show(Request::create('GET', '/x'), ['id' => (string) $id])->body;

        $this->assertStringContainsString('Vencida', $body);
        $this->assertStringContainsString('/obrigacoes/1', $body);
        $this->assertStringNotContainsString('/obrigacoes/2', $body);
        $this->assertStringContainsString('/obrigacoes/nova?empresa_id=' . $id, $body);
        $this->assertSame($id, $this->obrigacoes->lastFilter?->empresaId);
    }

    public function testShowWithoutGuidesShowsEmptyMessage(): void
    {
        $id = $this->empresas->seed('Padaria Alfa', self::CNPJ_A);

        $body = $this->controller->show(Request::create('GET', '/x'), ['id' => (string) $id])->body;

        $this->assertStringContainsString('Nenhuma guia cadastrada para esta empresa', $body);
    }

    public function testShowUnknownCompanyReturns404(): void
    {
        $this->assertSame(404, $this->controller->show(Request::create('GET', '/empresas/9'), ['id' => '9'])->status);
    }

    // ---------- editar ----------

    public function testEditFormIsPrefilledWithVersionAndPutOverride(): void
    {
        $this->controller->store(Request::create('POST', '/empresas', $this->valid()));
        $this->session->pullFlash();

        $response = $this->controller->edit(Request::create('GET', '/empresas/1/editar'), ['id' => '1']);

        $this->assertSame(200, $response->status);
        $this->assertStringContainsString('name="_method" value="PUT"', $response->body);
        $this->assertStringContainsString('name="versao" value="1"', $response->body);
        $this->assertStringContainsString('value="11.222.333/0001-81"', $response->body);
        $this->assertStringContainsString('value="(11) 98765-4321"', $response->body);
    }

    public function testEditUnknownCompanyReturns404(): void
    {
        $this->assertSame(404, $this->controller->edit(Request::create('GET', '/x'), ['id' => '9'])->status);
    }

    public function testUpdateSavesAndRedirects(): void
    {
        $this->controller->store(Request::create('POST', '/empresas', $this->valid()));

        $response = $this->controller->update(
            Request::create('POST', '/empresas/1', $this->valid(['razao_social' => 'Nova Razão', 'versao' => '1'])),
            ['id' => '1']
        );

        $this->assertSame(302, $response->status);
        $this->assertSame('Nova Razão', $this->empresas->find(1)?->razaoSocial);
        $this->assertSame(2, $this->empresas->find(1)->versao);
    }

    public function testUpdateWithInvalidDataReturns422KeepingVersion(): void
    {
        $this->controller->store(Request::create('POST', '/empresas', $this->valid()));

        $response = $this->controller->update(
            Request::create('POST', '/empresas/1', $this->valid(['razao_social' => '', 'versao' => '1'])),
            ['id' => '1']
        );

        $this->assertSame(422, $response->status);
        $this->assertStringContainsString('name="versao" value="1"', $response->body);
        $this->assertSame(1, $this->empresas->find(1)?->versao);
    }

    public function testUpdateWithMissingVersionIsRejected(): void
    {
        $this->controller->store(Request::create('POST', '/empresas', $this->valid()));

        $response = $this->controller->update(Request::create('POST', '/empresas/1', $this->valid()), ['id' => '1']);

        $this->assertSame(422, $response->status);
        $this->assertSame(1, $this->empresas->find(1)?->versao);
    }

    public function testUpdateWithStaleVersionReturns409ShowingCurrentData(): void
    {
        $this->controller->store(Request::create('POST', '/empresas', $this->valid()));
        $this->controller->update(
            Request::create('POST', '/empresas/1', $this->valid(['razao_social' => 'Feita por Bia', 'versao' => '1'])),
            ['id' => '1']
        );

        $response = $this->controller->update(
            Request::create('POST', '/empresas/1', $this->valid(['razao_social' => 'Feita por Ana', 'versao' => '1'])),
            ['id' => '1']
        );

        $this->assertSame(409, $response->status);
        $this->assertStringContainsString('alterada por outra pessoa', $response->body);
        $this->assertStringContainsString('value="Feita por Bia"', $response->body);
        $this->assertStringContainsString('name="versao" value="2"', $response->body);
        $this->assertSame('Feita por Bia', $this->empresas->find(1)?->razaoSocial);
    }

    public function testUpdateToDuplicateCnpjReturns409(): void
    {
        $this->empresas->seed('Outra', self::CNPJ_B);
        $this->controller->store(Request::create('POST', '/empresas', $this->valid()));

        $response = $this->controller->update(
            Request::create('POST', '/empresas/2', $this->valid(['cnpj' => self::CNPJ_B, 'versao' => '1'])),
            ['id' => '2']
        );

        $this->assertSame(409, $response->status);
        $this->assertStringContainsString('CNPJ já cadastrado', $response->body);
    }

    public function testUpdateUnknownCompanyReturns404(): void
    {
        $response = $this->controller->update(
            Request::create('POST', '/empresas/9', $this->valid(['versao' => '1'])),
            ['id' => '9']
        );

        $this->assertSame(404, $response->status);
    }

    // ---------- excluir ----------

    public function testDestroyDeletesAndRedirectsWithFlash(): void
    {
        $this->empresas->seed('Alfa', self::CNPJ_A);

        $response = $this->controller->destroy(Request::create('POST', '/empresas/1'), ['id' => '1']);

        $this->assertSame(302, $response->status);
        $this->assertSame('/empresas', $response->headers['Location']);
        $this->assertNull($this->empresas->find(1));
        $this->assertSame([['type' => 'success', 'message' => 'Empresa excluída.']], $this->session->pullFlash());
    }

    public function testDestroyBlockedByLinksReturns409SuggestingDeactivation(): void
    {
        $this->empresas->seed('Alfa', self::CNPJ_A);
        $this->empresas->linked = [1];

        $response = $this->controller->destroy(Request::create('POST', '/empresas/1'), ['id' => '1']);

        $this->assertSame(409, $response->status);
        $this->assertStringContainsString('Desative-a em vez de excluir', $response->body);
        $this->assertNotNull($this->empresas->find(1));
    }

    public function testDestroyUnknownCompanyReturns404(): void
    {
        $this->assertSame(404, $this->controller->destroy(Request::create('POST', '/x'), ['id' => '9'])->status);
    }

    // ---------- flash ----------

    public function testFlashMessageIsShownOnceAfterRedirect(): void
    {
        $this->controller->store(Request::create('POST', '/empresas', $this->valid()));

        $first = $this->index()->body;
        $second = $this->index()->body;

        $this->assertStringContainsString('Empresa cadastrada.', $first);
        $this->assertStringNotContainsString('Empresa cadastrada.', $second);
    }
}

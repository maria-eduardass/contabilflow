<?php

declare(strict_types=1);

namespace ContabilFlow\Tests\Integration\Http;

use ContabilFlow\Tests\Support\Http\HttpTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/** CRUD de empresas de ponta a ponta (empresas 1 a 3 do seed; só a 1 e a 2 têm guias... e a 3 também). */
final class EmpresaFlowTest extends HttpTestCase
{
    /**
     * @param array<string, string> $override
     * @return array<string, string>
     */
    private function valid(string $cnpj = '45.723.174/0001-10', array $override = []): array
    {
        // CNPJ já usado no seed (Tech Soluções); os testes de criação usam outro, gerado válido abaixo.
        return $override + [
            'razao_social' => 'Mercearia Nova Ltda', 'nome_fantasia' => 'Nova', 'cnpj' => $cnpj,
            'regime' => 'simples', 'email' => 'Contato@Nova.com', 'whatsapp' => '(21) 99876-5432',
            'responsavel_id' => '2', 'ativo' => '1',
        ];
    }

    /** CNPJ válido que não existe no seed. */
    private const NEW_CNPJ = '10.000.000/0001-45';

    // ---------- listagem ----------

    public function testListShowsTheSeedCompaniesWithMasksAndBadges(): void
    {
        $response = $this->contador()->get('/empresas');

        $this->assertSame(200, $response->status);
        foreach (['Padaria Pão Quente Ltda', 'Oficina Mecânica Silva ME', 'Tech Soluções S.A.'] as $name) {
            $this->assertBodyContains($name, $response);
        }
        $this->assertBodyContains('11.222.333/0001-81', $response);
        $this->assertBodyContains('(11) 98765-4321', $response);
        $this->assertBodyContains('Simples Nacional', $response);
        $this->assertBodyContains('Lucro Presumido', $response);
        $this->assertBodyContains('3 empresa(s)', $response);
    }

    public function testSearchByNameFantasyAndMaskedCnpj(): void
    {
        $admin = $this->admin();

        $byName = $admin->get('/empresas?q=oficina');
        $byFantasy = $admin->get('/empresas?q=techsol');
        $byCnpj = $admin->get('/empresas?q=' . rawurlencode('11.222.333/0001-81'));
        $none = $admin->get('/empresas?q=inexistente');

        $this->assertBodyContains('1 empresa(s)', $byName);
        $this->assertBodyContains('Oficina Mecânica Silva ME', $byName);
        $this->assertBodyContains('Tech Soluções S.A.', $byFantasy);
        $this->assertBodyContains('Padaria Pão Quente Ltda', $byCnpj);
        $this->assertBodyContains('Nenhuma empresa encontrada', $none);
    }

    public function testSearchTreatsWildcardsAndSqlAsPlainText(): void
    {
        $admin = $this->admin();

        $this->assertBodyContains('Nenhuma empresa encontrada', $admin->get('/empresas?q=%25'));
        $this->assertBodyContains('Nenhuma empresa encontrada', $admin->get('/empresas?q=_'));
        $this->assertBodyContains('Nenhuma empresa encontrada', $admin->get('/empresas?q=' . rawurlencode("' OR '1'='1")));
        $this->assertSame(3, $this->rows('empresas'));
    }

    public function testFiltersByRegimeAndActiveStatus(): void
    {
        $this->pdo->exec('UPDATE empresas SET ativo = 0 WHERE id = 2');
        $admin = $this->admin();

        $this->assertBodyContains('1 empresa(s)', $admin->get('/empresas?regime=mei'));
        $this->assertBodyContains('Oficina Mecânica Silva ME', $admin->get('/empresas?ativo=0'));
        $this->assertBodyContains('2 empresa(s)', $admin->get('/empresas?ativo=1'));
        $this->assertBodyContains('Nenhuma empresa encontrada', $admin->get('/empresas?regime=mei&ativo=1'));
    }

    public function testSortingAndPagination(): void
    {
        $admin = $this->admin();
        $asc = $admin->get('/empresas')->body;
        $desc = $admin->get('/empresas?sort=-razao_social')->body;

        $this->assertLessThan(strpos($asc, 'Tech Soluções'), strpos($asc, 'Oficina'));
        $this->assertLessThan(strpos($desc, 'Oficina'), strpos($desc, 'Tech Soluções'));

        for ($i = 1; $i <= 22; $i++) {
            $this->pdo->prepare("INSERT INTO empresas (razao_social, cnpj, regime) VALUES (?, ?, 'mei')")
                ->execute([sprintf('Empresa Extra %02d', $i), sprintf('%014d', $i)]);
        }
        $page1 = $admin->get('/empresas');
        $page2 = $admin->get('/empresas?page=2');

        $this->assertBodyContains('25 empresa(s) · página 1 de 2', $page1);
        $this->assertBodyContains('página 2 de 2', $page2);
        $this->assertBodyContains('Próxima', $page1);
        $this->assertSame(20, substr_count($page1->body, '<tr>') - 1);
        $this->assertSame(5, substr_count($page2->body, '<tr>') - 1);
    }

    /** @return array<string, array{0: string}> */
    public static function invalidFilters(): array
    {
        return [
            'regime' => ['regime=xpto'], 'ativo' => ['ativo=2'], 'sort' => ['sort=senha_hash'],
            'sort injection' => ['sort=razao_social;DROP'], 'page' => ['page=0'], 'page texto' => ['page=abc'],
        ];
    }

    #[DataProvider('invalidFilters')]
    public function testInvalidFiltersAre422(string $query): void
    {
        $response = $this->admin()->get('/empresas?' . $query);

        $this->assertSame(422, $response->status);
        $this->assertBodyContains('Filtro inválido', $response);
    }

    // ---------- criar ----------

    public function testCreateShowsTheFormWithActiveAccountantsAsResponsibles(): void
    {
        $this->pdo->exec("INSERT INTO usuarios (nome, email, senha_hash, perfil, ativo) VALUES ('Inativo Ivo', 'i@x.com', 'h', 'contador', 0)");

        $response = $this->contador()->get('/empresas/nova');

        $this->assertSame(200, $response->status);
        $this->assertBodyContains('Contador Demo', $response);
        $this->assertBodyNotContains('Inativo Ivo', $response);
        $this->assertBodyContains('name="_csrf"', $response);
    }

    public function testCreateNormalizesDataAndShowsAFlashOnlyOnce(): void
    {
        $admin = $this->admin();

        $response = $admin->post('/empresas', $this->valid(self::NEW_CNPJ));

        $this->assertRedirectTo('/empresas/4', $response);
        $row = $this->row('SELECT * FROM empresas WHERE id = 4');
        $this->assertSame('10000000000145', $row['cnpj']);
        $this->assertSame('5521998765432', $row['whatsapp']);
        $this->assertSame('contato@nova.com', $row['email']);
        $this->assertSame(2, (int) $row['responsavel_id']);
        $this->assertSame(1, (int) $row['versao']);
        $page = $admin->follow($response);
        $this->assertSame('Empresa cadastrada.', $this->alertText($page));
        $this->assertNull($this->alertText($admin->get('/empresas/4')));
    }

    public function testCreateWithInvalidDataKeepsTheInputAndShowsEveryError(): void
    {
        $response = $this->admin()->post('/empresas', [
            'razao_social' => '', 'nome_fantasia' => 'Mantido', 'cnpj' => '11.111.111/1111-11', 'regime' => 'xpto',
            'email' => 'nao-e-email', 'whatsapp' => '123', 'responsavel_id' => '999',
        ]);

        $this->assertSame(422, $response->status);
        foreach (['Campo obrigatório', 'CNPJ inválido', 'Valor não permitido', 'E-mail inválido', 'WhatsApp inválido', 'Responsável inválido'] as $msg) {
            $this->assertBodyContains($msg, $response);
        }
        $this->assertBodyContains('value="Mantido"', $response);
        $this->assertBodyContains('value="11.111.111/1111-11"', $response);
        $this->assertSame(3, $this->rows('empresas'));
    }

    public function testDuplicateCnpjIsRejectedEvenWithADifferentMask(): void
    {
        $admin = $this->admin();

        $masked = $admin->post('/empresas', $this->valid('11.222.333/0001-81'));
        $digits = $admin->post('/empresas', $this->valid('11222333000181'));

        $this->assertSame(409, $masked->status);
        $this->assertSame(409, $digits->status);
        $this->assertBodyContains('CNPJ já cadastrado', $masked);
        $this->assertBodyContains('value="Mercearia Nova Ltda"', $masked);
        $this->assertSame(3, $this->rows('empresas'));
    }

    public function testCreateIgnoresFieldsThatAreNotInTheForm(): void
    {
        $this->admin()->post('/empresas', $this->valid(self::NEW_CNPJ) + ['id' => '99', 'versao' => '50', 'criado_em' => '2000-01-01']);

        $row = $this->row('SELECT id, versao, YEAR(criado_em) AS ano FROM empresas ORDER BY id DESC LIMIT 1');
        $this->assertSame(4, (int) $row['id']);
        $this->assertSame(1, (int) $row['versao']);
        $this->assertGreaterThan(2000, (int) $row['ano']);
    }

    // ---------- detalhe ----------

    public function testDetailShowsCompanyDataAndItsGuidesOnly(): void
    {
        $response = $this->contador()->get('/empresas/1');

        $this->assertSame(200, $response->status);
        $this->assertBodyContains('Padaria Pão Quente Ltda', $response);
        $this->assertBodyContains('Pão Quente', $response);
        $this->assertBodyContains('contato@paoquente.example', $response);
        $this->assertBodyContains('/obrigacoes/1"', $response);   // DAS
        $this->assertBodyContains('/obrigacoes/2"', $response);   // FGTS
        $this->assertBodyContains('/obrigacoes/3"', $response);   // INSS
        $this->assertBodyNotContains('/obrigacoes/4"', $response); // guia de outra empresa
        $this->assertBodyContains('/obrigacoes/nova?empresa_id=1', $response);
        $this->assertBodyContains('Contador Demo', $response);     // responsável
    }

    public function testDetailOfACompanyWithoutGuidesShowsTheEmptyMessage(): void
    {
        $this->admin()->post('/empresas', $this->valid(self::NEW_CNPJ));

        $this->assertBodyContains('Nenhuma guia cadastrada para esta empresa', $this->admin()->get('/empresas/4'));
    }

    public function testUnknownCompanyIs404InEveryScreen(): void
    {
        $admin = $this->admin();

        $this->assertSame(404, $admin->get('/empresas/999')->status);
        $this->assertSame(404, $admin->get('/empresas/999/editar')->status);
        $this->assertSame(404, $admin->put('/empresas/999', $this->valid(self::NEW_CNPJ) + ['versao' => '1'])->status);
        $this->assertSame(404, $admin->delete('/empresas/999')->status);
    }

    // ---------- editar ----------

    public function testEditFormIsPrefilledWithTheVersion(): void
    {
        $response = $this->contador()->get('/empresas/1/editar');

        $this->assertSame(200, $response->status);
        $this->assertBodyContains('name="_method" value="PUT"', $response);
        $this->assertBodyContains('name="versao" value="1"', $response);
        $this->assertBodyContains('value="Padaria Pão Quente Ltda"', $response);
        $this->assertBodyContains('value="11.222.333/0001-81"', $response);
        $this->assertBodyContains('value="(11) 98765-4321"', $response);
    }

    public function testUpdateSavesAndBumpsTheVersion(): void
    {
        $admin = $this->admin();

        $response = $admin->put('/empresas/1', $this->valid('11.222.333/0001-81', ['razao_social' => 'Padaria Renomeada', 'versao' => '1']));

        $this->assertRedirectTo('/empresas/1', $response);
        $row = $this->row('SELECT razao_social, versao FROM empresas WHERE id = 1');
        $this->assertSame('Padaria Renomeada', $row['razao_social']);
        $this->assertSame(2, (int) $row['versao']);
        $this->assertSame('Empresa atualizada.', $this->alertText($admin->follow($response)));
    }

    public function testTwoPeopleEditingTheSameCompanyTheSecondGetsAConflictWithCurrentData(): void
    {
        $ana = $this->admin();
        $bia = $this->contador();
        $form = $bia->get('/empresas/1/editar'); // a Bia abre o formulário (versão 1)
        $this->assertBodyContains('name="versao" value="1"', $form);

        $ana->put('/empresas/1', $this->valid('11.222.333/0001-81', ['razao_social' => 'Alterada pela Ana', 'versao' => '1']));
        $response = $bia->put('/empresas/1', $this->valid('11.222.333/0001-81', ['razao_social' => 'Alterada pela Bia', 'versao' => '1']));

        $this->assertSame(409, $response->status);
        $this->assertBodyContains('alterada por outra pessoa', $response);
        $this->assertBodyContains('value="Alterada pela Ana"', $response);
        $this->assertBodyContains('name="versao" value="2"', $response);
        $this->assertSame('Alterada pela Ana', $this->scalar('SELECT razao_social FROM empresas WHERE id = 1'));
    }

    public function testUpdateToAnotherCompanysCnpjIs409(): void
    {
        $response = $this->admin()->put('/empresas/1', $this->valid('11.444.777/0001-61', ['versao' => '1']));

        $this->assertSame(409, $response->status);
        $this->assertBodyContains('CNPJ já cadastrado', $response);
        $this->assertSame('11222333000181', $this->scalar('SELECT cnpj FROM empresas WHERE id = 1'));
    }

    public function testUpdateWithInvalidDataOrMissingVersionIs422AndChangesNothing(): void
    {
        $admin = $this->admin();

        $invalid = $admin->put('/empresas/1', $this->valid('11.222.333/0001-81', ['razao_social' => '', 'versao' => '1']));
        $noVersion = $admin->put('/empresas/1', $this->valid('11.222.333/0001-81'));

        $this->assertSame(422, $invalid->status);
        $this->assertSame(422, $noVersion->status);
        $this->assertSame('Padaria Pão Quente Ltda', $this->scalar('SELECT razao_social FROM empresas WHERE id = 1'));
        $this->assertSame(1, (int) $this->scalar('SELECT versao FROM empresas WHERE id = 1'));
    }

    public function testDeactivatedCompanyCannotReceiveNewGuides(): void
    {
        $admin = $this->admin();
        $post = $this->valid('11.222.333/0001-81', ['versao' => '1']);
        unset($post['ativo']);

        $admin->put('/empresas/1', $post);
        $guide = $admin->post('/obrigacoes', [
            'empresa_id' => '1', 'tipo' => 'ISS', 'competencia' => '2026-10', 'vencimento' => '2026-11-20',
        ]);

        $this->assertSame(0, (int) $this->scalar('SELECT ativo FROM empresas WHERE id = 1'));
        $this->assertSame(422, $guide->status);
        $this->assertBodyContains('inexistente ou inativa', $guide);
        $this->assertBodyContains('Inativa', $admin->get('/empresas/1'));
    }

    // ---------- excluir ----------

    public function testDeletingACompanyWithGuidesIsBlockedAndSuggestsDeactivation(): void
    {
        $response = $this->admin()->delete('/empresas/1');

        $this->assertSame(409, $response->status);
        $this->assertBodyContains('Desative-a em vez de excluir', $response);
        $this->assertSame(1, $this->rows('empresas', 'id = 1'));
        $this->assertSame(3, $this->rows('obrigacoes', 'empresa_id = 1'));
    }

    public function testDeletingACompanyWithoutGuidesWorks(): void
    {
        $admin = $this->admin();
        $admin->follow($admin->post('/empresas', $this->valid(self::NEW_CNPJ)));

        $response = $admin->delete('/empresas/4');

        $this->assertRedirectTo('/empresas', $response);
        $this->assertSame(0, $this->rows('empresas', 'id = 4'));
        $this->assertSame('Empresa excluída.', $this->alertText($admin->follow($response)));
    }

    // ---------- segurança dos dados exibidos ----------

    public function testCompanyNamesAreEscapedEverywhere(): void
    {
        $admin = $this->admin();
        $payload = '<script>alert(1)</script>';
        $this->pdo->prepare('UPDATE empresas SET razao_social = ?, nome_fantasia = ? WHERE id = 1')->execute([$payload, '<b>x</b>']);

        foreach (['/empresas', '/empresas/1', '/empresas/1/editar', '/obrigacoes', '/obrigacoes/1', '/', '/obrigacoes/nova'] as $page) {
            $body = $admin->get($page)->body;

            $this->assertStringNotContainsString($payload, $body, $page);
            $this->assertStringNotContainsString('<b>x</b>', $body, $page);
        }
    }

    public function testResponsibleMustBeAnActiveUser(): void
    {
        $this->pdo->exec("INSERT INTO usuarios (nome, email, senha_hash, perfil, ativo) VALUES ('Inativo', 'i@x.com', 'h', 'contador', 0)");

        $response = $this->admin()->post('/empresas', $this->valid(self::NEW_CNPJ, ['responsavel_id' => '3']));

        $this->assertSame(422, $response->status);
        $this->assertBodyContains('Responsável inválido', $response);
    }
}

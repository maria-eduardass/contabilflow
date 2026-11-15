<?php

declare(strict_types=1);

namespace ContabilFlow\Tests\Integration;

use ContabilFlow\Core\LazyPdo;
use ContabilFlow\Core\Migrator;
use ContabilFlow\Models\ConflictException;
use ContabilFlow\Models\DuplicateException;
use ContabilFlow\Models\EmpresaDados;
use ContabilFlow\Models\EmpresaFiltro;
use ContabilFlow\Models\EmpresaRepository;
use ContabilFlow\Models\RecordNotFoundException;
use ContabilFlow\Models\StaleVersionException;
use ContabilFlow\Tests\Support\TestDatabase;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;

final class EmpresaRepositoryTest extends TestCase
{
    private PDO $pdo;
    private EmpresaRepository $repo;

    protected function setUp(): void
    {
        $pdo = TestDatabase::connectOrNull();
        if ($pdo === null) {
            $this->markTestSkipped('Crie o .env a partir do .env.example para rodar a integração.');
        }
        $this->pdo = $pdo;
        TestDatabase::reset($this->pdo);
        (new Migrator($this->pdo, __DIR__ . '/../../database/migrations'))->run();
        $this->repo = new EmpresaRepository(new LazyPdo(fn(): PDO => $pdo));
    }

    private function dados(
        string $razao = 'Padaria Pão Quente Ltda',
        string $cnpj = '11222333000181',
        string $regime = 'simples',
        bool $ativo = true,
        ?string $fantasia = null,
    ): EmpresaDados {
        return new EmpresaDados($razao, $fantasia, $cnpj, $regime, 'a@b.com', '5511987654321', null, $ativo);
    }

    private function usuario(): int
    {
        $this->pdo->exec(
            "INSERT INTO usuarios (nome, email, senha_hash, perfil) VALUES ('Ana', 'ana@x.com', 'h', 'contador')"
        );

        return (int) $this->pdo->lastInsertId();
    }

    private function obrigacaoPara(int $empresaId): void
    {
        $this->pdo->prepare(
            "INSERT INTO obrigacoes (empresa_id, tipo, competencia, vencimento, criado_por) "
            . "VALUES (?, 'DAS', '2026-10-01', '2026-11-20', ?)"
        )->execute([$empresaId, $this->usuario()]);
    }

    public function testCreateAndFindRoundTrip(): void
    {
        $id = $this->repo->create($this->dados(fantasia: 'Pão Quente'));

        $empresa = $this->repo->find($id);

        $this->assertNotNull($empresa);
        $this->assertSame($id, $empresa->id);
        $this->assertSame('Padaria Pão Quente Ltda', $empresa->razaoSocial);
        $this->assertSame('Pão Quente', $empresa->nomeFantasia);
        $this->assertSame('11222333000181', $empresa->cnpj);
        $this->assertSame('simples', $empresa->regime);
        $this->assertSame('5511987654321', $empresa->whatsapp);
        $this->assertNull($empresa->responsavelId);
        $this->assertTrue($empresa->ativo);
        $this->assertSame(1, $empresa->versao);
    }

    public function testFindReturnsNullForUnknownId(): void
    {
        $this->assertNull($this->repo->find(999));
    }

    public function testCreateWithDuplicateCnpjThrowsDuplicateOnCnpjField(): void
    {
        $this->repo->create($this->dados());

        try {
            $this->repo->create($this->dados(razao: 'Outra Ltda'));
            $this->fail('Deveria lançar DuplicateException');
        } catch (DuplicateException $e) {
            $this->assertSame('cnpj', $e->field);
        }
    }

    public function testUpdateChangesFieldsAndBumpsVersion(): void
    {
        $id = $this->repo->create($this->dados());

        $this->repo->update($id, 1, $this->dados(razao: 'Nova Razão', regime: 'presumido', ativo: false));

        $empresa = $this->repo->find($id);
        $this->assertNotNull($empresa);
        $this->assertSame('Nova Razão', $empresa->razaoSocial);
        $this->assertSame('presumido', $empresa->regime);
        $this->assertFalse($empresa->ativo);
        $this->assertSame(2, $empresa->versao);
    }

    public function testSecondUpdateWithSameVersionFails(): void
    {
        $id = $this->repo->create($this->dados());
        $this->repo->update($id, 1, $this->dados(razao: 'Primeira'));

        $this->expectException(StaleVersionException::class);
        $this->repo->update($id, 1, $this->dados(razao: 'Segunda'));
    }

    public function testStaleUpdateDoesNotOverwrite(): void
    {
        $id = $this->repo->create($this->dados());
        $this->repo->update($id, 1, $this->dados(razao: 'Primeira'));

        try {
            $this->repo->update($id, 1, $this->dados(razao: 'Segunda'));
        } catch (StaleVersionException) {
        }

        $this->assertSame('Primeira', $this->repo->find($id)?->razaoSocial);
    }

    public function testUpdateOfMissingRowThrowsNotFound(): void
    {
        $this->expectException(RecordNotFoundException::class);
        $this->repo->update(999, 1, $this->dados());
    }

    public function testUpdateToAnotherCompanysCnpjThrowsDuplicate(): void
    {
        $this->repo->create($this->dados());
        $id = $this->repo->create($this->dados(razao: 'B', cnpj: '11444777000161'));

        $this->expectException(DuplicateException::class);
        $this->repo->update($id, 1, $this->dados(razao: 'B', cnpj: '11222333000181'));
    }

    public function testDeleteRemovesRow(): void
    {
        $id = $this->repo->create($this->dados());

        $this->assertTrue($this->repo->delete($id));
        $this->assertNull($this->repo->find($id));
    }

    public function testDeleteOfMissingRowReturnsFalse(): void
    {
        $this->assertFalse($this->repo->delete(999));
    }

    public function testDeleteWithObligationsThrowsConflictAndKeepsRow(): void
    {
        $id = $this->repo->create($this->dados());
        $this->obrigacaoPara($id);

        try {
            $this->repo->delete($id);
            $this->fail('Deveria lançar ConflictException');
        } catch (ConflictException) {
            $this->assertNotNull($this->repo->find($id));
        }
    }

    public function testSearchFiltersByTextInNameFantasyAndCnpj(): void
    {
        $this->repo->create($this->dados(razao: 'Padaria Alfa', fantasia: 'Pão Quente'));
        $this->repo->create($this->dados(razao: 'Oficina Beta', cnpj: '11444777000161'));

        $this->assertSame(1, $this->repo->search(new EmpresaFiltro(q: 'padaria'))->total);
        $this->assertSame(1, $this->repo->search(new EmpresaFiltro(q: 'quente'))->total);
        $this->assertSame(1, $this->repo->search(new EmpresaFiltro(q: '11.444.777/0001-61'))->total);
        $this->assertSame(0, $this->repo->search(new EmpresaFiltro(q: 'inexistente'))->total);
    }

    public function testSearchTreatsLikeWildcardsAsLiterals(): void
    {
        $this->repo->create($this->dados(razao: 'Alfa 100% Ltda'));
        $this->repo->create($this->dados(razao: 'Beta Ltda', cnpj: '11444777000161'));

        $result = $this->repo->search(new EmpresaFiltro(q: '%'));

        $this->assertSame(1, $result->total);
        $this->assertSame('Alfa 100% Ltda', $result->items[0]->razaoSocial);
        $this->assertSame(0, $this->repo->search(new EmpresaFiltro(q: '_'))->total);
    }

    public function testSearchWithInjectionPayloadIsPlainText(): void
    {
        $this->repo->create($this->dados());

        $this->assertSame(0, $this->repo->search(new EmpresaFiltro(q: "' OR '1'='1"))->total);
    }

    public function testSearchFiltersByRegimeAndActive(): void
    {
        $this->repo->create($this->dados(razao: 'A', regime: 'mei'));
        $this->repo->create($this->dados(razao: 'B', cnpj: '11444777000161', regime: 'simples', ativo: false));

        $this->assertSame(1, $this->repo->search(new EmpresaFiltro(regime: 'mei'))->total);
        $this->assertSame(1, $this->repo->search(new EmpresaFiltro(ativo: false))->total);
        $this->assertSame(0, $this->repo->search(new EmpresaFiltro(regime: 'mei', ativo: false))->total);
        $this->assertSame(2, $this->repo->search(new EmpresaFiltro())->total);
    }

    public function testPaginationSplitsResultsAndReportsTotals(): void
    {
        for ($i = 1; $i <= 25; $i++) {
            $this->repo->create($this->dados(razao: sprintf('Empresa %02d', $i), cnpj: sprintf('%014d', $i)));
        }

        $first = $this->repo->search(new EmpresaFiltro());
        $third = $this->repo->search(new EmpresaFiltro(page: 2));

        $this->assertCount(20, $first->items);
        $this->assertSame(25, $first->total);
        $this->assertSame(2, $first->pages());
        $this->assertSame('Empresa 01', $first->items[0]->razaoSocial);
        $this->assertCount(5, $third->items);
        $this->assertSame('Empresa 21', $third->items[0]->razaoSocial);
    }

    public function testPageBeyondTheEndIsEmptyAndInvalidPageFallsToFirst(): void
    {
        $this->repo->create($this->dados());

        $this->assertSame([], $this->repo->search(new EmpresaFiltro(page: 5))->items);
        $this->assertCount(1, $this->repo->search(new EmpresaFiltro(page: 0))->items);
    }

    public function testSortOptionsAreWhitelisted(): void
    {
        $this->repo->create($this->dados(razao: 'A'));
        $this->repo->create($this->dados(razao: 'B', cnpj: '11444777000161'));

        $desc = $this->repo->search(new EmpresaFiltro(sort: '-razao_social'));

        $this->assertSame('B', $desc->items[0]->razaoSocial);
    }

    public function testInvalidSortIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->repo->search(new EmpresaFiltro(sort: 'razao_social; DROP TABLE empresas'));
    }

    public function testResponsavelIsPersisted(): void
    {
        $userId = $this->usuario();
        $dados = new EmpresaDados('X Ltda', null, '11222333000181', 'mei', null, null, $userId, true);

        $id = $this->repo->create($dados);

        $this->assertSame($userId, $this->repo->find($id)?->responsavelId);
    }
}

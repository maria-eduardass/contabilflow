<?php

declare(strict_types=1);

namespace ContabilFlow\Tests\Integration;

use ContabilFlow\Core\Migrator;
use ContabilFlow\Tests\Support\TestDatabase;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

/** Garante que as regras de integridade estão no banco, não só na aplicação. */
final class SchemaConstraintsTest extends TestCase
{
    private PDO $pdo;
    private int $empresaId;
    private int $usuarioId;

    protected function setUp(): void
    {
        $pdo = TestDatabase::connectOrNull();
        if ($pdo === null) {
            $this->markTestSkipped('Crie o .env a partir do .env.example para rodar a integração.');
        }
        $this->pdo = $pdo;
        TestDatabase::reset($this->pdo);
        (new Migrator($this->pdo, __DIR__ . '/../../database/migrations'))->run();

        $this->pdo->exec(
            "INSERT INTO usuarios (nome, email, senha_hash, perfil) VALUES ('A', 'a@x.com', 'h', 'admin')"
        );
        $this->usuarioId = (int) $this->pdo->lastInsertId();
        $this->empresaId = $this->insertEmpresa([]);
    }

    /** @param array<string, mixed> $overrides */
    private function insertEmpresa(array $overrides): int
    {
        $this->insert('empresas', $overrides + [
            'razao_social' => 'Empresa Teste',
            'cnpj' => '11222333000181',
            'regime' => 'simples',
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @param array<string, mixed> $overrides */
    private function insertObrigacao(array $overrides): void
    {
        $this->insert('obrigacoes', $overrides + [
            'empresa_id' => $this->empresaId,
            'tipo' => 'DAS',
            'competencia' => '2026-10-01',
            'vencimento' => '2026-11-20',
            'valor' => '10.00',
            'status' => 'pendente',
            'criado_por' => $this->usuarioId,
        ]);
    }

    /** @param array<string, mixed> $row */
    private function insert(string $table, array $row): void
    {
        $cols = implode(',', array_keys($row));
        $marks = implode(',', array_fill(0, count($row), '?'));
        $this->pdo->prepare("INSERT INTO {$table} ({$cols}) VALUES ({$marks})")->execute(array_values($row));
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function invalidObrigacoes(): array
    {
        return [
            'valor negativo' => [['valor' => '-1.00']],
            'competencia fora do dia 01' => [['competencia' => '2026-10-15']],
            'paga sem data de pagamento' => [['status' => 'paga']],
            'data de pagamento sem status paga' => [['paga_em' => '2026-11-01 10:00:00']],
            'OUTRA sem descricao' => [['tipo' => 'OUTRA']],
        ];
    }

    /** @param array<string, mixed> $overrides */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidObrigacoes')]
    public function testRejectsInvalidObrigacao(array $overrides): void
    {
        $this->expectException(PDOException::class);

        $this->insertObrigacao($overrides);
    }

    public function testAcceptsValidObrigacao(): void
    {
        $this->insertObrigacao([]);
        $this->insertObrigacao(['tipo' => 'OUTRA', 'descricao' => 'Parcelamento']);
        $this->insertObrigacao(['status' => 'paga', 'paga_em' => '2026-11-01 10:00:00', 'tipo' => 'DARF']);

        $count = $this->pdo->query('SELECT COUNT(*) FROM obrigacoes')->fetchColumn();
        $this->assertSame(3, (int) $count);
    }

    public function testRejectsDuplicateActiveObrigacao(): void
    {
        $this->insertObrigacao([]);

        $this->expectException(PDOException::class);
        $this->insertObrigacao([]);
    }

    public function testAllowsDuplicateWhenCancelledOrOutra(): void
    {
        $this->insertObrigacao(['status' => 'cancelada']);
        $this->insertObrigacao([]);
        $this->insertObrigacao(['tipo' => 'OUTRA', 'descricao' => 'x']);
        $this->insertObrigacao(['tipo' => 'OUTRA', 'descricao' => 'y']);

        $count = $this->pdo->query('SELECT COUNT(*) FROM obrigacoes')->fetchColumn();
        $this->assertSame(4, (int) $count);
    }

    public function testRejectsDuplicateCnpj(): void
    {
        $this->expectException(PDOException::class);

        $this->insertEmpresa([]);
    }

    public function testRejectsMalformedCnpj(): void
    {
        $this->expectException(PDOException::class);

        $this->insertEmpresa(['cnpj' => '1122233300018']);
    }

    public function testRejectsWhatsappWithoutCountryCode(): void
    {
        $this->expectException(PDOException::class);

        $this->insertEmpresa(['cnpj' => '11444777000161', 'whatsapp' => '11999999999']);
    }

    public function testRejectsDuplicateUserEmail(): void
    {
        $this->expectException(PDOException::class);

        $this->pdo->exec(
            "INSERT INTO usuarios (nome, email, senha_hash, perfil) VALUES ('B', 'a@x.com', 'h', 'contador')"
        );
    }

    public function testRejectsDeletingEmpresaWithObrigacoes(): void
    {
        $this->insertObrigacao([]);

        $this->expectException(PDOException::class);
        $this->pdo->exec('DELETE FROM empresas WHERE id = ' . $this->empresaId);
    }

    public function testRejectsAvaliacaoOutOfRange(): void
    {
        $this->expectException(PDOException::class);

        $this->insert('avaliacoes', [
            'perfil' => 'admin', 'q1' => 6, 'q2' => 5, 'q3' => 5, 'q4' => 5, 'q5' => 5, 'nps' => 10,
        ]);
    }

    public function testRejectsNpsAboveTen(): void
    {
        $this->expectException(PDOException::class);

        $this->insert('avaliacoes', [
            'perfil' => 'admin', 'q1' => 5, 'q2' => 5, 'q3' => 5, 'q4' => 5, 'q5' => 5, 'nps' => 11,
        ]);
    }
}

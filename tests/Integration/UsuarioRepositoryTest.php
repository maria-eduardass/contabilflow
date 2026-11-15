<?php

declare(strict_types=1);

namespace ContabilFlow\Tests\Integration;

use ContabilFlow\Core\LazyPdo;
use ContabilFlow\Core\Migrator;
use ContabilFlow\Models\UsuarioRepository;
use ContabilFlow\Tests\Support\TestDatabase;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;

final class UsuarioRepositoryTest extends TestCase
{
    private PDO $pdo;
    private UsuarioRepository $repo;
    private int $id;

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
            "INSERT INTO usuarios (nome, email, senha_hash, perfil) VALUES ('Ana', 'ana@escritorio.com', 'hash', 'admin')"
        );
        $this->id = (int) $this->pdo->lastInsertId();
        $this->repo = new UsuarioRepository(new LazyPdo(fn(): PDO => $pdo));
    }

    /** @return array<string, mixed> */
    private function row(): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM usuarios WHERE id = ?');
        $stmt->execute([$this->id]);

        return (array) $stmt->fetch();
    }

    public function testFindsUserByEmail(): void
    {
        $user = $this->repo->findByEmail('ana@escritorio.com');

        $this->assertNotNull($user);
        $this->assertSame($this->id, $user->id);
        $this->assertSame('admin', $user->perfil);
        $this->assertTrue($user->ativo);
        $this->assertSame(0, $user->tentativasFalhas);
        $this->assertNull($user->bloqueadoAte);
    }

    public function testReturnsNullForUnknownEmail(): void
    {
        $this->assertNull($this->repo->findByEmail('x@y.com'));
    }

    public function testSqlInjectionAttemptIsTreatedAsPlainValue(): void
    {
        $this->assertNull($this->repo->findByEmail("' OR '1'='1"));
    }

    public function testRegisterFailureIncrementsWithoutLockingBelowMax(): void
    {
        $locked = $this->repo->registerFailure($this->id, 5, new DateTimeImmutable('2026-11-14 10:15:00'));

        $this->assertFalse($locked);
        $this->assertSame(1, (int) $this->row()['tentativas_falhas']);
        $this->assertNull($this->row()['bloqueado_ate']);
    }

    public function testRegisterFailureLocksAtMaxAndResetsCounter(): void
    {
        $until = new DateTimeImmutable('2026-11-14 10:15:00');
        for ($i = 1; $i < 5; $i++) {
            $this->assertFalse($this->repo->registerFailure($this->id, 5, $until));
        }

        $this->assertTrue($this->repo->registerFailure($this->id, 5, $until));

        $this->assertSame('2026-11-14 10:15:00', $this->row()['bloqueado_ate']);
        $this->assertSame(0, (int) $this->row()['tentativas_falhas']);
        $this->assertEquals($until, $this->repo->findByEmail('ana@escritorio.com')?->bloqueadoAte);
    }

    public function testResetFailuresClearsCounterAndLock(): void
    {
        $this->repo->registerFailure($this->id, 1, new DateTimeImmutable('2026-11-14 10:15:00'));

        $this->repo->resetFailures($this->id);

        $this->assertSame(0, (int) $this->row()['tentativas_falhas']);
        $this->assertNull($this->row()['bloqueado_ate']);
    }

    public function testListActiveReturnsOnlyActiveUsersByName(): void
    {
        $this->pdo->exec(
            "INSERT INTO usuarios (nome, email, senha_hash, perfil, ativo) VALUES "
            . "('Bia', 'bia@x.com', 'h', 'contador', 1), ('Zed', 'zed@x.com', 'h', 'contador', 0)"
        );

        $this->assertSame(
            [['id' => $this->id, 'nome' => 'Ana'], ['id' => $this->id + 1, 'nome' => 'Bia']],
            $this->repo->listActive()
        );
    }

    public function testUpdatePasswordHash(): void
    {
        $this->repo->updatePasswordHash($this->id, 'novo-hash');

        $this->assertSame('novo-hash', $this->row()['senha_hash']);
    }
}

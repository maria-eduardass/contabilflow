<?php

declare(strict_types=1);

namespace ContabilFlow\Tests\Integration;

use ContabilFlow\Core\LazyPdo;
use ContabilFlow\Core\Migrator;
use ContabilFlow\Models\DuplicateException;
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

    public function testFindByIdReturnsUserWithCreationDate(): void
    {
        $user = $this->repo->find($this->id);

        $this->assertNotNull($user);
        $this->assertSame('Ana', $user->nome);
        $this->assertSame('ana@escritorio.com', $user->email);
        $this->assertNotNull($user->criadoEm);
        $this->assertNull($this->repo->find($this->id + 100));
    }

    public function testCreateStoresUserActiveWithHashAndProfile(): void
    {
        $id = $this->repo->create('Carla', 'carla@x.com', password_hash('segredo-123456', PASSWORD_DEFAULT), 'contador');

        $user = $this->repo->find($id);
        $this->assertNotNull($user);
        $this->assertSame('Carla', $user->nome);
        $this->assertSame('contador', $user->perfil);
        $this->assertTrue($user->ativo);
        $this->assertSame(0, $user->tentativasFalhas);
        $this->assertTrue(password_verify('segredo-123456', $user->senhaHash));
        $this->assertSame($id, $this->repo->findByEmail('carla@x.com')?->id);
    }

    public function testCreateWithDuplicateEmailThrowsDuplicateOnEmailField(): void
    {
        try {
            $this->repo->create('Outra Ana', 'ana@escritorio.com', 'h', 'contador');
            $this->fail('Deveria lançar DuplicateException');
        } catch (DuplicateException $e) {
            $this->assertSame('email', $e->field);
        }
        $this->assertCount(1, $this->repo->listPage(1)->items);
    }

    public function testCreateRejectsAnUnknownProfileAtTheDatabaseLevel(): void
    {
        $this->expectException(\PDOException::class);
        $this->repo->create('Cliente', 'cliente@x.com', 'h', 'cliente');
    }

    public function testSetActiveTogglesAndReportsExistence(): void
    {
        $this->assertTrue($this->repo->setActive($this->id, false));
        $this->assertFalse($this->repo->find($this->id)?->ativo);

        $this->assertTrue($this->repo->setActive($this->id, false)); // já estava inativo: continua "existe"
        $this->assertTrue($this->repo->setActive($this->id, true));
        $this->assertTrue($this->repo->find($this->id)?->ativo);
        $this->assertFalse($this->repo->setActive($this->id + 100, true));
    }

    public function testListPageOrdersActiveFirstThenByNameAndPaginates(): void
    {
        $this->repo->create('Zeca', 'zeca@x.com', 'h', 'contador');
        $inativo = $this->repo->create('Abel', 'abel@x.com', 'h', 'contador');
        $this->repo->setActive($inativo, false);

        $names = array_map(static fn($u): string => $u->nome, $this->repo->listPage(1)->items);

        $this->assertSame(['Ana', 'Zeca', 'Abel'], $names);
        $this->assertSame(3, $this->repo->listPage(1)->total);
    }

    public function testListPagePaginatesAtTwentyPerPage(): void
    {
        for ($i = 1; $i <= 24; $i++) {
            $this->repo->create(sprintf('Usuario %02d', $i), "u{$i}@x.com", 'h', 'contador');
        }

        $first = $this->repo->listPage(1);
        $second = $this->repo->listPage(2);

        $this->assertSame(25, $first->total);
        $this->assertCount(20, $first->items);
        $this->assertCount(5, $second->items);
        $this->assertSame(2, $first->pages());
        $this->assertSame([], $this->repo->listPage(9)->items);
        $this->assertCount(20, $this->repo->listPage(0)->items);
    }

    public function testUpdatePasswordHash(): void
    {
        $this->repo->updatePasswordHash($this->id, 'novo-hash');

        $this->assertSame('novo-hash', $this->row()['senha_hash']);
    }
}

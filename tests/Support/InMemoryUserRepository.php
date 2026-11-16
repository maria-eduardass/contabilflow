<?php

declare(strict_types=1);

namespace ContabilFlow\Tests\Support;

use ContabilFlow\Models\DuplicateException;
use ContabilFlow\Models\Page;
use ContabilFlow\Models\UserRepositoryInterface;
use ContabilFlow\Models\Usuario;
use DateTimeImmutable;

/** Repositório falso com a mesma regra de bloqueio do SQL real. */
final class InMemoryUserRepository implements UserRepositoryInterface
{
    /** @var array<int, array{nome: string, email: string, hash: string, perfil: string, ativo: bool, falhas: int, ate: ?DateTimeImmutable}> */
    public array $rows = [];

    /** @var list<string> */
    public array $writes = [];

    public function add(
        int $id,
        string $email,
        string $hash,
        string $perfil = 'contador',
        bool $ativo = true,
        int $falhas = 0,
        ?DateTimeImmutable $ate = null,
    ): void {
        $this->rows[$id] = [
            'nome' => 'Usuário ' . $id, 'email' => $email, 'hash' => $hash,
            'perfil' => $perfil, 'ativo' => $ativo, 'falhas' => $falhas, 'ate' => $ate,
        ];
    }

    public function findByEmail(string $email): ?Usuario
    {
        foreach ($this->rows as $id => $r) {
            if ($r['email'] === $email) {
                return new Usuario($id, $r['nome'], $r['email'], $r['hash'], $r['perfil'], $r['ativo'], $r['falhas'], $r['ate']);
            }
        }

        return null;
    }

    public function registerFailure(int $userId, int $maxAttempts, DateTimeImmutable $lockedUntil): bool
    {
        $this->writes[] = 'failure:' . $userId;
        $this->rows[$userId]['falhas']++;
        if ($this->rows[$userId]['falhas'] >= $maxAttempts) {
            $this->rows[$userId]['falhas'] = 0;
            $this->rows[$userId]['ate'] = $lockedUntil;

            return true;
        }

        return false;
    }

    public function resetFailures(int $userId): void
    {
        $this->writes[] = 'reset:' . $userId;
        $this->rows[$userId]['falhas'] = 0;
        $this->rows[$userId]['ate'] = null;
    }

    public function find(int $id): ?Usuario
    {
        $r = $this->rows[$id] ?? null;

        return $r === null ? null : $this->toUsuario($id, $r);
    }

    public function listPage(int $page): Page
    {
        $all = [];
        foreach ($this->rows as $id => $r) {
            $all[] = $this->toUsuario($id, $r);
        }
        $page = max(1, $page);

        return new Page(array_slice($all, ($page - 1) * 20, 20), count($all), $page, 20);
    }

    public function create(string $nome, string $email, string $senhaHash, string $perfil): int
    {
        foreach ($this->rows as $r) {
            if ($r['email'] === $email) {
                throw new DuplicateException('email');
            }
        }
        $id = ($this->rows === [] ? 0 : max(array_keys($this->rows))) + 1;
        $this->add($id, $email, $senhaHash, $perfil);
        $this->rows[$id]['nome'] = $nome;
        $this->writes[] = 'create:' . $id;

        return $id;
    }

    public function setActive(int $id, bool $ativo): bool
    {
        if (!isset($this->rows[$id])) {
            return false;
        }
        $this->rows[$id]['ativo'] = $ativo;
        $this->writes[] = ($ativo ? 'activate:' : 'deactivate:') . $id;

        return true;
    }

    /** @param array{nome: string, email: string, hash: string, perfil: string, ativo: bool, falhas: int, ate: ?DateTimeImmutable} $r */
    private function toUsuario(int $id, array $r): Usuario
    {
        return new Usuario($id, $r['nome'], $r['email'], $r['hash'], $r['perfil'], $r['ativo'], $r['falhas'], $r['ate']);
    }

    public function listActive(): array
    {
        $users = [];
        foreach ($this->rows as $id => $r) {
            if ($r['ativo']) {
                $users[] = ['id' => $id, 'nome' => $r['nome']];
            }
        }

        return $users;
    }

    public function updatePasswordHash(int $userId, string $hash): void
    {
        $this->writes[] = 'rehash:' . $userId;
        $this->rows[$userId]['hash'] = $hash;
    }
}

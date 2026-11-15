<?php

declare(strict_types=1);

namespace ContabilFlow\Models;

use ContabilFlow\Core\LazyPdo;
use DateTimeImmutable;

/** Todo o SQL de usuários, sempre com prepared statements. */
final class UsuarioRepository implements UserRepositoryInterface
{
    private const DATETIME = 'Y-m-d H:i:s';

    public function __construct(private LazyPdo $db)
    {
    }

    public function findByEmail(string $email): ?Usuario
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, nome, email, senha_hash, perfil, ativo, tentativas_falhas, bloqueado_ate '
            . 'FROM usuarios WHERE email = ? LIMIT 1'
        );
        $stmt->execute([$email]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return null;
        }

        return new Usuario(
            (int) $row['id'],
            (string) $row['nome'],
            (string) $row['email'],
            (string) $row['senha_hash'],
            (string) $row['perfil'],
            (bool) $row['ativo'],
            (int) $row['tentativas_falhas'],
            $row['bloqueado_ate'] === null ? null : new DateTimeImmutable((string) $row['bloqueado_ate'])
        );
    }

    /**
     * Por quê: duas instruções atômicas em vez de ler-e-gravar em PHP. O
     * incremento no SQL não perde tentativas simultâneas, e o bloqueio só é
     * aplicado se o contador realmente atingiu o limite.
     */
    public function registerFailure(int $userId, int $maxAttempts, DateTimeImmutable $lockedUntil): bool
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('UPDATE usuarios SET tentativas_falhas = tentativas_falhas + 1 WHERE id = ?')
            ->execute([$userId]);

        $lock = $pdo->prepare(
            'UPDATE usuarios SET bloqueado_ate = ?, tentativas_falhas = 0 '
            . 'WHERE id = ? AND tentativas_falhas >= ?'
        );
        $lock->execute([$lockedUntil->format(self::DATETIME), $userId, $maxAttempts]);

        return $lock->rowCount() === 1;
    }

    public function resetFailures(int $userId): void
    {
        $this->db->pdo()
            ->prepare('UPDATE usuarios SET tentativas_falhas = 0, bloqueado_ate = NULL WHERE id = ?')
            ->execute([$userId]);
    }

    public function listActive(): array
    {
        $rows = $this->db->pdo()->query('SELECT id, nome FROM usuarios WHERE ativo = 1 ORDER BY nome, id');
        $users = [];
        foreach ($rows === false ? [] : $rows->fetchAll() as $row) {
            $users[] = ['id' => (int) $row['id'], 'nome' => (string) $row['nome']];
        }

        return $users;
    }

    public function updatePasswordHash(int $userId, string $hash): void
    {
        $this->db->pdo()
            ->prepare('UPDATE usuarios SET senha_hash = ? WHERE id = ?')
            ->execute([$hash, $userId]);
    }
}

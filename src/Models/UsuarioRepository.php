<?php

declare(strict_types=1);

namespace ContabilFlow\Models;

use ContabilFlow\Core\LazyPdo;
use DateTimeImmutable;
use PDO;
use PDOException;

/** Todo o SQL de usuários, sempre com prepared statements. */
final class UsuarioRepository implements UserRepositoryInterface
{
    public const PER_PAGE = 20;
    private const DATETIME = 'Y-m-d H:i:s';
    private const COLUMNS = 'id, nome, email, senha_hash, perfil, ativo, tentativas_falhas, bloqueado_ate, criado_em';

    public function __construct(private LazyPdo $db)
    {
    }

    public function findByEmail(string $email): ?Usuario
    {
        $stmt = $this->db->pdo()->prepare('SELECT ' . self::COLUMNS . ' FROM usuarios WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $row = $stmt->fetch();

        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function find(int $id): ?Usuario
    {
        $stmt = $this->db->pdo()->prepare('SELECT ' . self::COLUMNS . ' FROM usuarios WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function listPage(int $page): Page
    {
        $pdo = $this->db->pdo();
        $count = $pdo->query('SELECT COUNT(*) FROM usuarios');
        $total = $count === false ? 0 : (int) $count->fetchColumn();

        $page = max(1, $page);
        $stmt = $pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM usuarios ORDER BY ativo DESC, nome, id LIMIT ? OFFSET ?'
        );
        $stmt->bindValue(1, self::PER_PAGE, PDO::PARAM_INT);
        $stmt->bindValue(2, ($page - 1) * self::PER_PAGE, PDO::PARAM_INT);
        $stmt->execute();

        $items = [];
        foreach ($stmt->fetchAll() as $row) {
            $items[] = $this->hydrate((array) $row);
        }

        return new Page($items, $total, $page, self::PER_PAGE);
    }

    /**
     * Por quê: a unicidade do e-mail é garantida pelo UNIQUE do banco; um
     * SELECT antes do INSERT abriria condição de corrida.
     */
    public function create(string $nome, string $email, string $senhaHash, string $perfil): int
    {
        $pdo = $this->db->pdo();
        try {
            $pdo->prepare('INSERT INTO usuarios (nome, email, senha_hash, perfil) VALUES (?, ?, ?, ?)')
                ->execute([$nome, $email, $senhaHash, $perfil]);
        } catch (PDOException $e) {
            throw (int) ($e->errorInfo[1] ?? 0) === 1062 ? new DuplicateException('email', $e) : $e;
        }

        return (int) $pdo->lastInsertId();
    }

    public function setActive(int $id, bool $ativo): bool
    {
        $stmt = $this->db->pdo()->prepare('UPDATE usuarios SET ativo = ? WHERE id = ?');
        $stmt->execute([(int) $ativo, $id]);

        // rowCount() é 0 quando o valor já era o pedido; a existência é confirmada à parte.
        return $stmt->rowCount() === 1 || $this->find($id) !== null;
    }

    /** @param array<array-key, mixed> $row */
    private function hydrate(array $row): Usuario
    {
        return new Usuario(
            (int) $row['id'],
            (string) $row['nome'],
            (string) $row['email'],
            (string) $row['senha_hash'],
            (string) $row['perfil'],
            (bool) $row['ativo'],
            (int) $row['tentativas_falhas'],
            $row['bloqueado_ate'] === null ? null : new DateTimeImmutable((string) $row['bloqueado_ate']),
            $row['criado_em'] === null ? null : new DateTimeImmutable((string) $row['criado_em'])
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

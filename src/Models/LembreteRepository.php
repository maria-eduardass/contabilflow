<?php

declare(strict_types=1);

namespace ContabilFlow\Models;

use ContabilFlow\Core\LazyPdo;
use DateTimeImmutable;
use PDO;

/** Todo o SQL do histórico de lembretes, sempre com prepared statements. */
final class LembreteRepository implements LembreteRepositoryInterface
{
    public function __construct(private LazyPdo $db)
    {
    }

    public function create(
        int $obrigacaoId,
        int $usuarioId,
        LembreteCanal $canal,
        string $mensagem,
        DateTimeImmutable $agora,
    ): int {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO lembretes (obrigacao_id, usuario_id, canal, mensagem, criado_em) VALUES (?, ?, ?, ?, ?)'
        )->execute([$obrigacaoId, $usuarioId, $canal->value, $mensagem, $agora->format('Y-m-d H:i:s')]);

        return (int) $pdo->lastInsertId();
    }

    public function listByObrigacao(int $obrigacaoId, int $limit = 20): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT l.id, l.obrigacao_id, l.usuario_id, u.nome AS usuario_nome, l.canal, l.mensagem, l.criado_em '
            . 'FROM lembretes l JOIN usuarios u ON u.id = l.usuario_id WHERE l.obrigacao_id = ? '
            . 'ORDER BY l.criado_em DESC, l.id DESC LIMIT ?'
        );
        $stmt->bindValue(1, $obrigacaoId, PDO::PARAM_INT);
        $stmt->bindValue(2, max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();

        $items = [];
        foreach ($stmt->fetchAll() as $row) {
            $items[] = new Lembrete(
                (int) $row['id'],
                (int) $row['obrigacao_id'],
                (int) $row['usuario_id'],
                (string) $row['usuario_nome'],
                LembreteCanal::from((string) $row['canal']),
                (string) $row['mensagem'],
                new DateTimeImmutable((string) $row['criado_em'])
            );
        }

        return $items;
    }
}

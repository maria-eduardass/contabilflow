<?php

declare(strict_types=1);

namespace ContabilFlow\Models;

use ContabilFlow\Core\LazyPdo;
use DateTimeImmutable;
use PDO;

/**
 * Todo o SQL das avaliações, sempre com prepared statements.
 *
 * Por quê: o resumo sai de uma única consulta agregada por perfil (SUM das
 * notas e contagem de promotores, neutros e detratores); a média geral é
 * recomposta em PHP a partir das somas, com o peso certo de cada perfil. A
 * avaliação guarda o perfil de QUEM respondeu no momento (não muda se o
 * usuário for promovido) e o autor vira NULL se o usuário for excluído.
 */
final class AvaliacaoRepository implements AvaliacaoRepositoryInterface
{
    public function __construct(private LazyPdo $db)
    {
    }

    public function create(int $usuarioId, string $perfil, AvaliacaoDados $dados, DateTimeImmutable $agora): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO avaliacoes (usuario_id, perfil, q1, q2, q3, q4, q5, nps, comentario, criado_em) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $usuarioId, $perfil, $dados->q1, $dados->q2, $dados->q3, $dados->q4, $dados->q5,
            $dados->nps, $dados->comentario, $agora->format('Y-m-d H:i:s'),
        ]);

        return (int) $pdo->lastInsertId();
    }

    public function resumo(): ResumoAvaliacoes
    {
        $pdo = $this->db->pdo();
        $rows = $pdo->query(
            'SELECT perfil, COUNT(*) AS total, SUM(q1) AS q1, SUM(q2) AS q2, SUM(q3) AS q3, SUM(q4) AS q4, '
            . 'SUM(q5) AS q5, SUM(nps >= 9) AS promotores, SUM(nps BETWEEN 7 AND 8) AS neutros, '
            . 'SUM(nps <= 6) AS detratores FROM avaliacoes GROUP BY perfil ORDER BY perfil'
        );

        $porPerfil = [];
        foreach ($rows === false ? [] : $rows->fetchAll() as $row) {
            $porPerfil[(string) $row['perfil']] = EstatisticasAvaliacao::fromSums(
                (int) $row['total'],
                ['q1' => (int) $row['q1'], 'q2' => (int) $row['q2'], 'q3' => (int) $row['q3'],
                    'q4' => (int) $row['q4'], 'q5' => (int) $row['q5']],
                (int) $row['promotores'],
                (int) $row['neutros'],
                (int) $row['detratores']
            );
        }

        return new ResumoAvaliacoes(
            EstatisticasAvaliacao::combine(array_values($porPerfil)),
            $porPerfil,
            $this->comentarios()
        );
    }

    /** @return list<ComentarioAvaliacao> */
    private function comentarios(): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT a.perfil, u.nome AS autor, a.nps, a.comentario, a.criado_em FROM avaliacoes a '
            . "LEFT JOIN usuarios u ON u.id = a.usuario_id WHERE a.comentario IS NOT NULL AND a.comentario <> '' "
            . 'ORDER BY a.criado_em DESC, a.id DESC LIMIT ?'
        );
        $stmt->bindValue(1, Avaliacao::MAX_COMENTARIOS_NO_RESUMO, PDO::PARAM_INT);
        $stmt->execute();

        $items = [];
        foreach ($stmt->fetchAll() as $row) {
            $items[] = new ComentarioAvaliacao(
                (string) $row['perfil'],
                $row['autor'] === null ? null : (string) $row['autor'],
                (int) $row['nps'],
                (string) $row['comentario'],
                new DateTimeImmutable((string) $row['criado_em'])
            );
        }

        return $items;
    }
}

<?php

declare(strict_types=1);

namespace ContabilFlow\Models;

use ContabilFlow\Core\LazyPdo;
use ContabilFlow\Core\Sql;
use DateTimeImmutable;
use InvalidArgumentException;
use PDO;
use PDOException;
use Throwable;

/**
 * Todo o SQL de guias (obrigações), sempre com prepared statements.
 *
 * Por quê:
 * - Duplicidade é garantida pela coluna gerada dedup_key (UNIQUE): sob
 *   requisições simultâneas só uma passa e a outra vira DuplicateException.
 * - Toda mudança usa lock otimista (WHERE versao = ?) e, nas transições de
 *   status, também WHERE status IN (origens válidas). Assim a regra vale de
 *   forma atômica, sem ler antes e gravar depois.
 * - Quando o UPDATE não altera nenhuma linha, a releitura diz o motivo, nesta
 *   ordem: sumiu (404), versão mudou (409) ou estado não permite (422).
 */
final class ObrigacaoRepository implements ObrigacaoRepositoryInterface
{
    private const ER_DUP_ENTRY = 1062;
    private const DATE = 'Y-m-d';
    private const DATETIME = 'Y-m-d H:i:s';
    private const SELECT = 'SELECT o.id, o.empresa_id, e.razao_social AS empresa_nome, o.tipo, o.descricao, '
        . 'o.competencia, o.vencimento, o.valor, o.status, o.enviada_em, o.paga_em, o.observacoes, '
        . 'o.criado_por, o.versao FROM obrigacoes o JOIN empresas e ON e.id = o.empresa_id';
    private const SORT_COLUMNS = [
        'vencimento' => 'o.vencimento', 'competencia' => 'o.competencia', 'valor' => 'o.valor',
        'tipo' => 'o.tipo', 'status' => 'o.status', 'criado_em' => 'o.criado_em',
    ];
    private const ACTIVE = "o.status IN ('pendente', 'enviada')";

    public function __construct(private LazyPdo $db)
    {
    }

    public function find(int $id): ?Obrigacao
    {
        $stmt = $this->db->pdo()->prepare(self::SELECT . ' WHERE o.id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function search(ObrigacaoFiltro $filtro): Page
    {
        [$where, $params] = $this->conditions($filtro);
        $order = $this->orderBy($filtro->sort);
        $pdo = $this->db->pdo();

        $count = $pdo->prepare('SELECT COUNT(*) FROM obrigacoes o JOIN empresas e ON e.id = o.empresa_id' . $where);
        $count->execute($params);
        $total = (int) $count->fetchColumn();

        $page = max(1, $filtro->page);
        $stmt = $pdo->prepare(self::SELECT . $where . ' ORDER BY ' . $order . ' LIMIT ? OFFSET ?');
        foreach ($params as $i => $value) {
            $stmt->bindValue($i + 1, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue(count($params) + 1, ObrigacaoFiltro::PER_PAGE, PDO::PARAM_INT);
        $stmt->bindValue(count($params) + 2, ($page - 1) * ObrigacaoFiltro::PER_PAGE, PDO::PARAM_INT);
        $stmt->execute();

        $items = [];
        foreach ($stmt->fetchAll() as $row) {
            $items[] = $this->hydrate((array) $row);
        }

        return new Page($items, $total, $page, ObrigacaoFiltro::PER_PAGE);
    }

    public function create(ObrigacaoDados $dados, int $criadoPor): int
    {
        $pdo = $this->db->pdo();
        try {
            $pdo->prepare(
                'INSERT INTO obrigacoes (empresa_id, tipo, descricao, competencia, vencimento, valor, '
                . 'observacoes, criado_por) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $dados->empresaId, $dados->tipo, $dados->descricao, $this->firstOfMonth($dados),
                $dados->vencimento->format(self::DATE), $dados->valor, $dados->observacoes, $criadoPor,
            ]);
        } catch (PDOException $e) {
            throw $this->translate($e);
        }

        return (int) $pdo->lastInsertId();
    }

    public function update(int $id, int $versao, ObrigacaoDados $dados): void
    {
        try {
            $stmt = $this->db->pdo()->prepare(
                'UPDATE obrigacoes SET empresa_id = ?, tipo = ?, descricao = ?, competencia = ?, vencimento = ?, '
                . "valor = ?, observacoes = ?, versao = versao + 1 WHERE id = ? AND versao = ? AND status <> 'paga'"
            );
            $stmt->execute([
                $dados->empresaId, $dados->tipo, $dados->descricao, $this->firstOfMonth($dados),
                $dados->vencimento->format(self::DATE), $dados->valor, $dados->observacoes, $id, $versao,
            ]);
        } catch (PDOException $e) {
            throw $this->translate($e);
        }

        if ($stmt->rowCount() !== 1) {
            $current = $this->find($id);
            if ($current !== null && $current->status === ObrigacaoStatus::Paga) {
                throw new InvalidStateException('Guia já paga não pode ser editada.');
            }
            throw $this->whyNotApplied($current, $versao, null);
        }
    }

    public function changeStatus(int $id, int $versao, ObrigacaoStatus $to, DateTimeImmutable $agora): void
    {
        $sources = array_map(static fn(ObrigacaoStatus $s): string => $s->value, ObrigacaoStatus::sourcesFor($to));
        $set = ['status = ?', 'versao = versao + 1'];
        $params = [$to->value];
        if ($to === ObrigacaoStatus::Enviada) {
            $set[] = 'enviada_em = ?';
            $params[] = $agora->format(self::DATETIME);
        } elseif ($to === ObrigacaoStatus::Paga) {
            $set[] = 'paga_em = ?';
            $params[] = $agora->format(self::DATETIME);
        } elseif ($to === ObrigacaoStatus::Pendente) {
            $set[] = 'enviada_em = NULL';
        }

        try {
            $stmt = $this->db->pdo()->prepare(
                'UPDATE obrigacoes SET ' . implode(', ', $set) . ' WHERE id = ? AND versao = ? AND status IN ('
                . implode(', ', array_fill(0, count($sources), '?')) . ')'
            );
            $stmt->execute([...$params, $id, $versao, ...$sources]);
        } catch (PDOException $e) {
            throw $this->translate($e);
        }

        if ($stmt->rowCount() !== 1) {
            throw $this->whyNotApplied($this->find($id), $versao, $to);
        }
    }

    public function delete(int $id): bool
    {
        $stmt = $this->db->pdo()->prepare("DELETE FROM obrigacoes WHERE id = ? AND status <> 'paga'");
        $stmt->execute([$id]);
        if ($stmt->rowCount() === 1) {
            return true;
        }

        if ($this->find($id) === null) {
            return false;
        }

        throw new ConflictException('Guia paga não pode ser excluída.');
    }

    /** Por quê: a competência é sempre o dia 01 (CHECK do banco), então normalizamos aqui. */
    private function firstOfMonth(ObrigacaoDados $dados): string
    {
        return $dados->competencia->format('Y-m-01');
    }

    /** Explica por que o UPDATE não alterou linha alguma. */
    private function whyNotApplied(?Obrigacao $current, int $versao, ?ObrigacaoStatus $to): Throwable
    {
        if ($current === null) {
            return new RecordNotFoundException('Guia não encontrada.');
        }
        if ($current->versao !== $versao) {
            return new StaleVersionException('Guia alterada por outra pessoa. Recarregue e tente de novo.');
        }

        return new InvalidStateException(
            $to === null
                ? 'Esta guia não pode ser alterada.'
                : "Transição inválida: {$current->status->value} → {$to->value}."
        );
    }

    /** @return array{0: string, 1: list<int|string>} */
    private function conditions(ObrigacaoFiltro $filtro): array
    {
        $where = [];
        $params = [];

        $q = trim((string) $filtro->q);
        if ($q !== '') {
            $like = '%' . Sql::escapeLike($q) . '%';
            $where[] = "(o.descricao LIKE ? ESCAPE '!' OR e.razao_social LIKE ? ESCAPE '!' "
                . "OR e.nome_fantasia LIKE ? ESCAPE '!')";
            array_push($params, $like, $like, $like);
        }
        if ($filtro->empresaId !== null) {
            $where[] = 'o.empresa_id = ?';
            $params[] = $filtro->empresaId;
        }
        if ($filtro->tipo !== null) {
            $where[] = 'o.tipo = ?';
            $params[] = $filtro->tipo;
        }
        if ($filtro->status !== null) {
            $where[] = 'o.status = ?';
            $params[] = $filtro->status->value;
        }
        if ($filtro->de !== null) {
            $where[] = 'o.vencimento >= ?';
            $params[] = $filtro->de->format(self::DATE);
        }
        if ($filtro->ate !== null) {
            $where[] = 'o.vencimento <= ?';
            $params[] = $filtro->ate->format(self::DATE);
        }
        if ($filtro->faixa !== null) {
            [$sql, $rangeParams] = $this->rangeCondition($filtro->faixa);
            $where[] = $sql;
            array_push($params, ...$rangeParams);
        }

        return [$where === [] ? '' : ' WHERE ' . implode(' AND ', $where), $params];
    }

    /**
     * Faixa do semáforo (calculada pelo SemaforoService): guias em aberto com vencimento
     * dentro do intervalo, ou guias encerradas (pagas ou canceladas) quando não está em aberto.
     *
     * @return array{0: string, 1: list<string>}
     */
    private function rangeCondition(FaixaVencimento $faixa): array
    {
        if (!$faixa->emAberto) {
            return ["o.status IN ('paga', 'cancelada')", []];
        }

        $sql = [self::ACTIVE];
        $params = [];
        if ($faixa->de !== null) {
            $sql[] = 'o.vencimento >= ?';
            $params[] = $faixa->de->format(self::DATE);
        }
        if ($faixa->ate !== null) {
            $sql[] = 'o.vencimento <= ?';
            $params[] = $faixa->ate->format(self::DATE);
        }

        return [implode(' AND ', $sql), $params];
    }

    /** Por quê: ORDER BY não aceita parâmetro, então só colunas da lista fixa entram no SQL. */
    private function orderBy(string $sort): string
    {
        $key = ltrim($sort, '-');
        if (!isset(self::SORT_COLUMNS[$key])) {
            throw new InvalidArgumentException("Ordenação inválida: {$sort}");
        }

        return self::SORT_COLUMNS[$key] . (str_starts_with($sort, '-') ? ' DESC' : ' ASC') . ', o.id ASC';
    }

    private function translate(PDOException $e): Throwable
    {
        return (int) ($e->errorInfo[1] ?? 0) === self::ER_DUP_ENTRY
            ? new DuplicateException('competencia', $e)
            : $e;
    }

    /** @param array<array-key, mixed> $row */
    private function hydrate(array $row): Obrigacao
    {
        $date = static fn(mixed $v): ?DateTimeImmutable => $v === null ? null : new DateTimeImmutable((string) $v);

        return new Obrigacao(
            (int) $row['id'],
            (int) $row['empresa_id'],
            (string) $row['empresa_nome'],
            (string) $row['tipo'],
            $row['descricao'] === null ? null : (string) $row['descricao'],
            new DateTimeImmutable((string) $row['competencia']),
            new DateTimeImmutable((string) $row['vencimento']),
            $row['valor'] === null ? null : (string) $row['valor'],
            ObrigacaoStatus::from((string) $row['status']),
            $date($row['enviada_em']),
            $date($row['paga_em']),
            $row['observacoes'] === null ? null : (string) $row['observacoes'],
            (int) $row['criado_por'],
            (int) $row['versao']
        );
    }
}

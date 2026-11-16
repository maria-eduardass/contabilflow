<?php

declare(strict_types=1);

namespace ContabilFlow\Models;

use ContabilFlow\Core\LazyPdo;
use ContabilFlow\Core\Sql;
use InvalidArgumentException;
use PDO;
use PDOException;
use Throwable;

/**
 * Todo o SQL de empresas, sempre com prepared statements.
 *
 * Por quê: unicidade e vínculos são garantidos pelo banco (UNIQUE e FK); aqui
 * só traduzimos o erro. Um "SELECT antes do INSERT" abriria condição de corrida.
 * A edição usa lock otimista: o UPDATE só vale se a versão ainda for a lida.
 */
final class EmpresaRepository implements EmpresaRepositoryInterface
{
    private const ER_DUP_ENTRY = 1062;
    private const ER_ROW_IS_REFERENCED = 1451;
    private const COLUMNS = 'id, razao_social, nome_fantasia, cnpj, regime, email, whatsapp, '
        . 'responsavel_id, ativo, versao';

    public function __construct(private LazyPdo $db)
    {
    }

    public function find(int $id): ?Empresa
    {
        $stmt = $this->db->pdo()->prepare('SELECT ' . self::COLUMNS . ' FROM empresas WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function search(EmpresaFiltro $filtro): Page
    {
        [$where, $params] = $this->conditions($filtro);
        $order = $this->orderBy($filtro->sort);
        $pdo = $this->db->pdo();

        $count = $pdo->prepare('SELECT COUNT(*) FROM empresas' . $where);
        $count->execute($params);
        $total = (int) $count->fetchColumn();

        $page = max(1, $filtro->page);
        $stmt = $pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM empresas' . $where . ' ORDER BY ' . $order . ' LIMIT ? OFFSET ?'
        );
        foreach ($params as $i => $value) {
            $stmt->bindValue($i + 1, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue(count($params) + 1, EmpresaFiltro::PER_PAGE, PDO::PARAM_INT);
        $stmt->bindValue(count($params) + 2, ($page - 1) * EmpresaFiltro::PER_PAGE, PDO::PARAM_INT);
        $stmt->execute();

        $items = [];
        foreach ($stmt->fetchAll() as $row) {
            $items[] = $this->hydrate((array) $row);
        }

        return new Page($items, $total, $page, EmpresaFiltro::PER_PAGE);
    }

    public function listOptions(bool $apenasAtivas = false): array
    {
        $rows = $this->db->pdo()->query(
            'SELECT id, razao_social FROM empresas' . ($apenasAtivas ? ' WHERE ativo = 1' : '')
            . ' ORDER BY razao_social, id'
        );
        $options = [];
        foreach ($rows === false ? [] : $rows->fetchAll() as $row) {
            $options[] = ['id' => (int) $row['id'], 'nome' => (string) $row['razao_social']];
        }

        return $options;
    }

    public function create(EmpresaDados $dados): int
    {
        $pdo = $this->db->pdo();
        try {
            $pdo->prepare(
                'INSERT INTO empresas (razao_social, nome_fantasia, cnpj, regime, email, whatsapp, '
                . 'responsavel_id, ativo) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $dados->razaoSocial, $dados->nomeFantasia, $dados->cnpj, $dados->regime,
                $dados->email, $dados->whatsapp, $dados->responsavelId, (int) $dados->ativo,
            ]);
        } catch (PDOException $e) {
            throw $this->translate($e);
        }

        return (int) $pdo->lastInsertId();
    }

    public function update(int $id, int $versao, EmpresaDados $dados): void
    {
        try {
            $stmt = $this->db->pdo()->prepare(
                'UPDATE empresas SET razao_social = ?, nome_fantasia = ?, cnpj = ?, regime = ?, email = ?, '
                . 'whatsapp = ?, responsavel_id = ?, ativo = ?, versao = versao + 1 WHERE id = ? AND versao = ?'
            );
            $stmt->execute([
                $dados->razaoSocial, $dados->nomeFantasia, $dados->cnpj, $dados->regime,
                $dados->email, $dados->whatsapp, $dados->responsavelId, (int) $dados->ativo, $id, $versao,
            ]);
        } catch (PDOException $e) {
            throw $this->translate($e);
        }

        if ($stmt->rowCount() === 1) {
            return;
        }

        // Zero linhas: ou a empresa sumiu ou a versão mudou. A releitura distingue os casos.
        throw $this->find($id) === null
            ? new RecordNotFoundException("Empresa {$id} não encontrada.")
            : new StaleVersionException('Empresa alterada por outra pessoa. Recarregue e tente de novo.');
    }

    public function delete(int $id): bool
    {
        try {
            $stmt = $this->db->pdo()->prepare('DELETE FROM empresas WHERE id = ?');
            $stmt->execute([$id]);
        } catch (PDOException $e) {
            throw $this->translate($e);
        }

        return $stmt->rowCount() === 1;
    }

    /** @return array{0: string, 1: list<int|string>} */
    private function conditions(EmpresaFiltro $filtro): array
    {
        $where = [];
        $params = [];

        $q = trim((string) $filtro->q);
        if ($q !== '') {
            $like = '%' . Sql::escapeLike($q) . '%';
            $parts = ["razao_social LIKE ? ESCAPE '!'", "nome_fantasia LIKE ? ESCAPE '!'"];
            $params[] = $like;
            $params[] = $like;

            // CNPJ digitado com máscara também deve achar (o banco guarda só dígitos).
            // Só vale se o texto parece um CNPJ: senão "Loja 2" casaria com qualquer CNPJ com "2".
            if (preg_match('/^[\d.\/\-\s]+$/', $q) === 1) {
                $parts[] = "cnpj LIKE ? ESCAPE '!'";
                $params[] = '%' . preg_replace('/\D/', '', $q) . '%';
            }
            $where[] = '(' . implode(' OR ', $parts) . ')';
        }
        if ($filtro->regime !== null) {
            $where[] = 'regime = ?';
            $params[] = $filtro->regime;
        }
        if ($filtro->ativo !== null) {
            $where[] = 'ativo = ?';
            $params[] = (int) $filtro->ativo;
        }

        return [$where === [] ? '' : ' WHERE ' . implode(' AND ', $where), $params];
    }

    /** Por quê: ORDER BY não aceita parâmetro, então só colunas da lista fixa entram no SQL. */
    private function orderBy(string $sort): string
    {
        $column = ltrim($sort, '-');
        if (!in_array($column, EmpresaFiltro::SORTS, true)) {
            throw new InvalidArgumentException("Ordenação inválida: {$sort}");
        }

        return $column . (str_starts_with($sort, '-') ? ' DESC' : ' ASC') . ', id ASC';
    }

    private function translate(PDOException $e): Throwable
    {
        return match ((int) ($e->errorInfo[1] ?? 0)) {
            self::ER_DUP_ENTRY => new DuplicateException('cnpj', $e),
            self::ER_ROW_IS_REFERENCED => new ConflictException(
                'Empresa possui obrigações ou usuários vinculados. Desative-a em vez de excluir.',
                0,
                $e
            ),
            default => $e,
        };
    }

    /** @param array<array-key, mixed> $row */
    private function hydrate(array $row): Empresa
    {
        return new Empresa(
            (int) $row['id'],
            (string) $row['razao_social'],
            $row['nome_fantasia'] === null ? null : (string) $row['nome_fantasia'],
            (string) $row['cnpj'],
            (string) $row['regime'],
            $row['email'] === null ? null : (string) $row['email'],
            $row['whatsapp'] === null ? null : (string) $row['whatsapp'],
            $row['responsavel_id'] === null ? null : (int) $row['responsavel_id'],
            (bool) $row['ativo'],
            (int) $row['versao']
        );
    }
}

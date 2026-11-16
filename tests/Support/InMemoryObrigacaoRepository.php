<?php

declare(strict_types=1);

namespace ContabilFlow\Tests\Support;

use ContabilFlow\Models\ConflictException;
use ContabilFlow\Models\DuplicateException;
use ContabilFlow\Models\FaixaVencimento;
use ContabilFlow\Models\InvalidStateException;
use ContabilFlow\Models\Obrigacao;
use ContabilFlow\Models\ObrigacaoDados;
use ContabilFlow\Models\ObrigacaoFiltro;
use ContabilFlow\Models\ObrigacaoRepositoryInterface;
use ContabilFlow\Models\ObrigacaoStatus;
use ContabilFlow\Models\Page;
use ContabilFlow\Models\RecordNotFoundException;
use ContabilFlow\Models\ResumoObrigacoes;
use ContabilFlow\Models\StaleVersionException;
use DateTimeImmutable;

/** Repositório falso com as mesmas regras do real: duplicidade, versão, transições e guia paga. */
final class InMemoryObrigacaoRepository implements ObrigacaoRepositoryInterface
{
    /** @var array<int, Obrigacao> */
    public array $rows = [];

    public ?ObrigacaoFiltro $lastFilter = null;
    private int $nextId = 1;

    public function __construct(private InMemoryEmpresaRepository $empresas)
    {
    }

    public function find(int $id): ?Obrigacao
    {
        return $this->rows[$id] ?? null;
    }

    public function search(ObrigacaoFiltro $filtro): Page
    {
        $this->lastFilter = $filtro;
        $items = array_values(array_filter(
            $this->rows,
            static fn(Obrigacao $o): bool => ($filtro->empresaId === null || $o->empresaId === $filtro->empresaId)
                && ($filtro->tipo === null || $o->tipo === $filtro->tipo)
                && ($filtro->status === null || $o->status === $filtro->status)
                && ($filtro->q === null || stripos((string) $o->descricao . $o->empresaNome, $filtro->q) !== false)
                && ($filtro->faixa === null || self::inRange($o, $filtro->faixa))
        ));
        usort($items, static fn(Obrigacao $a, Obrigacao $b): int => $a->vencimento <=> $b->vencimento);

        return new Page($items, count($items), max(1, $filtro->page), ObrigacaoFiltro::PER_PAGE);
    }

    public function resumo(array $faixas, DateTimeImmutable $pagasDe, DateTimeImmutable $pagasAte): ResumoObrigacoes
    {
        $porCor = [];
        foreach ($faixas as $name => $faixa) {
            $porCor[$name] = count(array_filter(
                $this->rows,
                static fn(Obrigacao $o): bool => self::inRange($o, $faixa)
            ));
        }
        $pagas = count(array_filter(
            $this->rows,
            static fn(Obrigacao $o): bool => $o->status === ObrigacaoStatus::Paga
                && $o->pagaEm !== null && $o->pagaEm >= $pagasDe && $o->pagaEm < $pagasAte
        ));
        $valor = array_sum(array_map(
            static fn(Obrigacao $o): float => $o->status->isActive() ? (float) $o->valor : 0.0,
            $this->rows
        ));

        return new ResumoObrigacoes($porCor, $pagas, number_format($valor, 2, '.', ''));
    }

    private static function inRange(Obrigacao $o, FaixaVencimento $faixa): bool
    {
        if (!$faixa->emAberto) {
            return !$o->status->isActive();
        }

        return $o->status->isActive()
            && ($faixa->de === null || $o->vencimento >= $faixa->de)
            && ($faixa->ate === null || $o->vencimento <= $faixa->ate);
    }

    public function create(ObrigacaoDados $dados, int $criadoPor): int
    {
        $this->assertNoDuplicate($dados, ObrigacaoStatus::Pendente, null);
        $id = $this->nextId++;
        $this->rows[$id] = $this->build($id, 1, $dados, ObrigacaoStatus::Pendente, null, null, $criadoPor);

        return $id;
    }

    public function update(int $id, int $versao, ObrigacaoDados $dados): void
    {
        $current = $this->rows[$id] ?? throw new RecordNotFoundException('x');
        if ($current->status === ObrigacaoStatus::Paga) {
            throw new InvalidStateException('Guia já paga não pode ser editada.');
        }
        if ($current->versao !== $versao) {
            throw new StaleVersionException('desatualizada');
        }
        $this->assertNoDuplicate($dados, $current->status, $id);
        $this->rows[$id] = $this->build(
            $id,
            $versao + 1,
            $dados,
            $current->status,
            $current->enviadaEm,
            $current->pagaEm,
            $current->criadoPor
        );
    }

    public function changeStatus(int $id, int $versao, ObrigacaoStatus $to, DateTimeImmutable $agora): void
    {
        $current = $this->rows[$id] ?? throw new RecordNotFoundException('x');
        if ($current->versao !== $versao) {
            throw new StaleVersionException('desatualizada');
        }
        if (!$current->status->canTransitionTo($to)) {
            throw new InvalidStateException("Transição inválida: {$current->status->value} → {$to->value}.");
        }
        $dados = $this->dadosOf($current);
        $this->assertNoDuplicate($dados, $to, $id);

        $this->rows[$id] = $this->build(
            $id,
            $versao + 1,
            $dados,
            $to,
            match ($to) {
                ObrigacaoStatus::Enviada => $agora,
                ObrigacaoStatus::Pendente => null,
                default => $current->enviadaEm,
            },
            $to === ObrigacaoStatus::Paga ? $agora : $current->pagaEm,
            $current->criadoPor
        );
    }

    public function delete(int $id): bool
    {
        $current = $this->rows[$id] ?? null;
        if ($current === null) {
            return false;
        }
        if ($current->status === ObrigacaoStatus::Paga) {
            throw new ConflictException('Guia paga não pode ser excluída.');
        }
        unset($this->rows[$id]);

        return true;
    }

    /** Atalho para montar o cenário dos testes. */
    public function seed(
        int $empresaId,
        string $tipo = 'DAS',
        string $competencia = '2026-10-01',
        string $vencimento = '2026-11-20',
        ObrigacaoStatus $status = ObrigacaoStatus::Pendente,
    ): int {
        $id = $this->create(new ObrigacaoDados(
            $empresaId,
            $tipo,
            $tipo === 'OUTRA' ? 'Descrição' : null,
            new DateTimeImmutable($competencia),
            new DateTimeImmutable($vencimento),
            '100.00',
            null
        ), 1);
        if ($status !== ObrigacaoStatus::Pendente) {
            $current = $this->rows[$id];
            $this->rows[$id] = $this->build(
                $id,
                1,
                $this->dadosOf($current),
                $status,
                null,
                $status === ObrigacaoStatus::Paga ? new DateTimeImmutable('2026-11-01') : null,
                1
            );
        }

        return $id;
    }

    private function assertNoDuplicate(ObrigacaoDados $d, ObrigacaoStatus $status, ?int $exceptId): void
    {
        if ($d->tipo === 'OUTRA' || $status === ObrigacaoStatus::Cancelada) {
            return;
        }
        foreach ($this->rows as $row) {
            if (
                $row->id !== $exceptId && $row->status !== ObrigacaoStatus::Cancelada
                && $row->empresaId === $d->empresaId && $row->tipo === $d->tipo
                && $row->competencia->format('Y-m') === $d->competencia->format('Y-m')
            ) {
                throw new DuplicateException('competencia');
            }
        }
    }

    private function dadosOf(Obrigacao $o): ObrigacaoDados
    {
        return new ObrigacaoDados(
            $o->empresaId,
            $o->tipo,
            $o->descricao,
            $o->competencia,
            $o->vencimento,
            $o->valor,
            $o->observacoes
        );
    }

    private function build(
        int $id,
        int $versao,
        ObrigacaoDados $d,
        ObrigacaoStatus $status,
        ?DateTimeImmutable $enviadaEm,
        ?DateTimeImmutable $pagaEm,
        int $criadoPor,
    ): Obrigacao {
        return new Obrigacao(
            $id,
            $d->empresaId,
            $this->empresas->find($d->empresaId)->razaoSocial ?? 'Empresa ' . $d->empresaId,
            $d->tipo,
            $d->descricao,
            new DateTimeImmutable($d->competencia->format('Y-m-01')),
            $d->vencimento,
            $d->valor,
            $status,
            $enviadaEm,
            $pagaEm,
            $d->observacoes,
            $criadoPor,
            $versao
        );
    }
}

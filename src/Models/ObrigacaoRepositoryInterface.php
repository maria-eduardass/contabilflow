<?php

declare(strict_types=1);

namespace ContabilFlow\Models;

use DateTimeImmutable;

interface ObrigacaoRepositoryInterface
{
    public function find(int $id): ?Obrigacao;

    /** @return Page<Obrigacao> */
    public function search(ObrigacaoFiltro $filtro): Page;

    /**
     * Totais em uma única consulta agregada.
     *
     * @param array<string, FaixaVencimento> $faixas faixas de guias em aberto, por nome (ex.: a cor do semáforo)
     * @param DateTimeImmutable $pagasDe início (inclusive) do período das pagas
     * @param DateTimeImmutable $pagasAte fim (exclusivo) do período das pagas
     */
    public function resumo(array $faixas, DateTimeImmutable $pagasDe, DateTimeImmutable $pagasAte): ResumoObrigacoes;

    /** @throws DuplicateException já existe guia ativa da empresa para o tipo e a competência */
    public function create(ObrigacaoDados $dados, int $criadoPor): int;

    /**
     * @throws RecordNotFoundException guia inexistente
     * @throws InvalidStateException guia já paga não pode ser editada
     * @throws StaleVersionException versão desatualizada
     * @throws DuplicateException a edição colidiria com outra guia ativa
     */
    public function update(int $id, int $versao, ObrigacaoDados $dados): void;

    /**
     * @throws RecordNotFoundException guia inexistente
     * @throws StaleVersionException versão desatualizada (alguém alterou antes)
     * @throws InvalidStateException transição não permitida a partir do status atual
     * @throws DuplicateException reabrir uma guia cancelada colidiria com outra ativa
     */
    public function changeStatus(int $id, int $versao, ObrigacaoStatus $to, DateTimeImmutable $agora): void;

    /**
     * @return bool false se a guia não existe
     * @throws ConflictException guia paga não pode ser excluída
     */
    public function delete(int $id): bool;
}

<?php

declare(strict_types=1);

namespace ContabilFlow\Models;

interface EmpresaRepositoryInterface
{
    public function find(int $id): ?Empresa;

    /** @return Page<Empresa> */
    public function search(EmpresaFiltro $filtro): Page;

    /** @throws DuplicateException CNPJ já cadastrado */
    public function create(EmpresaDados $dados): int;

    /**
     * @throws RecordNotFoundException empresa inexistente
     * @throws StaleVersionException versão desatualizada
     * @throws DuplicateException CNPJ já cadastrado
     */
    public function update(int $id, int $versao, EmpresaDados $dados): void;

    /**
     * @return bool false se a empresa não existe
     * @throws ConflictException empresa possui obrigações ou usuários
     */
    public function delete(int $id): bool;
}

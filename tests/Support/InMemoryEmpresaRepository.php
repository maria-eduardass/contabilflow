<?php

declare(strict_types=1);

namespace ContabilFlow\Tests\Support;

use ContabilFlow\Models\ConflictException;
use ContabilFlow\Models\DuplicateException;
use ContabilFlow\Models\Empresa;
use ContabilFlow\Models\EmpresaDados;
use ContabilFlow\Models\EmpresaFiltro;
use ContabilFlow\Models\EmpresaRepositoryInterface;
use ContabilFlow\Models\Page;
use ContabilFlow\Models\RecordNotFoundException;
use ContabilFlow\Models\StaleVersionException;

/** Repositório falso com as mesmas regras de erro do real (duplicidade, versão, vínculo). */
final class InMemoryEmpresaRepository implements EmpresaRepositoryInterface
{
    /** @var array<int, Empresa> */
    public array $rows = [];

    /** @var list<int> ids de empresas "com obrigações" (a exclusão é bloqueada) */
    public array $linked = [];

    public ?EmpresaFiltro $lastFilter = null;
    private int $nextId = 1;

    public function find(int $id): ?Empresa
    {
        return $this->rows[$id] ?? null;
    }

    public function search(EmpresaFiltro $filtro): Page
    {
        $this->lastFilter = $filtro;
        $items = array_values(array_filter(
            $this->rows,
            static fn(Empresa $e): bool => ($filtro->q === null || stripos($e->razaoSocial, $filtro->q) !== false)
                && ($filtro->regime === null || $e->regime === $filtro->regime)
                && ($filtro->ativo === null || $e->ativo === $filtro->ativo)
        ));

        return new Page($items, count($items), max(1, $filtro->page), EmpresaFiltro::PER_PAGE);
    }

    public function create(EmpresaDados $dados): int
    {
        $this->assertCnpjFree($dados->cnpj, null);
        $id = $this->nextId++;
        $this->rows[$id] = $this->build($id, 1, $dados);

        return $id;
    }

    public function update(int $id, int $versao, EmpresaDados $dados): void
    {
        $current = $this->rows[$id] ?? throw new RecordNotFoundException('x');
        if ($current->versao !== $versao) {
            throw new StaleVersionException('desatualizada');
        }
        $this->assertCnpjFree($dados->cnpj, $id);
        $this->rows[$id] = $this->build($id, $versao + 1, $dados);
    }

    public function delete(int $id): bool
    {
        if (!isset($this->rows[$id])) {
            return false;
        }
        if (in_array($id, $this->linked, true)) {
            throw new ConflictException('vinculada');
        }
        unset($this->rows[$id]);

        return true;
    }

    /** Atalho para montar o cenário dos testes. */
    public function seed(string $razao, string $cnpj, string $regime = 'simples', bool $ativo = true): int
    {
        return $this->create(new EmpresaDados($razao, null, $cnpj, $regime, null, null, null, $ativo));
    }

    private function assertCnpjFree(string $cnpj, ?int $exceptId): void
    {
        foreach ($this->rows as $row) {
            if ($row->cnpj === $cnpj && $row->id !== $exceptId) {
                throw new DuplicateException('cnpj');
            }
        }
    }

    private function build(int $id, int $versao, EmpresaDados $d): Empresa
    {
        return new Empresa(
            $id,
            $d->razaoSocial,
            $d->nomeFantasia,
            $d->cnpj,
            $d->regime,
            $d->email,
            $d->whatsapp,
            $d->responsavelId,
            $d->ativo,
            $versao
        );
    }
}

<?php
/**
 * @var \ContabilFlow\Models\Page<\ContabilFlow\Models\Obrigacao> $resultado
 * @var array<string, string> $values
 * @var array<string, string> $errors
 * @var list<array{id: int, nome: string}> $empresas
 * @var list<string> $tipos
 * @var list<\ContabilFlow\Models\ObrigacaoStatus> $statuses
 * @var list<\ContabilFlow\Models\Semaforo> $semaforos
 * @var \Closure(\ContabilFlow\Models\Obrigacao): \ContabilFlow\Models\Semaforo $cor
 * @var \Closure(int): string $pageUrl
 */
$sortLabels = [
    'vencimento' => 'Vencimento (mais próximo)', '-vencimento' => 'Vencimento (mais distante)',
    'competencia' => 'Competência (antiga)', '-competencia' => 'Competência (recente)',
    '-valor' => 'Maior valor', 'valor' => 'Menor valor', 'tipo' => 'Tipo', 'status' => 'Status',
    '-criado_em' => 'Cadastradas recentemente',
];
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0">Guias</h1>
    <a href="/obrigacoes/nova<?= $values['empresa_id'] !== '' ? '?empresa_id=' . e($values['empresa_id']) : '' ?>"
       class="btn btn-primary">Nova guia</a>
</div>

<?php if ($errors !== []): ?>
    <div class="alert alert-danger" role="alert">
        Filtro inválido: <?= e(implode('; ', $errors)) ?>. Mostrando a lista sem filtros.
    </div>
<?php endif; ?>

<form method="get" action="/obrigacoes" class="card card-body shadow-sm mb-3">
    <div class="row g-2 align-items-end">
        <div class="col-md-3">
            <label for="q" class="form-label">Buscar</label>
            <input type="search" id="q" name="q" value="<?= e($values['q']) ?>" maxlength="100"
                   placeholder="Empresa ou descrição" class="form-control">
        </div>
        <div class="col-md-3">
            <label for="empresa_id" class="form-label">Empresa</label>
            <select id="empresa_id" name="empresa_id" class="form-select">
                <option value="">Todas</option>
                <?php foreach ($empresas as $empresa): ?>
                    <option value="<?= e($empresa['id']) ?>"<?= $values['empresa_id'] === (string) $empresa['id'] ? ' selected' : '' ?>><?= e($empresa['nome']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label for="tipo" class="form-label">Tipo</label>
            <select id="tipo" name="tipo" class="form-select">
                <option value="">Todos</option>
                <?php foreach ($tipos as $tipo): ?>
                    <option value="<?= e($tipo) ?>"<?= $values['tipo'] === $tipo ? ' selected' : '' ?>><?= e($tipo) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label for="status" class="form-label">Status</label>
            <select id="status" name="status" class="form-select">
                <option value="">Todos</option>
                <?php foreach ($statuses as $status): ?>
                    <option value="<?= e($status->value) ?>"<?= $values['status'] === $status->value ? ' selected' : '' ?>><?= e(ucfirst($status->value)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label for="semaforo" class="form-label">Prazo</label>
            <select id="semaforo" name="semaforo" class="form-select">
                <option value="">Todos</option>
                <?php foreach ($semaforos as $semaforo): ?>
                    <option value="<?= e($semaforo->value) ?>"<?= $values['semaforo'] === $semaforo->value ? ' selected' : '' ?>><?= e($semaforo->label()) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label for="de" class="form-label">Vencimento de</label>
            <input type="date" id="de" name="de" value="<?= e($values['de']) ?>" class="form-control">
        </div>
        <div class="col-md-2">
            <label for="ate" class="form-label">até</label>
            <input type="date" id="ate" name="ate" value="<?= e($values['ate']) ?>" class="form-control">
        </div>
        <div class="col-md-4">
            <label for="sort" class="form-label">Ordenar</label>
            <select id="sort" name="sort" class="form-select">
                <?php foreach ($sortLabels as $value => $label): ?>
                    <option value="<?= e($value) ?>"<?= $values['sort'] === $value ? ' selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-4 d-flex gap-2">
            <button type="submit" class="btn btn-outline-primary flex-fill">Filtrar</button>
            <a href="/obrigacoes" class="btn btn-outline-secondary">Limpar</a>
        </div>
    </div>
</form>

<?php if ($resultado->items === []): ?>
    <div class="card card-body text-center text-muted">Nenhuma guia encontrada.</div>
<?php else: ?>
    <div class="card shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Prazo</th><th>Empresa</th><th>Tipo</th><th>Competência</th>
                        <th>Vencimento</th><th class="text-end">Valor</th><th>Status</th><th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($resultado->items as $guia): ?>
                    <?php $semaforo = $cor($guia); ?>
                    <tr>
                        <td><span class="badge <?= e($semaforo->badgeClass()) ?>"><?= e($semaforo->label()) ?></span></td>
                        <td><a href="/empresas/<?= e($guia->empresaId) ?>"><?= e($guia->empresaNome) ?></a></td>
                        <td>
                            <a href="/obrigacoes/<?= e($guia->id) ?>"><?= e($guia->tipo) ?></a>
                            <?php if ($guia->descricao !== null): ?>
                                <div class="small text-muted"><?= e($guia->descricao) ?></div>
                            <?php endif; ?>
                        </td>
                        <td><?= e(date_br($guia->competencia, 'm/Y')) ?></td>
                        <td><?= e(date_br($guia->vencimento)) ?></td>
                        <td class="text-end"><?= e(money_br($guia->valor)) ?></td>
                        <td><?= e(ucfirst($guia->status->value)) ?></td>
                        <td class="text-end">
                            <a href="/obrigacoes/<?= e($guia->id) ?>" class="btn btn-sm btn-outline-secondary">Abrir</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="d-flex justify-content-between align-items-center mt-3">
        <span class="text-muted small">
            <?= e($resultado->total) ?> guia(s) · página <?= e($resultado->page) ?> de <?= e($resultado->pages()) ?>
        </span>
        <?php if ($resultado->pages() > 1): ?>
            <nav aria-label="Paginação">
                <ul class="pagination mb-0">
                    <li class="page-item<?= $resultado->page <= 1 ? ' disabled' : '' ?>">
                        <a class="page-link" href="<?= e($pageUrl(max(1, $resultado->page - 1))) ?>">Anterior</a>
                    </li>
                    <li class="page-item<?= $resultado->page >= $resultado->pages() ? ' disabled' : '' ?>">
                        <a class="page-link" href="<?= e($pageUrl(min($resultado->pages(), $resultado->page + 1))) ?>">Próxima</a>
                    </li>
                </ul>
            </nav>
        <?php endif; ?>
    </div>
<?php endif; ?>

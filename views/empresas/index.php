<?php
/**
 * @var \ContabilFlow\Models\Page<\ContabilFlow\Models\Empresa> $resultado
 * @var \ContabilFlow\Models\EmpresaFiltro $filtro
 * @var array<string, string> $errors
 * @var \Closure(int): string $pageUrl
 */
use ContabilFlow\Models\Empresa;

$sortLabels = [
    'razao_social' => 'Razão social (A-Z)', '-razao_social' => 'Razão social (Z-A)',
    'cnpj' => 'CNPJ', 'regime' => 'Regime', '-criado_em' => 'Mais recentes', 'criado_em' => 'Mais antigas',
];
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0">Empresas</h1>
    <a href="/empresas/nova" class="btn btn-primary">Nova empresa</a>
</div>

<?php if ($errors !== []): ?>
    <div class="alert alert-danger" role="alert">
        Filtro inválido: <?= e(implode('; ', $errors)) ?>. Mostrando a lista sem filtros.
    </div>
<?php endif; ?>

<form method="get" action="/empresas" class="card card-body shadow-sm mb-3">
    <div class="row g-2 align-items-end">
        <div class="col-md-4">
            <label for="q" class="form-label">Buscar</label>
            <input type="search" id="q" name="q" value="<?= e($filtro->q) ?>" maxlength="100"
                   placeholder="Razão social, fantasia ou CNPJ" class="form-control">
        </div>
        <div class="col-md-2">
            <label for="regime" class="form-label">Regime</label>
            <select id="regime" name="regime" class="form-select">
                <option value="">Todos</option>
                <?php foreach (Empresa::REGIME_LABELS as $value => $label): ?>
                    <option value="<?= e($value) ?>"<?= $filtro->regime === $value ? ' selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label for="ativo" class="form-label">Situação</label>
            <select id="ativo" name="ativo" class="form-select">
                <option value="">Todas</option>
                <option value="1"<?= $filtro->ativo === true ? ' selected' : '' ?>>Ativas</option>
                <option value="0"<?= $filtro->ativo === false ? ' selected' : '' ?>>Inativas</option>
            </select>
        </div>
        <div class="col-md-2">
            <label for="sort" class="form-label">Ordenar</label>
            <select id="sort" name="sort" class="form-select">
                <?php foreach ($sortLabels as $value => $label): ?>
                    <option value="<?= e($value) ?>"<?= $filtro->sort === $value ? ' selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2 d-flex gap-2">
            <button type="submit" class="btn btn-outline-primary flex-fill">Filtrar</button>
            <a href="/empresas" class="btn btn-outline-secondary">Limpar</a>
        </div>
    </div>
</form>

<?php if ($resultado->items === []): ?>
    <div class="card card-body text-center text-muted">Nenhuma empresa encontrada.</div>
<?php else: ?>
    <div class="card shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr><th>Razão social</th><th>CNPJ</th><th>Regime</th><th>Contato</th><th>Situação</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach ($resultado->items as $empresa): ?>
                    <tr>
                        <td>
                            <a href="/empresas/<?= e($empresa->id) ?>"><?= e($empresa->razaoSocial) ?></a>
                            <?php if ($empresa->nomeFantasia !== null): ?>
                                <div class="small text-muted"><?= e($empresa->nomeFantasia) ?></div>
                            <?php endif; ?>
                        </td>
                        <td><?= e(cnpj_mask($empresa->cnpj)) ?></td>
                        <td><?= e(Empresa::REGIME_LABELS[$empresa->regime] ?? $empresa->regime) ?></td>
                        <td>
                            <?= e($empresa->whatsapp !== null ? phone_mask($empresa->whatsapp) : '') ?>
                            <div class="small text-muted"><?= e($empresa->email) ?></div>
                        </td>
                        <td>
                            <span class="badge <?= $empresa->ativo ? 'text-bg-success' : 'text-bg-secondary' ?>">
                                <?= $empresa->ativo ? 'Ativa' : 'Inativa' ?>
                            </span>
                        </td>
                        <td class="text-end">
                            <a href="/empresas/<?= e($empresa->id) ?>/editar" class="btn btn-sm btn-outline-secondary">Editar</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="d-flex justify-content-between align-items-center mt-3">
        <span class="text-muted small">
            <?= e($resultado->total) ?> empresa(s) · página <?= e($resultado->page) ?> de <?= e($resultado->pages()) ?>
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

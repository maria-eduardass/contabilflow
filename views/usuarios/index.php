<?php
/**
 * @var \ContabilFlow\Models\Page<\ContabilFlow\Models\Usuario> $usuarios
 * @var int $currentId
 * @var array<string, string> $errors
 * @var string $csrfField
 */
use ContabilFlow\Controllers\UsuarioController;

$pageUrl = static fn(int $page): string => '/usuarios' . ($page > 1 ? '?page=' . $page : '');
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0">Usuários</h1>
    <a href="/usuarios/novo" class="btn btn-primary">Novo usuário</a>
</div>

<?php if ($errors !== []): ?>
    <div class="alert alert-danger" role="alert">
        <?= e(implode('; ', $errors)) ?>. Mostrando a primeira página.
    </div>
<?php endif; ?>

<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr><th>Nome</th><th>E-mail</th><th>Perfil</th><th>Situação</th><th>Cadastrado em</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($usuarios->items as $usuario): ?>
                <tr id="usuario-<?= e($usuario->id) ?>">
                    <td>
                        <?= e($usuario->nome) ?>
                        <?php if ($usuario->id === $currentId): ?>
                            <span class="badge text-bg-light border">você</span>
                        <?php endif; ?>
                    </td>
                    <td><?= e($usuario->email) ?></td>
                    <td><?= e(UsuarioController::PERFIS[$usuario->perfil] ?? $usuario->perfil) ?></td>
                    <td>
                        <span class="badge <?= $usuario->ativo ? 'text-bg-success' : 'text-bg-secondary' ?>">
                            <?= $usuario->ativo ? 'Ativo' : 'Inativo' ?>
                        </span>
                    </td>
                    <td><?= e(date_br($usuario->criadoEm)) ?></td>
                    <td class="text-end">
                        <?php if ($usuario->id !== $currentId): ?>
                            <form method="post" action="/usuarios/<?= e($usuario->id) ?>/ativo" class="d-inline"
                                  <?= $usuario->ativo ? 'data-confirm="Desativar este usuário? Ele perde o acesso imediatamente."' : '' ?>>
                                <?= $csrfField ?>
                                <input type="hidden" name="_method" value="PATCH">
                                <input type="hidden" name="ativo" value="<?= $usuario->ativo ? '0' : '1' ?>">
                                <button type="submit" class="btn btn-sm <?= $usuario->ativo ? 'btn-outline-danger' : 'btn-outline-success' ?>">
                                    <?= $usuario->ativo ? 'Desativar' : 'Reativar' ?>
                                </button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="d-flex justify-content-between align-items-center mt-3">
    <span class="text-muted small">
        <?= e($usuarios->total) ?> usuário(s) · página <?= e($usuarios->page) ?> de <?= e($usuarios->pages()) ?>
    </span>
    <?php if ($usuarios->pages() > 1): ?>
        <nav aria-label="Paginação">
            <ul class="pagination mb-0">
                <li class="page-item<?= $usuarios->page <= 1 ? ' disabled' : '' ?>">
                    <a class="page-link" href="<?= e($pageUrl(max(1, $usuarios->page - 1))) ?>">Anterior</a>
                </li>
                <li class="page-item<?= $usuarios->page >= $usuarios->pages() ? ' disabled' : '' ?>">
                    <a class="page-link" href="<?= e($pageUrl(min($usuarios->pages(), $usuarios->page + 1))) ?>">Próxima</a>
                </li>
            </ul>
        </nav>
    <?php endif; ?>
</div>

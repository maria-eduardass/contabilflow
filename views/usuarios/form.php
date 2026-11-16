<?php
/**
 * @var array<string, string> $values
 * @var array<string, string> $errors
 * @var array<string, string> $perfis
 * @var int $minPassword
 * @var string $csrfField
 */
$field = static fn(string $name): string => e($values[$name] ?? '');
$invalid = static fn(string $name): string => isset($errors[$name]) ? ' is-invalid' : '';
$feedback = static fn(string $name): string => isset($errors[$name])
    ? '<div class="invalid-feedback">' . e($errors[$name]) . '</div>'
    : '';
?>
<div class="row justify-content-center">
    <div class="col-lg-6">
        <h1 class="h3 mb-3">Novo usuário</h1>

        <?php if ($errors !== []): ?>
            <div class="alert alert-danger" role="alert">Corrija os campos destacados e tente novamente.</div>
        <?php endif; ?>

        <form method="post" action="/usuarios" novalidate class="card card-body shadow-sm">
            <?= $csrfField ?>
            <div class="mb-3">
                <label for="nome" class="form-label">Nome *</label>
                <input type="text" id="nome" name="nome" value="<?= $field('nome') ?>" maxlength="120"
                       class="form-control<?= $invalid('nome') ?>" required autofocus>
                <?= $feedback('nome') ?>
            </div>
            <div class="mb-3">
                <label for="email" class="form-label">E-mail *</label>
                <input type="email" id="email" name="email" value="<?= $field('email') ?>" maxlength="180"
                       class="form-control<?= $invalid('email') ?>" autocomplete="off" required>
                <?= $feedback('email') ?>
            </div>
            <div class="mb-3">
                <label for="perfil" class="form-label">Perfil *</label>
                <select id="perfil" name="perfil" class="form-select<?= $invalid('perfil') ?>" required>
                    <?php foreach ($perfis as $value => $label): ?>
                        <option value="<?= e($value) ?>"<?= ($values['perfil'] ?? '') === $value ? ' selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text">Administradores também gerenciam usuários e veem o resumo das avaliações.</div>
                <?= $feedback('perfil') ?>
            </div>
            <div class="mb-3">
                <label for="senha" class="form-label">Senha * <span class="text-muted">(mínimo <?= e($minPassword) ?> caracteres)</span></label>
                <input type="password" id="senha" name="senha" class="form-control<?= $invalid('senha') ?>"
                       autocomplete="new-password" required>
                <?= $feedback('senha') ?>
            </div>
            <div class="mb-3">
                <label for="senha_confirmacao" class="form-label">Confirmar senha *</label>
                <input type="password" id="senha_confirmacao" name="senha_confirmacao"
                       class="form-control<?= $invalid('senha_confirmacao') ?>" autocomplete="new-password" required>
                <?= $feedback('senha_confirmacao') ?>
            </div>
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary">Cadastrar</button>
                <a href="/usuarios" class="btn btn-outline-secondary">Cancelar</a>
            </div>
        </form>
    </div>
</div>

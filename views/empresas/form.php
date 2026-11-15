<?php
/**
 * @var array<string, mixed> $values
 * @var array<string, string> $errors
 * @var int|null $id
 * @var int|null $versao
 * @var string|null $message
 * @var list<array{id: int, nome: string}> $responsaveis
 * @var string $csrfField
 */
use ContabilFlow\Models\Empresa;

$editing = $id !== null;
$field = static fn(string $name): string => e((string) ($values[$name] ?? ''));
$invalid = static fn(string $name): string => isset($errors[$name]) ? ' is-invalid' : '';
$feedback = static fn(string $name): string => isset($errors[$name])
    ? '<div class="invalid-feedback">' . e($errors[$name]) . '</div>'
    : '';
?>
<div class="row justify-content-center">
    <div class="col-lg-8">
        <h1 class="h3 mb-3"><?= e($title) ?></h1>

        <?php if ($message !== null): ?>
            <div class="alert alert-warning" role="alert"><?= e($message) ?></div>
        <?php elseif ($errors !== []): ?>
            <div class="alert alert-danger" role="alert">Corrija os campos destacados e tente novamente.</div>
        <?php endif; ?>

        <form method="post" action="<?= $editing ? '/empresas/' . e($id) : '/empresas' ?>" novalidate
              class="card card-body shadow-sm">
            <?= $csrfField ?>
            <?php if ($editing): ?>
                <input type="hidden" name="_method" value="PUT">
                <input type="hidden" name="versao" value="<?= e($versao) ?>">
            <?php endif; ?>

            <div class="row g-3">
                <div class="col-md-8">
                    <label for="razao_social" class="form-label">Razão social *</label>
                    <input type="text" id="razao_social" name="razao_social" value="<?= $field('razao_social') ?>"
                           maxlength="180" class="form-control<?= $invalid('razao_social') ?>" required>
                    <?= $feedback('razao_social') ?>
                </div>
                <div class="col-md-4">
                    <label for="cnpj" class="form-label">CNPJ *</label>
                    <input type="text" id="cnpj" name="cnpj" value="<?= $field('cnpj') ?>" inputmode="numeric"
                           placeholder="00.000.000/0000-00" class="form-control<?= $invalid('cnpj') ?>" required>
                    <?= $feedback('cnpj') ?>
                </div>
                <div class="col-md-8">
                    <label for="nome_fantasia" class="form-label">Nome fantasia</label>
                    <input type="text" id="nome_fantasia" name="nome_fantasia" value="<?= $field('nome_fantasia') ?>"
                           maxlength="180" class="form-control<?= $invalid('nome_fantasia') ?>">
                    <?= $feedback('nome_fantasia') ?>
                </div>
                <div class="col-md-4">
                    <label for="regime" class="form-label">Regime tributário *</label>
                    <select id="regime" name="regime" class="form-select<?= $invalid('regime') ?>" required>
                        <option value="">Selecione</option>
                        <?php foreach (Empresa::REGIME_LABELS as $value => $label): ?>
                            <option value="<?= e($value) ?>"<?= ($values['regime'] ?? '') === $value ? ' selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?= $feedback('regime') ?>
                </div>
                <div class="col-md-6">
                    <label for="email" class="form-label">E-mail</label>
                    <input type="email" id="email" name="email" value="<?= $field('email') ?>" maxlength="180"
                           class="form-control<?= $invalid('email') ?>">
                    <?= $feedback('email') ?>
                </div>
                <div class="col-md-6">
                    <label for="whatsapp" class="form-label">WhatsApp</label>
                    <input type="text" id="whatsapp" name="whatsapp" value="<?= $field('whatsapp') ?>"
                           inputmode="tel" placeholder="(11) 98765-4321" class="form-control<?= $invalid('whatsapp') ?>">
                    <?= $feedback('whatsapp') ?>
                </div>
                <div class="col-md-8">
                    <label for="responsavel_id" class="form-label">Contador responsável</label>
                    <select id="responsavel_id" name="responsavel_id" class="form-select<?= $invalid('responsavel_id') ?>">
                        <option value="">Sem responsável</option>
                        <?php foreach ($responsaveis as $user): ?>
                            <option value="<?= e($user['id']) ?>"<?= (string) ($values['responsavel_id'] ?? '') === (string) $user['id'] ? ' selected' : '' ?>><?= e($user['nome']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?= $feedback('responsavel_id') ?>
                </div>
                <div class="col-md-4 d-flex align-items-end">
                    <div class="form-check">
                        <input type="checkbox" id="ativo" name="ativo" value="1" class="form-check-input"
                            <?= !empty($values['ativo']) ? ' checked' : '' ?>>
                        <label for="ativo" class="form-check-label">Empresa ativa</label>
                    </div>
                </div>
            </div>

            <div class="d-flex gap-2 mt-4">
                <button type="submit" class="btn btn-primary">Salvar</button>
                <a href="<?= $editing ? '/empresas/' . e($id) : '/empresas' ?>" class="btn btn-outline-secondary">Cancelar</a>
            </div>
        </form>
    </div>
</div>

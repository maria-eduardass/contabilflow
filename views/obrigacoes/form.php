<?php
/**
 * @var array<string, mixed> $values
 * @var array<string, string> $errors
 * @var int|null $id
 * @var int|null $versao
 * @var string|null $message
 * @var list<array{id: int, nome: string}> $empresas
 * @var string|null $empresaNome
 * @var list<string> $tipos
 * @var string $csrfField
 */
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

        <form method="post" action="<?= $editing ? '/obrigacoes/' . e($id) : '/obrigacoes' ?>" novalidate
              class="card card-body shadow-sm">
            <?= $csrfField ?>
            <?php if ($editing): ?>
                <input type="hidden" name="_method" value="PUT">
                <input type="hidden" name="versao" value="<?= e($versao) ?>">
            <?php endif; ?>

            <div class="row g-3">
                <div class="col-12">
                    <?php if ($editing): ?>
                        <label class="form-label">Empresa</label>
                        <input type="text" value="<?= e($empresaNome) ?>" class="form-control" disabled>
                    <?php else: ?>
                        <label for="empresa_id" class="form-label">Empresa *</label>
                        <select id="empresa_id" name="empresa_id" class="form-select<?= $invalid('empresa_id') ?>" required>
                            <option value="">Selecione</option>
                            <?php foreach ($empresas as $empresa): ?>
                                <option value="<?= e($empresa['id']) ?>"<?= (string) ($values['empresa_id'] ?? '') === (string) $empresa['id'] ? ' selected' : '' ?>><?= e($empresa['nome']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?= $feedback('empresa_id') ?>
                    <?php endif; ?>
                </div>
                <div class="col-md-4">
                    <label for="tipo" class="form-label">Tipo *</label>
                    <select id="tipo" name="tipo" class="form-select<?= $invalid('tipo') ?>" required>
                        <option value="">Selecione</option>
                        <?php foreach ($tipos as $tipo): ?>
                            <option value="<?= e($tipo) ?>"<?= ($values['tipo'] ?? '') === $tipo ? ' selected' : '' ?>><?= e($tipo) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?= $feedback('tipo') ?>
                </div>
                <div class="col-md-8">
                    <label for="descricao" class="form-label">Descrição <span class="text-muted">(obrigatória para "OUTRA")</span></label>
                    <input type="text" id="descricao" name="descricao" value="<?= $field('descricao') ?>" maxlength="255"
                           class="form-control<?= $invalid('descricao') ?>">
                    <?= $feedback('descricao') ?>
                </div>
                <div class="col-md-4">
                    <label for="competencia" class="form-label">Competência *</label>
                    <input type="month" id="competencia" name="competencia" value="<?= $field('competencia') ?>"
                           class="form-control<?= $invalid('competencia') ?>" required>
                    <?= $feedback('competencia') ?>
                </div>
                <div class="col-md-4">
                    <label for="vencimento" class="form-label">Vencimento *</label>
                    <input type="date" id="vencimento" name="vencimento" value="<?= $field('vencimento') ?>"
                           class="form-control<?= $invalid('vencimento') ?>" required>
                    <?= $feedback('vencimento') ?>
                </div>
                <div class="col-md-4">
                    <label for="valor" class="form-label">Valor (R$)</label>
                    <input type="text" id="valor" name="valor" value="<?= $field('valor') ?>" inputmode="decimal"
                           placeholder="1234,56" class="form-control<?= $invalid('valor') ?>">
                    <?= $feedback('valor') ?>
                </div>
                <div class="col-12">
                    <label for="observacoes" class="form-label">Observações</label>
                    <textarea id="observacoes" name="observacoes" rows="3" maxlength="2000"
                              class="form-control<?= $invalid('observacoes') ?>"><?= $field('observacoes') ?></textarea>
                    <?= $feedback('observacoes') ?>
                </div>
            </div>

            <div class="d-flex gap-2 mt-4">
                <button type="submit" class="btn btn-primary">Salvar</button>
                <a href="<?= $editing ? '/obrigacoes/' . e($id) : '/obrigacoes' ?>" class="btn btn-outline-secondary">Cancelar</a>
            </div>
        </form>
    </div>
</div>

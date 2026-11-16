<?php
/**
 * @var array<string, string> $values
 * @var array<string, string> $errors
 * @var array<string, string> $perguntas
 * @var array<int, string> $escala
 * @var string $perguntaNps
 * @var int $maxComentario
 * @var string $csrfField
 */
$checked = static fn(string $name, string $value): string => ($values[$name] ?? '') === $value ? ' checked' : '';
?>
<div class="row justify-content-center">
    <div class="col-lg-8">
        <h1 class="h3 mb-1">Avaliar o sistema</h1>
        <p class="text-muted">Leva 1 minuto. Conte como foi usar o ContábilFlow para nos ajudar a melhorá-lo.</p>

        <?php if ($errors !== []): ?>
            <div class="alert alert-danger" role="alert">Responda todas as perguntas obrigatórias e tente novamente.</div>
        <?php endif; ?>

        <form method="post" action="/avaliacao" novalidate class="card card-body shadow-sm">
            <?= $csrfField ?>

            <?php foreach ($perguntas as $key => $texto): ?>
                <fieldset class="mb-4">
                    <legend class="h6"><?= e($texto) ?> *</legend>
                    <div class="d-flex flex-wrap gap-3">
                        <?php foreach ($escala as $nota => $rotulo): ?>
                            <div class="form-check">
                                <input class="form-check-input<?= isset($errors[$key]) ? ' is-invalid' : '' ?>" type="radio"
                                       name="<?= e($key) ?>" id="<?= e($key) ?>-<?= e($nota) ?>" value="<?= e($nota) ?>"<?= $checked($key, (string) $nota) ?>>
                                <label class="form-check-label" for="<?= e($key) ?>-<?= e($nota) ?>"><?= e($nota) ?> · <?= e($rotulo) ?></label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <?php if (isset($errors[$key])): ?>
                        <div class="text-danger small mt-1"><?= e($errors[$key]) ?></div>
                    <?php endif; ?>
                </fieldset>
            <?php endforeach; ?>

            <fieldset class="mb-4">
                <legend class="h6"><?= e($perguntaNps) ?> *</legend>
                <div class="d-flex flex-wrap gap-2">
                    <?php for ($n = 0; $n <= 10; $n++): ?>
                        <div class="form-check form-check-inline me-1">
                            <input class="form-check-input<?= isset($errors['nps']) ? ' is-invalid' : '' ?>" type="radio" name="nps"
                                   id="nps-<?= e($n) ?>" value="<?= e($n) ?>"<?= $checked('nps', (string) $n) ?>>
                            <label class="form-check-label" for="nps-<?= e($n) ?>"><?= e($n) ?></label>
                        </div>
                    <?php endfor; ?>
                </div>
                <div class="form-text">0 = nenhuma chance · 10 = com certeza</div>
                <?php if (isset($errors['nps'])): ?>
                    <div class="text-danger small mt-1"><?= e($errors['nps']) ?></div>
                <?php endif; ?>
            </fieldset>

            <div class="mb-4">
                <label for="comentario" class="form-label">Comentário (opcional)</label>
                <textarea id="comentario" name="comentario" rows="4" maxlength="<?= e($maxComentario) ?>"
                          class="form-control<?= isset($errors['comentario']) ? ' is-invalid' : '' ?>"><?= e($values['comentario'] ?? '') ?></textarea>
                <?php if (isset($errors['comentario'])): ?>
                    <div class="invalid-feedback"><?= e($errors['comentario']) ?></div>
                <?php endif; ?>
            </div>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary">Enviar avaliação</button>
                <a href="/" class="btn btn-outline-secondary">Agora não</a>
            </div>
        </form>
    </div>
</div>

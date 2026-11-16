<?php
/**
 * @var \ContabilFlow\Models\ResumoAvaliacoes $resumo
 * @var array<string, string> $perguntas
 * @var array<string, string> $perfis
 */
$geral = $resumo->geral;
$nps = $geral->nps();
$zonaClass = match (true) {
    $nps === null => 'text-bg-secondary',
    $nps >= 75 => 'text-bg-success',
    $nps >= 50 => 'text-bg-primary',
    $nps >= 0 => 'text-bg-warning',
    default => 'text-bg-danger',
};
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0">Resultado das avaliações</h1>
    <a href="/avaliacao" class="btn btn-outline-primary">Enviar a minha avaliação</a>
</div>

<?php if ($geral->total === 0): ?>
    <div class="card card-body text-center text-muted">Ainda não há avaliações. Peça à equipe para responder em "Avaliação".</div>
<?php else: ?>
    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="card shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small">Respostas</div>
                    <div class="display-6 fw-semibold"><?= e($geral->total) ?></div>
                    <div class="text-muted small">
                        <?php foreach ($resumo->porPerfil as $perfil => $stats): ?>
                            <?= e($perfis[$perfil] ?? $perfil) ?>: <?= e($stats->total) ?><br>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small">NPS (recomendação)</div>
                    <div class="display-6 fw-semibold"><?= e($nps) ?></div>
                    <span class="badge <?= e($zonaClass) ?>"><?= e($geral->zona()) ?></span>
                    <div class="text-muted small mt-1">
                        <?= e($geral->promotores) ?> promotor(es) · <?= e($geral->neutros) ?> neutro(s) · <?= e($geral->detratores) ?> detrator(es)
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small">Média geral (1 a 5)</div>
                    <div class="display-6 fw-semibold"><?= e(number_format($geral->mediaGeral, 2, ',', '.')) ?></div>
                    <progress class="w-100" max="5" value="<?= e($geral->mediaGeral) ?>"><?= e($geral->mediaGeral) ?> de 5</progress>
                </div>
            </div>
        </div>
    </div>

    <h2 class="h5 mb-2">Médias por pergunta</h2>
    <div class="card shadow-sm mb-4">
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead>
                    <tr>
                        <th>Pergunta</th>
                        <th class="text-end">Geral</th>
                        <?php foreach ($resumo->porPerfil as $perfil => $stats): ?>
                            <th class="text-end"><?= e($perfis[$perfil] ?? $perfil) ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($perguntas as $key => $texto): ?>
                    <tr>
                        <td><?= e($texto) ?></td>
                        <td class="text-end fw-semibold"><?= e(number_format($geral->medias[$key], 2, ',', '.')) ?></td>
                        <?php foreach ($resumo->porPerfil as $stats): ?>
                            <td class="text-end"><?= e(number_format($stats->medias[$key], 2, ',', '.')) ?></td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
                    <tr class="table-light">
                        <td class="fw-semibold">NPS</td>
                        <td class="text-end fw-semibold"><?= e($nps) ?></td>
                        <?php foreach ($resumo->porPerfil as $stats): ?>
                            <td class="text-end"><?= e($stats->nps()) ?></td>
                        <?php endforeach; ?>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <h2 class="h5 mb-2">Comentários</h2>
    <?php if ($resumo->comentarios === []): ?>
        <div class="card card-body text-center text-muted">Nenhum comentário ainda.</div>
    <?php else: ?>
        <div class="card shadow-sm">
            <ul class="list-group list-group-flush">
                <?php foreach ($resumo->comentarios as $c): ?>
                    <li class="list-group-item">
                        <div class="d-flex justify-content-between">
                            <span>
                                <span class="badge text-bg-light border"><?= e($perfis[$c->perfil] ?? $c->perfil) ?></span>
                                <?= e($c->autor ?? 'usuário removido') ?> · nota de recomendação <?= e($c->nps) ?>
                            </span>
                            <span class="text-muted small"><?= e(date_br($c->criadoEm, 'd/m/Y H:i')) ?></span>
                        </div>
                        <div class="mt-1"><?= nl2br(e($c->comentario)) ?></div>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
<?php endif; ?>

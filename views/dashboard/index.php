<?php
/**
 * @var \ContabilFlow\Services\Indicadores $indicadores
 * @var \ContabilFlow\Models\Page<\ContabilFlow\Models\Obrigacao> $semana
 * @var \Closure(\ContabilFlow\Models\Obrigacao): \ContabilFlow\Models\Semaforo $cor
 * @var string $userName
 */
use ContabilFlow\Models\Semaforo;

$cards = [
    [Semaforo::Preto, $indicadores->vencidas, 'Vencidas'],
    [Semaforo::Vermelho, $indicadores->ate2dias, 'Vencem em até 2 dias'],
    [Semaforo::Amarelo, $indicadores->ate7dias, 'Vencem em 3 a 7 dias'],
    [Semaforo::Verde, $indicadores->noPrazo, 'No prazo (mais de 7 dias)'],
];
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0">Painel</h1>
    <a href="/obrigacoes/nova" class="btn btn-primary">Nova guia</a>
</div>

<div class="row g-3 mb-4">
    <?php foreach ($cards as [$semaforo, $total, $label]): ?>
        <div class="col-6 col-lg-3">
            <a href="/obrigacoes?semaforo=<?= e($semaforo->value) ?>" class="text-decoration-none">
                <div class="card shadow-sm h-100">
                    <div class="card-body">
                        <span class="badge <?= e($semaforo->badgeClass()) ?> mb-2"><?= e($semaforo->label()) ?></span>
                        <div class="display-6 fw-semibold text-body"><?= e($total) ?></div>
                        <div class="text-muted small"><?= e($label) ?></div>
                    </div>
                </div>
            </a>
        </div>
    <?php endforeach; ?>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-6">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted small">Valor total em aberto</div>
                <div class="h3 mb-0"><?= e(money_br($indicadores->valorPendente)) ?></div>
                <div class="text-muted small"><?= e($indicadores->totalEmAberto()) ?> guia(s) pendentes ou enviadas</div>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <a href="/obrigacoes?status=paga" class="text-decoration-none">
            <div class="card shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small">Pagas neste mês</div>
                    <div class="h3 mb-0 text-body"><?= e($indicadores->pagasMes) ?></div>
                    <div class="text-muted small">guia(s) quitadas desde o dia 1º</div>
                </div>
            </div>
        </a>
    </div>
</div>

<div class="d-flex justify-content-between align-items-center mb-2">
    <h2 class="h5 mb-0">Vencendo esta semana</h2>
    <span class="text-muted small">de hoje até daqui a 7 dias</span>
</div>

<?php if ($semana->items === []): ?>
    <div class="card card-body text-center text-muted">Nenhuma guia vence nos próximos 7 dias.</div>
<?php else: ?>
    <div class="card shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr><th>Prazo</th><th>Empresa</th><th>Tipo</th><th>Vencimento</th><th class="text-end">Valor</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach ($semana->items as $guia): ?>
                    <?php $semaforo = $cor($guia); ?>
                    <tr>
                        <td><span class="badge <?= e($semaforo->badgeClass()) ?>"><?= e($semaforo->label()) ?></span></td>
                        <td><a href="/empresas/<?= e($guia->empresaId) ?>"><?= e($guia->empresaNome) ?></a></td>
                        <td><?= e($guia->tipo) ?></td>
                        <td><?= e(date_br($guia->vencimento)) ?></td>
                        <td class="text-end"><?= e(money_br($guia->valor)) ?></td>
                        <td class="text-end">
                            <a href="/obrigacoes/<?= e($guia->id) ?>" class="btn btn-sm btn-outline-secondary">Abrir</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php if ($semana->total > count($semana->items)): ?>
        <div class="mt-2">
            <a href="/obrigacoes?semaforo=vermelho">Ver todas as <?= e($semana->total) ?> guias da semana</a>
        </div>
    <?php endif; ?>
<?php endif; ?>

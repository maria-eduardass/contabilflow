<?php
/**
 * @var \ContabilFlow\Models\Obrigacao $obrigacao
 * @var \ContabilFlow\Models\Semaforo $cor
 * @var list<array{status: string, label: string, class: string, confirm: string|null}> $acoes
 * @var string|null $message
 * @var string $csrfField
 */
use ContabilFlow\Models\ObrigacaoStatus;

$paga = $obrigacao->status === ObrigacaoStatus::Paga;
?>
<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="d-flex justify-content-between align-items-start mb-3">
            <div>
                <h1 class="h3 mb-1"><?= e($obrigacao->tipo) ?> · <?= e(date_br($obrigacao->competencia, 'm/Y')) ?></h1>
                <span class="badge <?= e($cor->badgeClass()) ?>"><?= e($cor->label()) ?></span>
                <span class="badge text-bg-light border"><?= e(ucfirst($obrigacao->status->value)) ?></span>
            </div>
            <a href="/obrigacoes" class="btn btn-outline-secondary btn-sm">Voltar</a>
        </div>

        <?php if ($message !== null): ?>
            <div class="alert alert-danger" role="alert"><?= e($message) ?></div>
        <?php endif; ?>

        <div class="card shadow-sm mb-3">
            <dl class="row mb-0 card-body">
                <dt class="col-sm-4">Empresa</dt>
                <dd class="col-sm-8"><a href="/empresas/<?= e($obrigacao->empresaId) ?>"><?= e($obrigacao->empresaNome) ?></a></dd>
                <dt class="col-sm-4">Descrição</dt>
                <dd class="col-sm-8"><?= e($obrigacao->descricao ?? '—') ?></dd>
                <dt class="col-sm-4">Competência</dt>
                <dd class="col-sm-8"><?= e(date_br($obrigacao->competencia, 'm/Y')) ?></dd>
                <dt class="col-sm-4">Vencimento</dt>
                <dd class="col-sm-8"><?= e(date_br($obrigacao->vencimento)) ?></dd>
                <dt class="col-sm-4">Valor</dt>
                <dd class="col-sm-8"><?= e(money_br($obrigacao->valor)) ?></dd>
                <dt class="col-sm-4">Enviada em</dt>
                <dd class="col-sm-8"><?= e(date_br($obrigacao->enviadaEm, 'd/m/Y H:i')) ?></dd>
                <dt class="col-sm-4">Paga em</dt>
                <dd class="col-sm-8"><?= e(date_br($obrigacao->pagaEm, 'd/m/Y H:i')) ?></dd>
                <dt class="col-sm-4">Observações</dt>
                <dd class="col-sm-8"><?= nl2br(e($obrigacao->observacoes ?? '—')) ?></dd>
            </dl>
        </div>

        <div class="d-flex flex-wrap gap-2">
            <?php foreach ($acoes as $acao): ?>
                <form method="post" action="/obrigacoes/<?= e($obrigacao->id) ?>/status"<?= $acao['confirm'] !== null ? ' data-confirm="' . e($acao['confirm']) . '"' : '' ?>>
                    <?= $csrfField ?>
                    <input type="hidden" name="_method" value="PATCH">
                    <input type="hidden" name="status" value="<?= e($acao['status']) ?>">
                    <input type="hidden" name="versao" value="<?= e($obrigacao->versao) ?>">
                    <button type="submit" class="btn <?= e($acao['class']) ?>"><?= e($acao['label']) ?></button>
                </form>
            <?php endforeach; ?>

            <?php if (!$paga): ?>
                <a href="/obrigacoes/<?= e($obrigacao->id) ?>/editar" class="btn btn-primary">Editar</a>
                <form method="post" action="/obrigacoes/<?= e($obrigacao->id) ?>" data-confirm="Excluir esta guia? Esta ação não pode ser desfeita.">
                    <?= $csrfField ?>
                    <input type="hidden" name="_method" value="DELETE">
                    <button type="submit" class="btn btn-outline-danger">Excluir</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>

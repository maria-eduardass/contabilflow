<?php
/**
 * @var \ContabilFlow\Models\Empresa $empresa
 * @var string|null $responsavel
 * @var string|null $message
 * @var string $csrfField
 */
use ContabilFlow\Models\Empresa;
?>
<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="d-flex justify-content-between align-items-start mb-3">
            <div>
                <h1 class="h3 mb-1"><?= e($empresa->razaoSocial) ?></h1>
                <span class="badge <?= $empresa->ativo ? 'text-bg-success' : 'text-bg-secondary' ?>">
                    <?= $empresa->ativo ? 'Ativa' : 'Inativa' ?>
                </span>
            </div>
            <a href="/empresas" class="btn btn-outline-secondary btn-sm">Voltar</a>
        </div>

        <?php if ($message !== null): ?>
            <div class="alert alert-danger" role="alert"><?= e($message) ?></div>
        <?php endif; ?>

        <div class="card shadow-sm mb-3">
            <dl class="row mb-0 card-body">
                <dt class="col-sm-4">Nome fantasia</dt>
                <dd class="col-sm-8"><?= e($empresa->nomeFantasia ?? '—') ?></dd>
                <dt class="col-sm-4">CNPJ</dt>
                <dd class="col-sm-8"><?= e(cnpj_mask($empresa->cnpj)) ?></dd>
                <dt class="col-sm-4">Regime</dt>
                <dd class="col-sm-8"><?= e(Empresa::REGIME_LABELS[$empresa->regime] ?? $empresa->regime) ?></dd>
                <dt class="col-sm-4">E-mail</dt>
                <dd class="col-sm-8"><?= e($empresa->email ?? '—') ?></dd>
                <dt class="col-sm-4">WhatsApp</dt>
                <dd class="col-sm-8"><?= e($empresa->whatsapp !== null ? phone_mask($empresa->whatsapp) : '—') ?></dd>
                <dt class="col-sm-4">Responsável</dt>
                <dd class="col-sm-8"><?= e($responsavel ?? '—') ?></dd>
            </dl>
        </div>

        <div class="d-flex gap-2">
            <a href="/empresas/<?= e($empresa->id) ?>/editar" class="btn btn-primary">Editar</a>
            <form method="post" action="/empresas/<?= e($empresa->id) ?>" data-confirm="Excluir esta empresa? Esta ação não pode ser desfeita.">
                <?= $csrfField ?>
                <input type="hidden" name="_method" value="DELETE">
                <button type="submit" class="btn btn-outline-danger">Excluir</button>
            </form>
        </div>
    </div>
</div>

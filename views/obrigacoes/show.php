<?php
/**
 * @var \ContabilFlow\Models\Obrigacao $obrigacao
 * @var \ContabilFlow\Models\Semaforo $cor
 * @var list<array{status: string, label: string, class: string, confirm: string|null}> $acoes
 * @var string|null $message
 * @var string $csrfField
 * @var list<\ContabilFlow\Models\Lembrete> $lembretes
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

        <?php if ($obrigacao->status->isActive()): ?>
            <div class="card shadow-sm mt-3">
                <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <div>
                        <div class="fw-semibold">Lembrete para o cliente</div>
                        <div class="text-muted small">Gera a mensagem pronta para enviar por WhatsApp, e-mail ou copiar.</div>
                    </div>
                    <button type="button" class="btn btn-success" data-lembrete-abrir
                            data-preview-url="/api/obrigacoes/<?= e($obrigacao->id) ?>/lembrete"
                            data-registro-url="/api/obrigacoes/<?= e($obrigacao->id) ?>/lembretes">Gerar lembrete</button>
                </div>
            </div>

            <div class="modal fade" id="lembrete-modal" tabindex="-1" aria-labelledby="lembrete-titulo" aria-hidden="true">
                <div class="modal-dialog modal-lg">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h2 class="modal-title h5" id="lembrete-titulo">Lembrete para o cliente</h2>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                        </div>
                        <div class="modal-body">
                            <div class="alert alert-danger d-none" role="alert" data-lembrete-erro></div>
                            <div class="mb-2"><span class="badge" data-lembrete-badge></span></div>
                            <label for="lembrete-texto" class="form-label">Mensagem (você pode editar antes de enviar)</label>
                            <textarea id="lembrete-texto" class="form-control" rows="10" maxlength="2000" data-lembrete-texto></textarea>
                            <div class="form-text text-success d-none" data-lembrete-ok>Envio registrado no histórico da guia.</div>
                        </div>
                        <div class="modal-footer flex-wrap justify-content-start">
                            <button type="button" class="btn btn-outline-secondary" data-lembrete-canal="copiado">Copiar texto</button>
                            <a class="btn btn-success disabled" href="#" target="_blank" rel="noopener noreferrer"
                               aria-disabled="true" tabindex="-1" data-lembrete-canal="whatsapp">Abrir WhatsApp</a>
                            <a class="btn btn-primary disabled" href="#" aria-disabled="true" tabindex="-1"
                               data-lembrete-canal="email">Abrir e-mail</a>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <h2 class="h5 mt-4 mb-2">Histórico de lembretes</h2>
        <?php if ($lembretes === []): ?>
            <div class="card card-body text-center text-muted">Nenhum lembrete gerado para esta guia.</div>
        <?php else: ?>
            <div class="card shadow-sm">
                <ul class="list-group list-group-flush">
                    <?php foreach ($lembretes as $lembrete): ?>
                        <li class="list-group-item">
                            <div class="d-flex justify-content-between">
                                <span><span class="badge text-bg-light border"><?= e($lembrete->canal->label()) ?></span>
                                    por <?= e($lembrete->usuarioNome) ?></span>
                                <span class="text-muted small"><?= e(date_br($lembrete->criadoEm, 'd/m/Y H:i')) ?></span>
                            </div>
                            <details class="mt-1">
                                <summary class="small text-muted">Ver mensagem</summary>
                                <div class="small mt-1"><?= nl2br(e($lembrete->mensagem)) ?></div>
                            </details>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
    </div>
</div>

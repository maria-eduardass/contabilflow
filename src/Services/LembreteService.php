<?php

declare(strict_types=1);

namespace ContabilFlow\Services;

use ContabilFlow\Models\Empresa;
use ContabilFlow\Models\InvalidStateException;
use ContabilFlow\Models\Obrigacao;
use ContabilFlow\Models\Semaforo;

/**
 * Monta o lembrete de uma guia para o empresário: texto, link do WhatsApp e mailto.
 *
 * Por quê:
 * - O tom muda com a cor do semáforo (vencida, vence hoje, vence em poucos
 *   dias, no prazo), e o número de dias vem da mesma conta do semáforo.
 * - O envio é manual: o link wa.me (click-to-chat, gratuito) abre a conversa
 *   com o texto pronto; não usamos API paga. O texto vai com rawurlencode,
 *   então acentos, quebras de linha e & ou # não quebram nem injetam
 *   parâmetros na URL.
 * - Sem WhatsApp ou e-mail cadastrado, o link vem null e a tela desabilita o botão.
 * - Guia paga ou cancelada não gera lembrete.
 */
final class LembreteService
{
    public const MAX_MESSAGE_LENGTH = 2000;

    public function __construct(private SemaforoService $semaforo)
    {
    }

    /** @throws InvalidStateException guia paga ou cancelada */
    public function montar(Obrigacao $guia, Empresa $empresa): LembretePronto
    {
        if (!$guia->status->isActive()) {
            throw new InvalidStateException('Guia paga ou cancelada não precisa de lembrete.');
        }

        $cor = $this->semaforo->classificar($guia);
        $dias = SemaforoService::diasAte($guia->vencimento, $this->semaforo->hoje());
        $mensagem = $this->texto($guia, $empresa, $cor, $dias);

        return new LembretePronto(
            $mensagem,
            $empresa->whatsapp === null
                ? null
                : 'https://wa.me/' . $empresa->whatsapp . '?text=' . rawurlencode($mensagem),
            $empresa->email === null
                ? null
                : 'mailto:' . str_replace('%40', '@', rawurlencode($empresa->email))
                    . '?subject=' . rawurlencode($this->assunto($guia, $cor))
                    . '&body=' . rawurlencode($mensagem),
            $cor
        );
    }

    private function texto(Obrigacao $guia, Empresa $empresa, Semaforo $cor, int $dias): string
    {
        $nome = $empresa->nomeFantasia ?? $empresa->razaoSocial;
        $vencimento = date_br($guia->vencimento);

        $abertura = match ($cor) {
            Semaforo::Preto => sprintf(
                'Identificamos que a guia abaixo está vencida desde %s (%d %s de atraso). '
                . 'Vamos regularizar o quanto antes?',
                $vencimento,
                abs($dias),
                abs($dias) === 1 ? 'dia' : 'dias'
            ),
            Semaforo::Vermelho => match ($dias) {
                0 => 'Atenção: a guia abaixo vence HOJE.',
                1 => 'Atenção: a guia abaixo vence amanhã.',
                default => "Atenção: a guia abaixo vence em {$dias} dias.",
            },
            Semaforo::Amarelo => "Lembrete: a guia abaixo vence em {$dias} dias.",
            default => "Passando para lembrar da guia abaixo, que vence em {$vencimento}.",
        };

        $linhas = [
            "Olá, {$nome}!",
            '',
            $abertura,
            '',
            'Guia: ' . $guia->tipo . ($guia->descricao !== null ? ' - ' . $guia->descricao : ''),
            'Competência: ' . date_br($guia->competencia, 'm/Y'),
            'Vencimento: ' . $vencimento,
        ];
        if ($guia->valor !== null) {
            $linhas[] = 'Valor: ' . money_br($guia->valor);
        }
        array_push($linhas, '', 'Qualquer dúvida, é só falar. Obrigado!');

        return implode("\n", $linhas);
    }

    private function assunto(Obrigacao $guia, Semaforo $cor): string
    {
        $quando = $cor === Semaforo::Preto ? 'vencida em ' : 'vence em ';

        return "Lembrete: {$guia->tipo} {$quando}" . date_br($guia->vencimento);
    }
}

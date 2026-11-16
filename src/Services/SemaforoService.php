<?php

declare(strict_types=1);

namespace ContabilFlow\Services;

use ContabilFlow\Core\Clock;
use ContabilFlow\Models\FaixaVencimento;
use ContabilFlow\Models\Obrigacao;
use ContabilFlow\Models\ObrigacaoStatus;
use ContabilFlow\Models\Semaforo;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Semáforo de prazos: preto (vencida), vermelho (0 a 2 dias), amarelo (3 a 7),
 * verde (mais de 7) e cinza (paga ou cancelada).
 *
 * Por quê:
 * - A conta é em dias de calendário (data sem hora), então 23:59 e 00:01 do
 *   mesmo dia dão a mesma cor. Os dias são contados em UTC para o horário de
 *   verão não encurtar nem alongar o dia.
 * - classificar() (PHP) e faixa() (SQL) usam as mesmas constantes, então a cor
 *   mostrada na tela e o filtro por cor nunca discordam; um teste varre 50 dias
 *   para garantir isso.
 * - Guia "enviada" ainda está em aberto: o prazo continua valendo até pagar.
 */
final class SemaforoService
{
    public const VERMELHO_ATE_DIAS = 2;
    public const AMARELO_ATE_DIAS = 7;

    public function __construct(private Clock $clock)
    {
    }

    /** Hoje à meia-noite, no fuso do relógio. */
    public function hoje(): DateTimeImmutable
    {
        return $this->clock->now()->setTime(0, 0);
    }

    public function classificar(Obrigacao $obrigacao): Semaforo
    {
        return self::classificarEm($obrigacao->status, $obrigacao->vencimento, $this->hoje());
    }

    public static function classificarEm(
        ObrigacaoStatus $status,
        DateTimeImmutable $vencimento,
        DateTimeImmutable $hoje,
    ): Semaforo {
        if (!$status->isActive()) {
            return Semaforo::Cinza;
        }

        $dias = self::diasAte($vencimento, $hoje);

        return match (true) {
            $dias < 0 => Semaforo::Preto,
            $dias <= self::VERMELHO_ATE_DIAS => Semaforo::Vermelho,
            $dias <= self::AMARELO_ATE_DIAS => Semaforo::Amarelo,
            default => Semaforo::Verde,
        };
    }

    /** A cor como intervalo de vencimento, para filtrar no banco sem calcular linha a linha em PHP. */
    public function faixa(Semaforo $cor): FaixaVencimento
    {
        $hoje = $this->hoje();
        $dia = static fn(int $offset): DateTimeImmutable => $hoje->modify(sprintf('%+d days', $offset));

        return match ($cor) {
            Semaforo::Preto => new FaixaVencimento(true, null, $dia(-1)),
            Semaforo::Vermelho => new FaixaVencimento(true, $dia(0), $dia(self::VERMELHO_ATE_DIAS)),
            Semaforo::Amarelo => new FaixaVencimento(
                true,
                $dia(self::VERMELHO_ATE_DIAS + 1),
                $dia(self::AMARELO_ATE_DIAS)
            ),
            Semaforo::Verde => new FaixaVencimento(true, $dia(self::AMARELO_ATE_DIAS + 1), null),
            Semaforo::Cinza => new FaixaVencimento(false),
        };
    }

    private static function diasAte(DateTimeImmutable $vencimento, DateTimeImmutable $hoje): int
    {
        $utc = new DateTimeZone('UTC');
        $due = new DateTimeImmutable($vencimento->format('Y-m-d'), $utc);
        $today = new DateTimeImmutable($hoje->format('Y-m-d'), $utc);

        return (int) $today->diff($due)->format('%r%a');
    }
}

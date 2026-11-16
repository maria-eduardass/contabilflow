<?php

declare(strict_types=1);

namespace ContabilFlow\Tests\Support;

use ContabilFlow\Models\ObrigacaoDados;
use ContabilFlow\Models\ObrigacaoRepositoryInterface;
use ContabilFlow\Models\ObrigacaoStatus;
use DateTimeImmutable;

/**
 * Cenário do dashboard com hoje = 16/11/2026, carregado só pela interface do
 * repositório. Serve ao repositório real (SQL) e ao falso, então os dois
 * precisam dar os mesmos números.
 *
 * Esperado: vencidas 3, até 2 dias 2, 3 a 7 dias 2, no prazo 2, pagas no mês 2,
 * valor em aberto 900,00 e 4 guias vencendo na semana.
 */
final class DashboardScenario
{
    public const HOJE = '2026-11-16 10:00:00';

    /** @var list<array{0: string, 1: string, 2: string, 3: string, 4?: string}> tipo, competência, vencimento, status, data do pagamento */
    private const ROWS = [
        ['DAS', '2026-10-01', '2026-11-10', 'pendente'],      // preto
        ['DARF', '2026-10-01', '2026-11-15', 'pendente'],     // preto (venceu ontem)
        ['INSS', '2026-10-01', '2026-11-01', 'enviada'],      // preto (enviada ainda conta)
        ['ISS', '2026-10-01', '2026-11-16', 'pendente'],      // vermelho (hoje)
        ['FGTS', '2026-10-01', '2026-11-18', 'enviada'],      // vermelho (+2)
        ['ICMS', '2026-10-01', '2026-11-19', 'pendente'],     // amarelo (+3)
        ['DCTFWEB', '2026-10-01', '2026-11-23', 'pendente'],  // amarelo (+7)
        ['OUTRA', '2026-10-01', '2026-11-24', 'pendente'],    // verde (+8)
        ['OUTRA', '2026-09-01', '2026-12-31', 'pendente'],    // verde
        ['DAS', '2026-08-01', '2026-11-10', 'paga', '2026-11-05 09:00:00'],   // paga no mês
        ['DARF', '2026-08-01', '2026-11-10', 'paga', '2026-11-30 23:59:59'],  // paga no mês (limite)
        ['DAS', '2026-07-01', '2026-10-10', 'paga', '2026-10-31 23:59:59'],   // paga em outubro
        ['DARF', '2026-07-01', '2026-10-10', 'paga', '2026-12-01 00:00:00'],  // fora do mês (limite)
        ['DAS', '2026-06-01', '2026-11-10', 'cancelada'],     // cinza
    ];

    public static function load(ObrigacaoRepositoryInterface $repo, int $empresaId, int $userId): void
    {
        foreach (self::ROWS as $row) {
            [$tipo, $competencia, $vencimento, $status] = $row;
            $id = $repo->create(new ObrigacaoDados(
                $empresaId,
                $tipo,
                $tipo === 'OUTRA' ? 'Descrição' : null,
                new DateTimeImmutable($competencia),
                new DateTimeImmutable($vencimento),
                '100.00',
                null
            ), $userId);

            $now = new DateTimeImmutable('2026-11-01 08:00:00');
            if ($status === 'enviada') {
                $repo->changeStatus($id, 1, ObrigacaoStatus::Enviada, $now);
            } elseif ($status === 'paga') {
                $repo->changeStatus($id, 1, ObrigacaoStatus::Paga, new DateTimeImmutable($row[4] ?? '2026-11-01'));
            } elseif ($status === 'cancelada') {
                $repo->changeStatus($id, 1, ObrigacaoStatus::Cancelada, $now);
            }
        }
    }
}

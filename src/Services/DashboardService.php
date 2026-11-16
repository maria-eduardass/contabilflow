<?php

declare(strict_types=1);

namespace ContabilFlow\Services;

use ContabilFlow\Models\FaixaVencimento;
use ContabilFlow\Models\Obrigacao;
use ContabilFlow\Models\ObrigacaoFiltro;
use ContabilFlow\Models\ObrigacaoRepositoryInterface;
use ContabilFlow\Models\Page;
use ContabilFlow\Models\Semaforo;

/**
 * Dados do painel inicial.
 *
 * Por quê:
 * - Uma única consulta agregada traz todos os indicadores (usa o índice
 *   status + vencimento) e a data vem do SemaforoService, então os números
 *   batem com as cores mostradas e com os filtros da lista de guias.
 * - "Esta semana" é do vermelho (hoje) até o fim do amarelo (hoje + 7 dias),
 *   derivado das mesmas faixas do semáforo, sem constantes duplicadas.
 * - "Pagas no mês" conta pela data do pagamento, dentro do mês corrente.
 */
final class DashboardService
{
    public function __construct(
        private ObrigacaoRepositoryInterface $obrigacoes,
        private SemaforoService $semaforo,
    ) {
    }

    public function indicadores(): Indicadores
    {
        $faixas = [];
        foreach ([Semaforo::Preto, Semaforo::Vermelho, Semaforo::Amarelo, Semaforo::Verde] as $cor) {
            $faixas[$cor->value] = $this->semaforo->faixa($cor);
        }
        $hoje = $this->semaforo->hoje();

        $resumo = $this->obrigacoes->resumo(
            $faixas,
            $hoje->modify('first day of this month'),
            $hoje->modify('first day of next month')
        );

        return new Indicadores(
            $resumo->porCor[Semaforo::Preto->value] ?? 0,
            $resumo->porCor[Semaforo::Vermelho->value] ?? 0,
            $resumo->porCor[Semaforo::Amarelo->value] ?? 0,
            $resumo->porCor[Semaforo::Verde->value] ?? 0,
            $resumo->pagasNoPeriodo,
            $resumo->valorEmAberto
        );
    }

    /**
     * Guias em aberto que vencem de hoje até daqui a 7 dias, das mais próximas.
     *
     * @return Page<Obrigacao>
     */
    public function vencendoNaSemana(): Page
    {
        $faixa = new FaixaVencimento(
            true,
            $this->semaforo->faixa(Semaforo::Vermelho)->de,
            $this->semaforo->faixa(Semaforo::Amarelo)->ate
        );

        return $this->obrigacoes->search(new ObrigacaoFiltro(faixa: $faixa, sort: 'vencimento'));
    }
}

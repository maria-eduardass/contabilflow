<?php

declare(strict_types=1);

namespace ContabilFlow\Models;

/** Resultado consolidado das avaliações: geral, por perfil e os comentários mais recentes. */
final class ResumoAvaliacoes
{
    /**
     * @param array<string, EstatisticasAvaliacao> $porPerfil chave = perfil (admin, contador)
     * @param list<ComentarioAvaliacao> $comentarios do mais recente para o mais antigo
     */
    public function __construct(
        public readonly EstatisticasAvaliacao $geral,
        public readonly array $porPerfil,
        public readonly array $comentarios,
    ) {
    }
}

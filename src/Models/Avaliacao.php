<?php

declare(strict_types=1);

namespace ContabilFlow\Models;

/** Perguntas e escala da avaliação de usabilidade. */
final class Avaliacao
{
    public const PERGUNTAS = [
        'q1' => 'O sistema é fácil de usar.',
        'q2' => 'As cores do semáforo de prazos são claras.',
        'q3' => 'O lembrete rápido ajuda no meu dia a dia.',
        'q4' => 'O sistema é rápido para as tarefas do dia a dia.',
        'q5' => 'O sistema poderia substituir minha planilha ou meu WhatsApp atuais.',
    ];

    public const ESCALA = [
        1 => 'Discordo totalmente',
        2 => 'Discordo',
        3 => 'Neutro',
        4 => 'Concordo',
        5 => 'Concordo totalmente',
    ];

    public const PERGUNTA_NPS = 'De 0 a 10, qual a chance de você recomendar o ContábilFlow a um colega?';
    public const MAX_COMENTARIO = 1000;
    public const MAX_COMENTARIOS_NO_RESUMO = 50;
}

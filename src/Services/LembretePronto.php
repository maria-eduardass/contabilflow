<?php

declare(strict_types=1);

namespace ContabilFlow\Services;

use ContabilFlow\Models\Semaforo;

/** Lembrete montado: texto e os links de entrega (null quando a empresa não tem o contato). */
final class LembretePronto
{
    public function __construct(
        public readonly string $mensagem,
        public readonly ?string $whatsappUrl,
        public readonly ?string $mailtoUrl,
        public readonly Semaforo $semaforo,
    ) {
    }

    /**
     * @return array{mensagem: string, whatsappUrl: ?string, mailtoUrl: ?string, semaforo: string,
     *     semaforoLabel: string, semaforoBadge: string}
     */
    public function toArray(): array
    {
        return [
            'mensagem' => $this->mensagem,
            'whatsappUrl' => $this->whatsappUrl,
            'mailtoUrl' => $this->mailtoUrl,
            'semaforo' => $this->semaforo->value,
            'semaforoLabel' => $this->semaforo->label(),
            'semaforoBadge' => $this->semaforo->badgeClass(),
        ];
    }
}

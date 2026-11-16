<?php

declare(strict_types=1);

namespace ContabilFlow\Controllers;

use ContabilFlow\Core\Clock;
use ContabilFlow\Core\Request;
use ContabilFlow\Core\Response;
use ContabilFlow\Core\Session;
use ContabilFlow\Models\EmpresaRepositoryInterface;
use ContabilFlow\Models\InvalidStateException;
use ContabilFlow\Models\LembreteCanal;
use ContabilFlow\Models\LembreteRepositoryInterface;
use ContabilFlow\Models\ObrigacaoRepositoryInterface;
use ContabilFlow\Services\LembreteService;
use ContabilFlow\Validation\Validator;

/**
 * API JSON do lembrete rápido: prévia do texto e registro do envio.
 *
 * Por quê: o contador abre o WhatsApp ou o e-mail por conta própria, então o
 * registro acontece DEPOIS do envio e, se falhar, a tela só avisa. Erros usam
 * o formato {"error": {"code", "message", "fields"?}} do resto da API.
 */
final class LembreteController
{
    public function __construct(
        private ObrigacaoRepositoryInterface $obrigacoes,
        private EmpresaRepositoryInterface $empresas,
        private LembreteRepositoryInterface $lembretes,
        private LembreteService $service,
        private Clock $clock,
        private Session $session,
        private Validator $validator,
    ) {
    }

    /** @param array<string, string> $params */
    public function preview(Request $request, array $params = []): Response
    {
        $guia = $this->obrigacoes->find((int) ($params['id'] ?? 0));
        $empresa = $guia === null ? null : $this->empresas->find($guia->empresaId);
        if ($guia === null || $empresa === null) {
            return $this->error('NOT_FOUND', 'Guia não encontrada.', 404);
        }

        try {
            return Response::json($this->service->montar($guia, $empresa)->toArray());
        } catch (InvalidStateException $e) {
            return $this->error('NOT_REMINDABLE', $e->getMessage(), 422);
        }
    }

    /** @param array<string, string> $params */
    public function registrar(Request $request, array $params = []): Response
    {
        $guia = $this->obrigacoes->find((int) ($params['id'] ?? 0));
        if ($guia === null) {
            return $this->error('NOT_FOUND', 'Guia não encontrada.', 404);
        }

        $result = $this->validator->validate($request->post, [
            'canal' => ['required', 'in:' . implode(',', LembreteCanal::values())],
            'mensagem' => ['required', 'maxLength:' . LembreteService::MAX_MESSAGE_LENGTH],
        ]);
        if ($result->fails()) {
            return $this->error('VALIDATION_FAILED', 'Dados inválidos.', 422, $result->errors);
        }
        if (!$guia->status->isActive()) {
            return $this->error('NOT_REMINDABLE', 'Guia paga ou cancelada não precisa de lembrete.', 422);
        }

        $agora = $this->clock->now();
        $id = $this->lembretes->create(
            $guia->id,
            (int) $this->session->get('user_id', 0),
            LembreteCanal::from((string) $result->data['canal']),
            (string) $result->data['mensagem'],
            $agora
        );

        return Response::json([
            'id' => $id,
            'canal' => $result->data['canal'],
            'criadoEm' => $agora->format('Y-m-d H:i:s'),
        ], 201);
    }

    /** @param array<string, string> $fields */
    private function error(string $code, string $message, int $status, array $fields = []): Response
    {
        $error = ['code' => $code, 'message' => $message];
        if ($fields !== []) {
            $error['fields'] = $fields;
        }

        return Response::json(['error' => $error], $status);
    }
}

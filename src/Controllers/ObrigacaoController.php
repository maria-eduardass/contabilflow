<?php

declare(strict_types=1);

namespace ContabilFlow\Controllers;

use ContabilFlow\Core\Clock;
use ContabilFlow\Core\Csrf;
use ContabilFlow\Core\Request;
use ContabilFlow\Core\Response;
use ContabilFlow\Core\Session;
use ContabilFlow\Core\View;
use ContabilFlow\Models\ConflictException;
use ContabilFlow\Models\DuplicateException;
use ContabilFlow\Models\EmpresaRepositoryInterface;
use ContabilFlow\Models\InvalidStateException;
use ContabilFlow\Models\Obrigacao;
use ContabilFlow\Models\ObrigacaoDados;
use ContabilFlow\Models\ObrigacaoFiltro;
use ContabilFlow\Models\ObrigacaoRepositoryInterface;
use ContabilFlow\Models\ObrigacaoStatus;
use ContabilFlow\Models\RecordNotFoundException;
use ContabilFlow\Models\Semaforo;
use ContabilFlow\Models\StaleVersionException;
use ContabilFlow\Services\SemaforoService;
use ContabilFlow\Validation\Validator;
use DateTimeImmutable;

/**
 * Telas de guias (obrigações): listar, ver, criar, editar, mudar status e excluir.
 *
 * Por quê:
 * - Só os campos de RULES chegam ao banco (whitelist do Validator); status,
 *   datas de envio/pagamento e criador nunca vêm do formulário de edição.
 * - 422 = dado inválido ou ação não permitida no estado atual; 409 = conflito
 *   (guia duplicada, alguém alterou antes). Sempre com a tela re-renderizada.
 * - A empresa de uma guia não muda na edição, e só empresas ativas recebem
 *   guias novas.
 */
final class ObrigacaoController
{
    private const FIELDS = ['empresa_id', 'tipo', 'descricao', 'competencia', 'vencimento', 'valor', 'observacoes'];
    private const STATUS_MESSAGES = [
        'enviada' => 'Guia marcada como enviada.',
        'paga' => 'Guia marcada como paga.',
        'cancelada' => 'Guia cancelada.',
        'pendente' => 'Guia reaberta.',
    ];
    private const ACTIONS = [
        'enviada' => ['Marcar como enviada', 'btn-outline-primary', null],
        'paga' => ['Marcar como paga', 'btn-success', null],
        'cancelada' => ['Cancelar guia', 'btn-outline-danger', 'Cancelar esta guia?'],
        'pendente' => ['Reabrir como pendente', 'btn-outline-secondary', null],
    ];
    private const DUPLICATE_MESSAGE = 'Já existe uma guia ativa deste tipo para a empresa nesta competência.';

    public function __construct(
        private ObrigacaoRepositoryInterface $obrigacoes,
        private EmpresaRepositoryInterface $empresas,
        private SemaforoService $semaforo,
        private Clock $clock,
        private View $view,
        private Session $session,
        private Csrf $csrf,
        private Validator $validator,
    ) {
    }

    public function index(Request $request): Response
    {
        $sorts = [];
        foreach (ObrigacaoFiltro::SORTS as $column) {
            $sorts[] = $column;
            $sorts[] = '-' . $column;
        }
        $result = $this->validator->validate($request->query, [
            'q' => ['maxLength:100'],
            'empresa_id' => [],
            'tipo' => ['in:' . implode(',', Obrigacao::TIPOS)],
            'status' => ['in:' . implode(',', $this->statusValues())],
            'semaforo' => ['in:' . implode(',', Semaforo::values())],
            'de' => ['date'],
            'ate' => ['date'],
            'sort' => ['in:' . implode(',', $sorts)],
        ]);
        $errors = $result->errors;
        $data = $result->data;

        $empresaId = $data['empresa_id'] ?? null;
        if ($empresaId !== null && preg_match('/^[1-9][0-9]{0,9}$/', $empresaId) !== 1) {
            $errors['empresa_id'] = 'Empresa inválida';
        }
        $page = $request->query['page'] ?? '1';
        if (!is_string($page) || preg_match('/^[1-9][0-9]{0,5}$/', $page) !== 1) {
            $errors['page'] = 'Página inválida';
        }
        if (($data['de'] ?? null) !== null && ($data['ate'] ?? null) !== null && $data['de'] > $data['ate']) {
            $errors['ate'] = 'A data final não pode ser anterior à inicial';
        }

        if ($errors !== []) {
            return $this->render('obrigacoes/index', $this->listData(new ObrigacaoFiltro(), $errors), 422);
        }

        $filtro = new ObrigacaoFiltro(
            $data['q'] ?? null,
            $empresaId === null ? null : (int) $empresaId,
            $data['tipo'] ?? null,
            isset($data['status']) ? ObrigacaoStatus::from($data['status']) : null,
            isset($data['semaforo']) ? $this->semaforo->faixa(Semaforo::from($data['semaforo'])) : null,
            isset($data['de']) ? new DateTimeImmutable($data['de']) : null,
            isset($data['ate']) ? new DateTimeImmutable($data['ate']) : null,
            (int) $page,
            $data['sort'] ?? 'vencimento'
        );

        return $this->render('obrigacoes/index', $this->listData($filtro, [], [
            'q' => $data['q'] ?? '', 'empresa_id' => $empresaId ?? '', 'tipo' => $data['tipo'] ?? '',
            'status' => $data['status'] ?? '', 'semaforo' => $data['semaforo'] ?? '',
            'de' => $data['de'] ?? '', 'ate' => $data['ate'] ?? '', 'sort' => $data['sort'] ?? 'vencimento',
        ]));
    }

    /** @param array<string, string> $params */
    public function show(Request $request, array $params = []): Response
    {
        $obrigacao = $this->obrigacoes->find((int) ($params['id'] ?? 0));

        return $obrigacao === null ? $this->notFound() : $this->render('obrigacoes/show', $this->showData($obrigacao));
    }

    public function create(Request $request): Response
    {
        $values = $this->blankValues();
        $empresaId = $request->query['empresa_id'] ?? '';
        $values['empresa_id'] = is_string($empresaId) && preg_match('/^[0-9]{1,10}$/', $empresaId) === 1
            ? $empresaId
            : '';

        return $this->form($values, [], null, null);
    }

    public function store(Request $request): Response
    {
        $parsed = $this->parse($request->post, null);
        if ($parsed['errors'] !== []) {
            return $this->form($parsed['old'], $parsed['errors'], null, null, null, 422);
        }

        try {
            $id = $this->obrigacoes->create($parsed['dados'], (int) $this->session->get('user_id', 0));
        } catch (DuplicateException) {
            return $this->form($parsed['old'], ['competencia' => self::DUPLICATE_MESSAGE], null, null, null, 409);
        }

        $this->session->flash('success', 'Guia cadastrada.');

        return Response::redirect('/obrigacoes/' . $id);
    }

    /** @param array<string, string> $params */
    public function edit(Request $request, array $params = []): Response
    {
        $obrigacao = $this->obrigacoes->find((int) ($params['id'] ?? 0));
        if ($obrigacao === null) {
            return $this->notFound();
        }
        if ($obrigacao->status === ObrigacaoStatus::Paga) {
            $this->session->flash('warning', 'Guia já paga não pode ser editada.');

            return Response::redirect('/obrigacoes/' . $obrigacao->id);
        }

        return $this->form($this->valuesOf($obrigacao), [], $obrigacao->id, $obrigacao->versao, null, 200, $obrigacao);
    }

    /** @param array<string, string> $params */
    public function update(Request $request, array $params = []): Response
    {
        $id = (int) ($params['id'] ?? 0);
        $current = $this->obrigacoes->find($id);
        if ($current === null) {
            return $this->notFound();
        }

        $parsed = $this->parse($request->post, $current);
        $versao = $parsed['versao'] ?? $current->versao;
        if ($parsed['errors'] !== []) {
            return $this->form($parsed['old'], $parsed['errors'], $id, $versao, null, 422, $current);
        }

        try {
            $this->obrigacoes->update($id, $versao, $parsed['dados']);
        } catch (DuplicateException) {
            return $this->form(
                $parsed['old'],
                ['competencia' => self::DUPLICATE_MESSAGE],
                $id,
                $versao,
                null,
                409,
                $current
            );
        } catch (StaleVersionException) {
            $fresh = $this->obrigacoes->find($id);
            if ($fresh === null) {
                return $this->notFound();
            }
            $message = 'Esta guia foi alterada por outra pessoa enquanto você editava. '
                . 'Os dados abaixo são os atuais; confira e salve novamente.';

            return $this->form($this->valuesOf($fresh), [], $id, $fresh->versao, $message, 409, $fresh);
        } catch (InvalidStateException $e) {
            return $this->render('obrigacoes/show', ['message' => $e->getMessage()] + $this->showData($current), 422);
        } catch (RecordNotFoundException) {
            return $this->notFound();
        }

        $this->session->flash('success', 'Guia atualizada.');

        return Response::redirect('/obrigacoes/' . $id);
    }

    /** @param array<string, string> $params */
    public function changeStatus(Request $request, array $params = []): Response
    {
        $id = (int) ($params['id'] ?? 0);
        $current = $this->obrigacoes->find($id);
        if ($current === null) {
            return $this->notFound();
        }

        $result = $this->validator->validate($request->post, [
            'status' => ['required', 'in:' . implode(',', $this->statusValues())],
            'versao' => ['required', 'decimal:0,9'],
        ]);
        if ($result->fails()) {
            return $this->render('obrigacoes/show', ['message' => 'Pedido inválido: ' . implode('; ', $result->errors)]
                + $this->showData($current), 422);
        }

        $to = ObrigacaoStatus::from((string) $result->data['status']);
        try {
            $this->obrigacoes->changeStatus($id, (int) $result->data['versao'], $to, $this->clock->now());
        } catch (StaleVersionException) {
            $fresh = $this->obrigacoes->find($id) ?? $current;
            $message = "Esta guia foi alterada por outra pessoa e agora está {$fresh->status->value}. "
                . 'Confira o estado atual antes de continuar.';

            return $this->render('obrigacoes/show', ['message' => $message] + $this->showData($fresh), 409);
        } catch (InvalidStateException $e) {
            return $this->render('obrigacoes/show', ['message' => $e->getMessage()] + $this->showData($current), 422);
        } catch (DuplicateException) {
            return $this->render(
                'obrigacoes/show',
                ['message' => self::DUPLICATE_MESSAGE . ' Cancele ou conclua a outra antes de reabrir esta.']
                    + $this->showData($current),
                409
            );
        } catch (RecordNotFoundException) {
            return $this->notFound();
        }

        $this->session->flash('success', self::STATUS_MESSAGES[$to->value]);

        return Response::redirect('/obrigacoes/' . $id);
    }

    /** @param array<string, string> $params */
    public function destroy(Request $request, array $params = []): Response
    {
        $id = (int) ($params['id'] ?? 0);
        $current = $this->obrigacoes->find($id);
        if ($current === null) {
            return $this->notFound();
        }

        try {
            $deleted = $this->obrigacoes->delete($id);
        } catch (ConflictException $e) {
            return $this->render('obrigacoes/show', ['message' => $e->getMessage()] + $this->showData($current), 409);
        }
        if (!$deleted) {
            return $this->notFound();
        }

        $this->session->flash('success', 'Guia excluída.');

        return Response::redirect('/obrigacoes');
    }

    /**
     * @param array<string, mixed> $post
     * @return array{errors: array<string, string>, old: array<string, mixed>, dados: ObrigacaoDados, versao: ?int}
     */
    private function parse(array $post, ?Obrigacao $current): array
    {
        $rules = [
            'empresa_id' => $current === null ? ['required'] : [],
            'tipo' => ['required', 'in:' . implode(',', Obrigacao::TIPOS)],
            'descricao' => ['maxLength:255'],
            'competencia' => ['required', 'month'],
            'vencimento' => ['required', 'date'],
            'valor' => ['decimal:2,10'],
            'observacoes' => ['maxLength:2000'],
        ];
        if ($current !== null) {
            $rules['versao'] = ['required', 'decimal:0,9'];
        }
        $result = $this->validator->validate($post, $rules);
        $errors = $result->errors;
        $data = $result->data;

        // A empresa só é escolhida na criação (e precisa estar ativa); na edição ela não muda.
        $empresaId = $current?->empresaId;
        if ($current === null && !isset($errors['empresa_id'])) {
            $empresaId = $this->activeCompanyId((string) $data['empresa_id']);
            if ($empresaId === null) {
                $errors['empresa_id'] = 'Empresa inexistente ou inativa';
            }
        }
        if (($data['tipo'] ?? null) === 'OUTRA' && ($data['descricao'] ?? null) === null) {
            $errors['descricao'] = 'Informe a descrição para o tipo Outra';
        }
        if (
            !isset($errors['competencia']) && !isset($errors['vencimento'])
            && isset($data['competencia'], $data['vencimento']) && $data['vencimento'] < $data['competencia']
        ) {
            $errors['vencimento'] = 'O vencimento não pode ser anterior à competência';
        }

        $old = $this->blankValues();
        foreach (self::FIELDS as $field) {
            $old[$field] = is_string($post[$field] ?? null) ? trim($post[$field]) : '';
        }
        if ($current !== null) {
            $old['empresa_id'] = (string) $current->empresaId;
        }

        $dados = new ObrigacaoDados(
            $empresaId ?? 0,
            (string) ($data['tipo'] ?? ''),
            $data['descricao'] ?? null,
            new DateTimeImmutable($data['competencia'] ?? '1970-01-01'),
            new DateTimeImmutable($data['vencimento'] ?? '1970-01-01'),
            $data['valor'] ?? null,
            $data['observacoes'] ?? null
        );

        return [
            'errors' => $errors,
            'old' => $old,
            'dados' => $dados,
            'versao' => isset($data['versao']) ? (int) $data['versao'] : null,
        ];
    }

    private function activeCompanyId(string $value): ?int
    {
        if (preg_match('/^[0-9]{1,10}$/', $value) !== 1) {
            return null;
        }
        $empresa = $this->empresas->find((int) $value);

        return $empresa !== null && $empresa->ativo ? $empresa->id : null;
    }

    /** @return array<string, mixed> */
    private function blankValues(): array
    {
        return [
            'empresa_id' => '', 'tipo' => '', 'descricao' => '', 'competencia' => '',
            'vencimento' => '', 'valor' => '', 'observacoes' => '',
        ];
    }

    /** @return array<string, mixed> */
    private function valuesOf(Obrigacao $o): array
    {
        return [
            'empresa_id' => (string) $o->empresaId, 'tipo' => $o->tipo, 'descricao' => $o->descricao ?? '',
            'competencia' => $o->competencia->format('Y-m'), 'vencimento' => $o->vencimento->format('Y-m-d'),
            'valor' => $o->valor === null ? '' : str_replace('.', ',', $o->valor),
            'observacoes' => $o->observacoes ?? '',
        ];
    }

    /**
     * @param array<string, mixed> $values
     * @param array<string, string> $errors
     */
    private function form(
        array $values,
        array $errors,
        ?int $id,
        ?int $versao,
        ?string $message = null,
        int $status = 200,
        ?Obrigacao $current = null,
    ): Response {
        return $this->render('obrigacoes/form', [
            'title' => $id === null ? 'Nova guia' : 'Editar guia',
            'values' => $values,
            'errors' => $errors,
            'id' => $id,
            'versao' => $versao,
            'message' => $message,
            'empresas' => $this->empresas->listOptions(true),
            'empresaNome' => $current?->empresaNome,
            'tipos' => Obrigacao::TIPOS,
        ], $status);
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, string> $values
     * @return array<string, mixed>
     */
    private function listData(ObrigacaoFiltro $filtro, array $errors, array $values = []): array
    {
        $values += [
            'q' => '', 'empresa_id' => '', 'tipo' => '', 'status' => '', 'semaforo' => '',
            'de' => '', 'ate' => '', 'sort' => 'vencimento',
        ];
        $query = array_filter(
            ['sort' => $values['sort'] === 'vencimento' ? '' : $values['sort']] + $values,
            static fn(string $v): bool => $v !== ''
        );

        return [
            'title' => 'Guias',
            'resultado' => $this->obrigacoes->search($filtro),
            'values' => $values,
            'errors' => $errors,
            'empresas' => $this->empresas->listOptions(),
            'tipos' => Obrigacao::TIPOS,
            'statuses' => ObrigacaoStatus::cases(),
            'semaforos' => Semaforo::cases(),
            'cor' => fn(Obrigacao $o): Semaforo => $this->semaforo->classificar($o),
            'pageUrl' => static fn(int $page): string => '/obrigacoes?'
                . http_build_query($query + ($page > 1 ? ['page' => $page] : [])),
        ];
    }

    /** @return array<string, mixed> */
    private function showData(Obrigacao $o): array
    {
        $acoes = [];
        foreach ($o->status->allowedNext() as $next) {
            [$label, $class, $confirm] = self::ACTIONS[$next->value];
            $acoes[] = ['status' => $next->value, 'label' => $label, 'class' => $class, 'confirm' => $confirm];
        }

        return [
            'title' => $o->tipo . ' · ' . $o->empresaNome,
            'obrigacao' => $o,
            'cor' => $this->semaforo->classificar($o),
            'acoes' => $acoes,
            'message' => null,
        ];
    }

    /** @return list<string> */
    private function statusValues(): array
    {
        return array_map(static fn(ObrigacaoStatus $s): string => $s->value, ObrigacaoStatus::cases());
    }

    private function notFound(): Response
    {
        return $this->render('erro', ['title' => 'Não encontrada', 'message' => 'Guia não encontrada.'], 404);
    }

    /** @param array<string, mixed> $data */
    private function render(string $template, array $data, int $status = 200): Response
    {
        return Response::html($this->view->render($template, $data + [
            'csrfField' => $this->csrf->field(),
            'userName' => (string) $this->session->get('nome', ''),
            'flash' => $this->session->pullFlash(),
        ]), $status);
    }
}

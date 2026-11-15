<?php

declare(strict_types=1);

namespace ContabilFlow\Controllers;

use ContabilFlow\Core\Csrf;
use ContabilFlow\Core\Request;
use ContabilFlow\Core\Response;
use ContabilFlow\Core\Session;
use ContabilFlow\Core\View;
use ContabilFlow\Models\ConflictException;
use ContabilFlow\Models\DuplicateException;
use ContabilFlow\Models\Empresa;
use ContabilFlow\Models\EmpresaDados;
use ContabilFlow\Models\EmpresaFiltro;
use ContabilFlow\Models\EmpresaRepositoryInterface;
use ContabilFlow\Models\RecordNotFoundException;
use ContabilFlow\Models\StaleVersionException;
use ContabilFlow\Models\UserRepositoryInterface;
use ContabilFlow\Validation\Validator;

/**
 * Telas de empresas (listar, ver, criar, editar, excluir). Controller fino:
 * valida, chama o repositório e escolhe a resposta.
 *
 * Por quê:
 * - Só os campos de RULES chegam ao banco (whitelist do Validator).
 * - Erro de validação volta 422 e conflito (CNPJ duplicado, versão
 *   desatualizada, vínculo) volta 409, sempre com o formulário re-renderizado.
 * - Sucesso redireciona (post-redirect-get) com mensagem flash.
 */
final class EmpresaController
{
    private const FIELDS = ['razao_social', 'nome_fantasia', 'cnpj', 'regime', 'email', 'whatsapp', 'responsavel_id'];

    public function __construct(
        private EmpresaRepositoryInterface $empresas,
        private UserRepositoryInterface $users,
        private View $view,
        private Session $session,
        private Csrf $csrf,
        private Validator $validator,
    ) {
    }

    public function index(Request $request): Response
    {
        $sorts = [];
        foreach (EmpresaFiltro::SORTS as $column) {
            $sorts[] = $column;
            $sorts[] = '-' . $column;
        }
        $result = $this->validator->validate($request->query, [
            'q' => ['maxLength:100'],
            'regime' => ['in:' . implode(',', Empresa::REGIMES)],
            'ativo' => ['in:0,1'],
            'sort' => ['in:' . implode(',', $sorts)],
        ]);
        $page = $request->query['page'] ?? '1';
        $errors = $result->errors;
        if (!is_string($page) || preg_match('/^[1-9][0-9]{0,5}$/', $page) !== 1) {
            $errors['page'] = 'Página inválida';
        }

        if ($errors !== []) {
            return $this->render('empresas/index', $this->listData(new EmpresaFiltro(), $errors), 422);
        }

        $ativo = $result->data['ativo'] ?? null;
        $filtro = new EmpresaFiltro(
            $result->data['q'] ?? null,
            $result->data['regime'] ?? null,
            $ativo === null ? null : $ativo === '1',
            (int) $page,
            $result->data['sort'] ?? 'razao_social'
        );

        return $this->render('empresas/index', $this->listData($filtro, []));
    }

    /** @param array<string, string> $params */
    public function show(Request $request, array $params = []): Response
    {
        $empresa = $this->empresas->find((int) ($params['id'] ?? 0));

        return $empresa === null ? $this->notFound() : $this->render('empresas/show', $this->showData($empresa));
    }

    public function create(Request $request): Response
    {
        return $this->form(['ativo' => true] + $this->blankValues(), [], null, null);
    }

    public function store(Request $request): Response
    {
        $parsed = $this->parse($request->post);
        if ($parsed['errors'] !== []) {
            return $this->form($parsed['old'], $parsed['errors'], null, null, null, 422);
        }

        try {
            $id = $this->empresas->create($parsed['dados']);
        } catch (DuplicateException) {
            return $this->form($parsed['old'], ['cnpj' => 'CNPJ já cadastrado'], null, null, null, 409);
        }

        $this->session->flash('success', 'Empresa cadastrada.');

        return Response::redirect('/empresas/' . $id);
    }

    /** @param array<string, string> $params */
    public function edit(Request $request, array $params = []): Response
    {
        $empresa = $this->empresas->find((int) ($params['id'] ?? 0));
        if ($empresa === null) {
            return $this->notFound();
        }

        return $this->form($this->valuesOf($empresa), [], $empresa->id, $empresa->versao);
    }

    /** @param array<string, string> $params */
    public function update(Request $request, array $params = []): Response
    {
        $id = (int) ($params['id'] ?? 0);
        $current = $this->empresas->find($id);
        if ($current === null) {
            return $this->notFound();
        }

        $parsed = $this->parse($request->post, true);
        $versao = $parsed['versao'] ?? $current->versao;
        if ($parsed['errors'] !== []) {
            return $this->form($parsed['old'], $parsed['errors'], $id, $versao, null, 422);
        }

        try {
            $this->empresas->update($id, $versao, $parsed['dados']);
        } catch (DuplicateException) {
            return $this->form($parsed['old'], ['cnpj' => 'CNPJ já cadastrado'], $id, $versao, null, 409);
        } catch (StaleVersionException) {
            // Por quê: mostra o que está salvo agora (e a versão nova) para a pessoa reaplicar a edição.
            $fresh = $this->empresas->find($id);
            if ($fresh === null) {
                return $this->notFound();
            }
            $message = 'Esta empresa foi alterada por outra pessoa enquanto você editava. '
                . 'Os dados abaixo são os atuais; confira e salve novamente.';

            return $this->form($this->valuesOf($fresh), [], $id, $fresh->versao, $message, 409);
        } catch (RecordNotFoundException) {
            return $this->notFound();
        }

        $this->session->flash('success', 'Empresa atualizada.');

        return Response::redirect('/empresas/' . $id);
    }

    /** @param array<string, string> $params */
    public function destroy(Request $request, array $params = []): Response
    {
        $id = (int) ($params['id'] ?? 0);
        $empresa = $this->empresas->find($id);
        if ($empresa === null) {
            return $this->notFound();
        }

        try {
            $deleted = $this->empresas->delete($id);
        } catch (ConflictException) {
            $message = 'Não é possível excluir: a empresa possui obrigações ou usuários vinculados. '
                . 'Desative-a em vez de excluir.';

            return $this->render('empresas/show', ['message' => $message] + $this->showData($empresa), 409);
        }
        if (!$deleted) {
            return $this->notFound();
        }

        $this->session->flash('success', 'Empresa excluída.');

        return Response::redirect('/empresas');
    }

    /**
     * @param array<string, mixed> $post
     * @return array{errors: array<string, string>, old: array<string, mixed>, dados: EmpresaDados, versao: ?int}
     */
    private function parse(array $post, bool $withVersion = false): array
    {
        $rules = [
            'razao_social' => ['required', 'maxLength:180'],
            'nome_fantasia' => ['maxLength:180'],
            'cnpj' => ['required', 'cnpj'],
            'regime' => ['required', 'in:' . implode(',', Empresa::REGIMES)],
            'email' => ['email', 'maxLength:180'],
            'whatsapp' => ['whatsapp'],
            'responsavel_id' => [],
        ];
        if ($withVersion) {
            $rules['versao'] = ['required', 'decimal:0,9'];
        }
        $result = $this->validator->validate($post, $rules);
        $errors = $result->errors;
        $data = $result->data;

        $responsavel = null;
        if (($data['responsavel_id'] ?? null) !== null) {
            $responsavel = $this->activeUserId((string) $data['responsavel_id']);
            if ($responsavel === null) {
                $errors['responsavel_id'] = 'Responsável inválido';
            }
        }

        $old = $this->blankValues();
        foreach (self::FIELDS as $field) {
            $old[$field] = is_string($post[$field] ?? null) ? trim($post[$field]) : '';
        }
        $old['ativo'] = ($post['ativo'] ?? null) === '1';
        $versao = isset($data['versao']) ? (int) $data['versao'] : null;

        $dados = new EmpresaDados(
            (string) ($data['razao_social'] ?? ''),
            $data['nome_fantasia'] ?? null,
            (string) ($data['cnpj'] ?? ''),
            (string) ($data['regime'] ?? ''),
            $data['email'] ?? null,
            $data['whatsapp'] ?? null,
            $responsavel,
            $old['ativo']
        );

        return ['errors' => $errors, 'old' => $old, 'dados' => $dados, 'versao' => $versao];
    }

    private function activeUserId(string $value): ?int
    {
        if (preg_match('/^[0-9]{1,10}$/', $value) !== 1) {
            return null;
        }
        foreach ($this->users->listActive() as $user) {
            if ($user['id'] === (int) $value) {
                return $user['id'];
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function blankValues(): array
    {
        return [
            'razao_social' => '', 'nome_fantasia' => '', 'cnpj' => '', 'regime' => '',
            'email' => '', 'whatsapp' => '', 'responsavel_id' => '', 'ativo' => false,
        ];
    }

    /** @return array<string, mixed> */
    private function valuesOf(Empresa $e): array
    {
        return [
            'razao_social' => $e->razaoSocial, 'nome_fantasia' => $e->nomeFantasia ?? '',
            'cnpj' => cnpj_mask($e->cnpj), 'regime' => $e->regime, 'email' => $e->email ?? '',
            'whatsapp' => $e->whatsapp !== null ? phone_mask($e->whatsapp) : '',
            'responsavel_id' => $e->responsavelId === null ? '' : (string) $e->responsavelId,
            'ativo' => $e->ativo,
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
    ): Response {
        return $this->render('empresas/form', [
            'title' => $id === null ? 'Nova empresa' : 'Editar empresa',
            'values' => $values,
            'errors' => $errors,
            'id' => $id,
            'versao' => $versao,
            'message' => $message,
            'responsaveis' => $this->users->listActive(),
        ], $status);
    }

    /**
     * @param array<string, string> $errors
     * @return array<string, mixed>
     */
    private function listData(EmpresaFiltro $filtro, array $errors): array
    {
        $query = array_filter([
            'q' => $filtro->q,
            'regime' => $filtro->regime,
            'ativo' => $filtro->ativo === null ? null : ($filtro->ativo ? '1' : '0'),
            'sort' => $filtro->sort === 'razao_social' ? null : $filtro->sort,
        ], static fn(?string $v): bool => $v !== null && $v !== '');

        return [
            'title' => 'Empresas',
            'resultado' => $this->empresas->search($filtro),
            'filtro' => $filtro,
            'errors' => $errors,
            'pageUrl' => static fn(int $page): string => '/empresas?'
                . http_build_query($query + ($page > 1 ? ['page' => $page] : [])),
        ];
    }

    /** @return array<string, mixed> */
    private function showData(Empresa $empresa): array
    {
        $responsavel = null;
        foreach ($this->users->listActive() as $user) {
            if ($user['id'] === $empresa->responsavelId) {
                $responsavel = $user['nome'];
            }
        }

        return [
            'title' => $empresa->razaoSocial,
            'empresa' => $empresa,
            'responsavel' => $responsavel,
            'message' => null,
        ];
    }

    private function notFound(): Response
    {
        return $this->render('erro', ['title' => 'Não encontrada', 'message' => 'Empresa não encontrada.'], 404);
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

<?php

declare(strict_types=1);

namespace ContabilFlow\Controllers;

use ContabilFlow\Core\Csrf;
use ContabilFlow\Core\Request;
use ContabilFlow\Core\Response;
use ContabilFlow\Core\Session;
use ContabilFlow\Core\View;
use ContabilFlow\Models\DuplicateException;
use ContabilFlow\Models\UserRepositoryInterface;
use ContabilFlow\Validation\Validator;

/**
 * Gestão de usuários (somente admin; o RoleMiddleware já barra os demais perfis).
 *
 * Por quê:
 * - A senha vem crua do POST e NÃO passa pelo trim do Validator: o login
 *   compara a senha exatamente como digitada, então aparar espaços aqui
 *   criaria uma senha diferente da que a pessoa vai usar.
 * - O perfil só aceita admin ou contador (whitelist) e o formulário nunca
 *   devolve a senha na tela.
 * - Ninguém desativa a si mesmo: evita o admin se trancar para fora.
 */
final class UsuarioController
{
    public const MIN_PASSWORD_LENGTH = 10;
    public const MAX_PASSWORD_LENGTH = 1000;
    public const PERFIS = ['admin' => 'Administrador', 'contador' => 'Contador'];

    public function __construct(
        private UserRepositoryInterface $users,
        private View $view,
        private Session $session,
        private Csrf $csrf,
        private Validator $validator,
    ) {
    }

    public function index(Request $request): Response
    {
        $page = $request->query['page'] ?? '1';
        if (!is_string($page) || preg_match('/^[1-9][0-9]{0,5}$/', $page) !== 1) {
            return $this->render('usuarios/index', [
                'title' => 'Usuários',
                'usuarios' => $this->users->listPage(1),
                'currentId' => $this->currentId(),
                'errors' => ['page' => 'Página inválida'],
            ], 422);
        }

        return $this->render('usuarios/index', [
            'title' => 'Usuários',
            'usuarios' => $this->users->listPage((int) $page),
            'currentId' => $this->currentId(),
            'errors' => [],
        ]);
    }

    public function create(Request $request): Response
    {
        return $this->form(['nome' => '', 'email' => '', 'perfil' => 'contador'], []);
    }

    public function store(Request $request): Response
    {
        $result = $this->validator->validate($request->post, [
            'nome' => ['required', 'maxLength:120'],
            'email' => ['required', 'email', 'maxLength:180'],
            'perfil' => ['required', 'in:' . implode(',', array_keys(self::PERFIS))],
        ]);
        $errors = $result->errors;

        $senha = $request->post['senha'] ?? null;
        $confirmacao = $request->post['senha_confirmacao'] ?? null;
        if (!is_string($senha) || $senha === '') {
            $errors['senha'] = 'Campo obrigatório';
        } elseif (mb_strlen($senha) < self::MIN_PASSWORD_LENGTH) {
            $errors['senha'] = 'Mínimo de ' . self::MIN_PASSWORD_LENGTH . ' caracteres';
        } elseif (mb_strlen($senha) > self::MAX_PASSWORD_LENGTH) {
            $errors['senha'] = 'Máximo de ' . self::MAX_PASSWORD_LENGTH . ' caracteres';
        } elseif ($confirmacao !== $senha) {
            $errors['senha_confirmacao'] = 'A confirmação não confere com a senha';
        }

        $old = [
            'nome' => is_string($request->post['nome'] ?? null) ? trim($request->post['nome']) : '',
            'email' => is_string($request->post['email'] ?? null) ? trim($request->post['email']) : '',
            'perfil' => is_string($request->post['perfil'] ?? null) ? $request->post['perfil'] : 'contador',
        ];
        if ($errors !== [] || !is_string($senha)) {
            return $this->form($old, $errors, 422);
        }

        try {
            $id = $this->users->create(
                (string) $result->data['nome'],
                (string) $result->data['email'],
                password_hash($senha, PASSWORD_DEFAULT),
                (string) $result->data['perfil']
            );
        } catch (DuplicateException) {
            return $this->form($old, ['email' => 'Já existe um usuário com este e-mail'], 409);
        }

        $this->session->flash('success', 'Usuário cadastrado.');

        return Response::redirect('/usuarios#usuario-' . $id);
    }

    /** @param array<string, string> $params */
    public function toggleActive(Request $request, array $params = []): Response
    {
        $id = (int) ($params['id'] ?? 0);
        $user = $this->users->find($id);
        if ($user === null) {
            return $this->notFound();
        }

        $result = $this->validator->validate($request->post, ['ativo' => ['required', 'in:0,1']]);
        if ($result->fails()) {
            $this->session->flash('danger', 'Pedido inválido.');

            return Response::redirect('/usuarios');
        }
        $ativar = $result->data['ativo'] === '1';

        if (!$ativar && $id === $this->currentId()) {
            $this->session->flash('danger', 'Você não pode desativar a sua própria conta.');

            return Response::redirect('/usuarios');
        }

        $this->users->setActive($id, $ativar);
        $this->session->flash('success', $ativar ? "{$user->nome} foi reativado." : "{$user->nome} foi desativado.");

        return Response::redirect('/usuarios');
    }

    private function currentId(): int
    {
        $id = $this->session->get('user_id');

        return is_int($id) ? $id : 0;
    }

    /**
     * @param array<string, string> $values
     * @param array<string, string> $errors
     */
    private function form(array $values, array $errors, int $status = 200): Response
    {
        return $this->render('usuarios/form', [
            'title' => 'Novo usuário',
            'values' => $values,
            'errors' => $errors,
            'perfis' => self::PERFIS,
            'minPassword' => self::MIN_PASSWORD_LENGTH,
        ], $status);
    }

    private function notFound(): Response
    {
        return $this->render('erro', ['title' => 'Não encontrado', 'message' => 'Usuário não encontrado.'], 404);
    }

    /** @param array<string, mixed> $data */
    private function render(string $template, array $data, int $status = 200): Response
    {
        return Response::html($this->view->render($template, $data + [
            'csrfField' => $this->csrf->field(),
            'userName' => (string) $this->session->get('nome', ''),
            'isAdmin' => $this->session->get('perfil') === 'admin',
            'flash' => $this->session->pullFlash(),
        ]), $status);
    }
}

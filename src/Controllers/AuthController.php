<?php

declare(strict_types=1);

namespace ContabilFlow\Controllers;

use ContabilFlow\Core\Clock;
use ContabilFlow\Core\Csrf;
use ContabilFlow\Core\Request;
use ContabilFlow\Core\Response;
use ContabilFlow\Core\Session;
use ContabilFlow\Core\View;
use ContabilFlow\Services\AuthService;
use ContabilFlow\Services\LoginStatus;
use ContabilFlow\Validation\Validator;

/**
 * Login e logout. Controller fino: valida, chama o serviço e escolhe a resposta.
 *
 * Por quê: a senha vem crua do POST (o Validator faz trim, e uma senha pode
 * começar ou terminar com espaço). No sucesso, o ID da sessão e o token CSRF
 * são trocados (session fixation); na tela só reaparece o e-mail, nunca a senha.
 */
final class AuthController
{
    private const MAX_PASSWORD_LENGTH = 1000;

    public function __construct(
        private AuthService $auth,
        private View $view,
        private Session $session,
        private Csrf $csrf,
        private Validator $validator,
        private Clock $clock,
    ) {
    }

    public function showLogin(Request $request): Response
    {
        if ($this->session->has('user_id')) {
            return Response::redirect('/');
        }

        return $this->form();
    }

    public function login(Request $request): Response
    {
        $password = $request->post['senha'] ?? null;
        $result = $this->validator->validate($request->post, [
            'email' => ['required', 'email', 'maxLength:180'],
            'senha' => ['required', 'maxLength:' . self::MAX_PASSWORD_LENGTH],
        ]);

        if ($result->fails() || !is_string($password)) {
            $email = $request->post['email'] ?? '';

            return $this->form(['errors' => $result->errors, 'email' => is_string($email) ? $email : ''], 422);
        }

        $email = (string) $result->data['email'];
        $login = $this->auth->login($email, $password);

        if ($login->status === LoginStatus::Locked) {
            $seconds = max(1, ($login->lockedUntil?->getTimestamp() ?? 0) - $this->clock->now()->getTimestamp());
            $message = 'Conta temporariamente bloqueada por excesso de tentativas. Tente novamente em alguns minutos.';

            return $this->form(['email' => $email, 'message' => $message], 429)
                ->withHeaders(['Retry-After' => (string) $seconds]);
        }

        if ($login->status !== LoginStatus::Success || $login->user === null) {
            return $this->form(['email' => $email, 'message' => 'E-mail ou senha inválidos.'], 401);
        }

        $this->session->regenerate();
        $this->csrf->rotate();
        $this->session->set('user_id', $login->user->id);
        $this->session->set('perfil', $login->user->perfil);
        $this->session->set('nome', $login->user->nome);

        return Response::redirect('/');
    }

    public function logout(Request $request): Response
    {
        $this->session->clear();
        $this->session->regenerate();
        $this->csrf->rotate();

        return Response::redirect('/login');
    }

    /** @param array<string, mixed> $data */
    private function form(array $data = [], int $status = 200): Response
    {
        return Response::html($this->view->render('auth/login', $data + [
            'title' => 'Entrar',
            'csrfField' => $this->csrf->field(),
            'errors' => [],
            'email' => '',
            'message' => null,
        ]), $status);
    }
}

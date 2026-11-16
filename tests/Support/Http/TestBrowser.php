<?php

declare(strict_types=1);

namespace ContabilFlow\Tests\Support\Http;

use Closure;
use ContabilFlow\App;
use ContabilFlow\Core\Csrf;
use ContabilFlow\Core\Request;
use ContabilFlow\Core\Response;
use ContabilFlow\Core\Session;

/**
 * "Navegador" em memória para os testes HTTP: mantém uma sessão própria (como o
 * cookie de um navegador real) e envia requisições ao Kernel de verdade, sem
 * rede. Cada instância é uma pessoa diferente; duas instâncias nunca dividem sessão.
 */
final class TestBrowser
{
    /** @var array<string, mixed> */
    private array $sessionData = [];
    private Session $session;
    private Csrf $csrf;
    private App $app;

    /** @param Closure(Session): App $factory */
    public function __construct(Closure $factory)
    {
        $this->session = new Session($this->sessionData);
        $this->csrf = new Csrf($this->session);
        $this->app = $factory($this->session);
    }

    public function app(): App
    {
        return $this->app;
    }

    public function token(): string
    {
        return $this->csrf->token();
    }

    public function isLoggedIn(): bool
    {
        return is_int($this->session->get('user_id'));
    }

    /** Altera a sessão diretamente (ex.: simular inatividade). */
    public function setSession(string $key, mixed $value): void
    {
        $this->session->set($key, $value);
    }

    public function sessionValue(string $key): mixed
    {
        return $this->session->get($key);
    }

    /** @param array<string, string> $headers */
    public function get(string $uri, array $headers = []): Response
    {
        return $this->app->handle(Request::create('GET', $uri, [], $headers));
    }

    /**
     * Formulário HTML: o token CSRF vai junto, como no campo oculto do navegador.
     * PUT, PATCH e DELETE viajam como POST com _method, igual aos formulários reais.
     *
     * @param array<string, mixed> $data
     * @param array<string, string> $headers
     */
    public function send(
        string $method,
        string $uri,
        array $data = [],
        array $headers = [],
        bool $csrf = true,
    ): Response {
        $method = strtoupper($method);
        if ($csrf) {
            $data = ['_csrf' => $this->token()] + $data;
        }
        if (in_array($method, ['PUT', 'PATCH', 'DELETE'], true)) {
            $data = ['_method' => $method] + $data;
            $method = 'POST';
        }

        return $this->app->handle(Request::create($method, $uri, $data, $headers));
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $headers
     */
    public function post(string $uri, array $data = [], array $headers = [], bool $csrf = true): Response
    {
        return $this->send('POST', $uri, $data, $headers, $csrf);
    }

    /** @param array<string, mixed> $data */
    public function put(string $uri, array $data = []): Response
    {
        return $this->send('PUT', $uri, $data);
    }

    /** @param array<string, mixed> $data */
    public function patch(string $uri, array $data = []): Response
    {
        return $this->send('PATCH', $uri, $data);
    }

    public function delete(string $uri): Response
    {
        return $this->send('DELETE', $uri);
    }

    /**
     * Chamada de API como o fetch() do navegador: Accept JSON e o token no cabeçalho.
     *
     * @param array<string, mixed> $data
     */
    public function api(string $method, string $uri, array $data = [], bool $withToken = true): Response
    {
        $headers = ['Accept' => 'application/json'];
        if ($withToken) {
            $headers['X-CSRF-Token'] = $this->token();
        }

        return $this->app->handle(Request::create($method, $uri, $data, $headers));
    }

    /**
     * Entra "como" um usuário sem passar pela tela de login, para os testes que
     * não tratam de login não pagarem o custo do bcrypt. O AuthMiddleware continua
     * conferindo a cada requisição se a conta existe e está ativa.
     */
    public function actAs(int $userId, string $perfil, string $nome): void
    {
        $this->session->set('user_id', $userId);
        $this->session->set('perfil', $perfil);
        $this->session->set('nome', $nome);
    }

    public function login(string $email, string $password): Response
    {
        $this->get('/login');

        return $this->post('/login', ['email' => $email, 'senha' => $password]);
    }

    /** Segue o redirecionamento (302) de uma resposta, como o navegador. */
    public function follow(Response $response): Response
    {
        $location = $response->headers['Location'] ?? null;
        if ($location === null) {
            throw new \LogicException('A resposta não é um redirecionamento.');
        }

        return $this->get($location);
    }
}

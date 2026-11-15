<?php

declare(strict_types=1);

namespace ContabilFlow\Tests\Unit;

use ContabilFlow\Controllers\AuthController;
use ContabilFlow\Core\Csrf;
use ContabilFlow\Core\Request;
use ContabilFlow\Core\Session;
use ContabilFlow\Core\View;
use ContabilFlow\Services\AuthService;
use ContabilFlow\Tests\Support\FixedClock;
use ContabilFlow\Tests\Support\InMemoryUserRepository;
use ContabilFlow\Validation\Validator;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class AuthControllerTest extends TestCase
{
    private const PASSWORD = 'segredo-12345';

    private static string $hash;
    /** @var array<string, mixed> */
    private array $sessionData = [];
    private InMemoryUserRepository $users;
    private Csrf $csrf;
    private AuthController $controller;

    public static function setUpBeforeClass(): void
    {
        self::$hash = password_hash(self::PASSWORD, PASSWORD_DEFAULT);
    }

    protected function setUp(): void
    {
        $this->users = new InMemoryUserRepository();
        $this->users->add(1, 'ana@escritorio.com', self::$hash, 'admin');
        $session = new Session($this->sessionData);
        $this->csrf = new Csrf($session);
        $clock = new FixedClock(new DateTimeImmutable('2026-11-14 10:00:00'));

        $this->controller = new AuthController(
            new AuthService($this->users, $clock),
            new View(__DIR__ . '/../../views'),
            $session,
            $this->csrf,
            new Validator(),
            $clock
        );
    }

    /** @param array<string, string> $post */
    private function login(array $post): \ContabilFlow\Core\Response
    {
        return $this->controller->login(Request::create('POST', '/login', $post));
    }

    public function testShowLoginRendersFormWithCsrfAndNoPassword(): void
    {
        $response = $this->controller->showLogin(Request::create('GET', '/login'));

        $this->assertSame(200, $response->status);
        $this->assertStringContainsString('name="_csrf"', $response->body);
        $this->assertStringContainsString($this->csrf->token(), $response->body);
        $this->assertStringContainsString('type="password"', $response->body);
        $this->assertStringContainsString('autocomplete="current-password"', $response->body);
    }

    public function testShowLoginRedirectsWhenAlreadyLoggedIn(): void
    {
        $this->sessionData['user_id'] = 1;

        $response = $this->controller->showLogin(Request::create('GET', '/login'));

        $this->assertSame(302, $response->status);
        $this->assertSame('/', $response->headers['Location']);
    }

    public function testSuccessfulLoginStoresIdentityAndRedirects(): void
    {
        $oldToken = $this->csrf->token();

        $response = $this->login(['email' => 'ana@escritorio.com', 'senha' => self::PASSWORD]);

        $this->assertSame(302, $response->status);
        $this->assertSame('/', $response->headers['Location']);
        $this->assertSame(1, $this->sessionData['user_id']);
        $this->assertSame('admin', $this->sessionData['perfil']);
        $this->assertNotSame($oldToken, $this->csrf->token(), 'token CSRF deve ser renovado no login');
    }

    public function testSessionNeverStoresPasswordOrHash(): void
    {
        $this->login(['email' => 'ana@escritorio.com', 'senha' => self::PASSWORD]);

        $this->assertStringNotContainsString(self::PASSWORD, (string) json_encode($this->sessionData));
        $this->assertStringNotContainsString(self::$hash, (string) json_encode($this->sessionData));
    }

    public function testPasswordIsNotTrimmed(): void
    {
        $this->users->add(2, 'esp@escritorio.com', password_hash(' com espaco ', PASSWORD_DEFAULT));

        $response = $this->login(['email' => 'esp@escritorio.com', 'senha' => ' com espaco ']);

        $this->assertSame(302, $response->status);
    }

    public function testWrongPasswordReturns401WithGenericMessage(): void
    {
        $response = $this->login(['email' => 'ana@escritorio.com', 'senha' => 'errada']);

        $this->assertSame(401, $response->status);
        $this->assertStringContainsString('E-mail ou senha inválidos', $response->body);
        $this->assertArrayNotHasKey('user_id', $this->sessionData);
    }

    public function testUnknownEmailGivesSameResponseAsWrongPassword(): void
    {
        $wrong = $this->login(['email' => 'ana@escritorio.com', 'senha' => 'errada']);
        $unknown = $this->login(['email' => 'ninguem@escritorio.com', 'senha' => 'errada']);

        $this->assertSame($wrong->status, $unknown->status);
        $this->assertStringContainsString('E-mail ou senha inválidos', $unknown->body);
    }

    public function testFailedLoginKeepsEmailButNeverEchoesPassword(): void
    {
        $response = $this->login(['email' => 'ana@escritorio.com', 'senha' => 'errada-secreta']);

        $this->assertStringContainsString('value="ana@escritorio.com"', $response->body);
        $this->assertStringNotContainsString('errada-secreta', $response->body);
    }

    public function testInactiveUserGets401(): void
    {
        $this->users->add(2, 'off@escritorio.com', self::$hash, ativo: false);

        $this->assertSame(401, $this->login(['email' => 'off@escritorio.com', 'senha' => self::PASSWORD])->status);
    }

    public function testLockedAccountReturns429WithRetryAfter(): void
    {
        $this->users->add(1, 'ana@escritorio.com', self::$hash, ate: new DateTimeImmutable('2026-11-14 10:15:00'));

        $response = $this->login(['email' => 'ana@escritorio.com', 'senha' => self::PASSWORD]);

        $this->assertSame(429, $response->status);
        $this->assertSame('900', $response->headers['Retry-After']);
        $this->assertStringContainsString('bloqueada', $response->body);
        $this->assertArrayNotHasKey('user_id', $this->sessionData);
    }

    public function testEmptyFieldsReturn422WithFieldErrors(): void
    {
        $response = $this->login(['email' => '', 'senha' => '']);

        $this->assertSame(422, $response->status);
        $this->assertStringContainsString('Campo obrigatório', $response->body);
        $this->assertSame([], $this->users->writes);
    }

    public function testInvalidEmailFormatReturns422(): void
    {
        $this->assertSame(422, $this->login(['email' => 'nao-e-email', 'senha' => 'x'])->status);
    }

    public function testNonStringPasswordReturns422(): void
    {
        $response = $this->controller->login(Request::create('POST', '/login', ['email' => 'a@b.co', 'senha' => ['x']]));

        $this->assertSame(422, $response->status);
    }

    public function testEmailIsEscapedWhenRedisplayed(): void
    {
        $response = $this->login(['email' => '"><script>alert(1)</script>', 'senha' => 'x']);

        $this->assertStringNotContainsString('<script>alert(1)', $response->body);
    }

    public function testLogoutClearsSessionAndRedirectsToLogin(): void
    {
        $this->login(['email' => 'ana@escritorio.com', 'senha' => self::PASSWORD]);

        $response = $this->controller->logout(Request::create('POST', '/logout'));

        $this->assertSame(302, $response->status);
        $this->assertSame('/login', $response->headers['Location']);
        $this->assertArrayNotHasKey('user_id', $this->sessionData);
        $this->assertArrayNotHasKey('perfil', $this->sessionData);
    }
}

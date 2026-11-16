<?php

declare(strict_types=1);

namespace ContabilFlow\Tests\Unit;

use ContabilFlow\Controllers\UsuarioController;
use ContabilFlow\Core\Csrf;
use ContabilFlow\Core\Request;
use ContabilFlow\Core\Response;
use ContabilFlow\Core\Session;
use ContabilFlow\Core\View;
use ContabilFlow\Tests\Support\InMemoryUserRepository;
use ContabilFlow\Validation\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UsuarioControllerTest extends TestCase
{
    private const SENHA = 'senha-forte-123';

    /** @var array<string, mixed> */
    private array $sessionData = ['user_id' => 1, 'perfil' => 'admin', 'nome' => 'Admin'];
    private Session $session;
    private InMemoryUserRepository $users;
    private UsuarioController $controller;

    protected function setUp(): void
    {
        $this->session = new Session($this->sessionData);
        $this->users = new InMemoryUserRepository();
        $this->users->add(1, 'admin@x.com', 'hash', 'admin');
        $this->users->add(2, 'bia@x.com', 'hash', 'contador');
        $this->users->rows[1]['nome'] = 'Admin';
        $this->users->rows[2]['nome'] = 'Bia';

        $this->controller = new UsuarioController(
            $this->users,
            new View(__DIR__ . '/../../views'),
            $this->session,
            new Csrf($this->session),
            new Validator()
        );
    }

    /**
     * @param array<string, string> $override
     * @return array<string, string>
     */
    private function valid(array $override = []): array
    {
        return $override + [
            'nome' => 'Carla Dias', 'email' => 'Carla@Escritorio.com', 'perfil' => 'contador',
            'senha' => self::SENHA, 'senha_confirmacao' => self::SENHA,
        ];
    }

    /** @param array<string, string> $post */
    private function store(array $post): Response
    {
        return $this->controller->store(Request::create('POST', '/usuarios', $post));
    }

    private function toggle(int $id, string $ativo): Response
    {
        return $this->controller->toggleActive(
            Request::create('POST', "/usuarios/{$id}/ativo", ['_method' => 'PATCH', 'ativo' => $ativo]),
            ['id' => (string) $id]
        );
    }

    // ---------- listagem ----------

    public function testIndexListsUsersWithProfileStatusAndActions(): void
    {
        $this->users->add(3, 'inativo@x.com', 'hash', 'contador', false);

        $response = $this->controller->index(Request::create('GET', '/usuarios'));

        $body = $response->body;
        $this->assertSame(200, $response->status);
        $this->assertStringContainsString('bia@x.com', $body);
        $this->assertStringContainsString('Administrador', $body);
        $this->assertStringContainsString('Contador', $body);
        $this->assertStringContainsString('Inativo', $body);
        $this->assertStringContainsString('3 usuário(s)', $body);
        $this->assertStringContainsString('action="/usuarios/2/ativo"', $body);
        $this->assertStringContainsString('name="_method" value="PATCH"', $body);
        $this->assertStringContainsString('name="_csrf"', $body);
        $this->assertStringContainsString('Reativar', $body);
        $this->assertStringContainsString('Desativar', $body);
        $this->assertStringContainsString('href="/usuarios"', $body); // menu do admin
    }

    public function testIndexHasNoToggleForTheLoggedUserThemself(): void
    {
        $body = $this->controller->index(Request::create('GET', '/usuarios'))->body;

        $this->assertStringNotContainsString('action="/usuarios/1/ativo"', $body);
        $this->assertStringContainsString('você', $body);
    }

    public function testIndexNeverExposesPasswordHashes(): void
    {
        $this->users->rows[2]['hash'] = '$2y$10$SEGREDOSEGREDOSEGREDO';

        $body = $this->controller->index(Request::create('GET', '/usuarios'))->body;

        $this->assertStringNotContainsString('SEGREDO', $body);
    }

    public function testIndexEscapesNames(): void
    {
        $this->users->rows[2]['nome'] = '<script>alert(1)</script>';

        $body = $this->controller->index(Request::create('GET', '/usuarios'))->body;

        $this->assertStringNotContainsString('<script>alert(1)', $body);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $body);
    }

    public function testIndexRejectsInvalidPage(): void
    {
        foreach (['0', 'abc', '-1', '1; DROP'] as $page) {
            $response = $this->controller->index(Request::create('GET', '/usuarios?page=' . rawurlencode($page)));

            $this->assertSame(422, $response->status, $page);
        }
    }

    // ---------- cadastro ----------

    public function testCreateFormHasFieldsCsrfAndPasswordInputs(): void
    {
        $response = $this->controller->create(Request::create('GET', '/usuarios/novo'));

        $this->assertSame(200, $response->status);
        $this->assertStringContainsString('name="_csrf"', $response->body);
        foreach (['nome', 'email', 'perfil', 'senha', 'senha_confirmacao'] as $field) {
            $this->assertStringContainsString('name="' . $field . '"', $response->body);
        }
        $this->assertStringContainsString('type="password" id="senha"', $response->body);
    }

    public function testStoreCreatesUserWithHashedPasswordAndNormalizedEmail(): void
    {
        $response = $this->store($this->valid());

        $this->assertSame(302, $response->status);
        $this->assertStringStartsWith('/usuarios', $response->headers['Location']);
        $user = $this->users->findByEmail('carla@escritorio.com');
        $this->assertNotNull($user);
        $this->assertSame('Carla Dias', $user->nome);
        $this->assertSame('contador', $user->perfil);
        $this->assertTrue($user->ativo);
        $this->assertNotSame(self::SENHA, $user->senhaHash);
        $this->assertTrue(password_verify(self::SENHA, $user->senhaHash));
        $this->assertSame([['type' => 'success', 'message' => 'Usuário cadastrado.']], $this->session->pullFlash());
    }

    public function testStoreCanCreateAnAdmin(): void
    {
        $this->store($this->valid(['perfil' => 'admin']));

        $this->assertSame('admin', $this->users->findByEmail('carla@escritorio.com')?->perfil);
    }

    public function testPasswordIsStoredExactlyAsTypedWithoutTrimming(): void
    {
        $senha = '  espacos nas pontas  ';

        $this->store($this->valid(['senha' => $senha, 'senha_confirmacao' => $senha]));

        $user = $this->users->findByEmail('carla@escritorio.com');
        $this->assertNotNull($user);
        $this->assertTrue(password_verify($senha, $user->senhaHash));
        $this->assertFalse(password_verify(trim($senha), $user->senhaHash));
    }

    /** @return array<string, array{0: array<string, string>, 1: string}> */
    public static function invalidUsers(): array
    {
        $enorme = str_repeat('a', 1001);

        return [
            'nome vazio' => [['nome' => ''], 'Campo obrigatório'],
            'nome longo' => [['nome' => str_repeat('a', 121)], 'Máximo de 120'],
            'email vazio' => [['email' => ''], 'Campo obrigatório'],
            'email inválido' => [['email' => 'sem-arroba'], 'E-mail inválido'],
            'perfil vazio' => [['perfil' => ''], 'Campo obrigatório'],
            'perfil desconhecido' => [['perfil' => 'superadmin'], 'Valor não permitido'],
            'perfil cliente' => [['perfil' => 'cliente'], 'Valor não permitido'],
            'senha vazia' => [['senha' => '', 'senha_confirmacao' => ''], 'Campo obrigatório'],
            'senha curta' => [['senha' => 'curta123', 'senha_confirmacao' => 'curta123'], 'Mínimo de 10'],
            'senha de 9' => [['senha' => '123456789', 'senha_confirmacao' => '123456789'], 'Mínimo de 10'],
            'confirmação diferente' => [['senha_confirmacao' => 'outra-senha-123'], 'não confere'],
            'sem confirmação' => [['senha_confirmacao' => ''], 'não confere'],
            'senha enorme' => [['senha' => $enorme, 'senha_confirmacao' => $enorme], 'Máximo de 1000'],
        ];
    }

    /** @param array<string, string> $override */
    #[DataProvider('invalidUsers')]
    public function testStoreRejectsInvalidDataWith422(array $override, string $message): void
    {
        $response = $this->store($this->valid($override));

        $this->assertSame(422, $response->status);
        $this->assertStringContainsString($message, $response->body);
        $this->assertNull($this->users->findByEmail('carla@escritorio.com'));
        $this->assertCount(2, $this->users->rows);
    }

    public function testPasswordOfExactlyTenCharactersIsAccepted(): void
    {
        $response = $this->store($this->valid(['senha' => '1234567890', 'senha_confirmacao' => '1234567890']));

        $this->assertSame(302, $response->status);
    }

    public function testMinimumLengthCountsCharactersNotBytes(): void
    {
        $senha = 'çççççççççç'; // 10 caracteres, 20 bytes

        $this->assertSame(302, $this->store($this->valid(['senha' => $senha, 'senha_confirmacao' => $senha]))->status);
    }

    public function testFormKeepsTypedFieldsButNeverEchoesPasswords(): void
    {
        $response = $this->store($this->valid(['nome' => 'Mantido', 'email' => 'mantido@x.com', 'senha' => 'curta']));

        $this->assertSame(422, $response->status);
        $this->assertStringContainsString('value="Mantido"', $response->body);
        $this->assertStringContainsString('value="mantido@x.com"', $response->body);
        $this->assertStringNotContainsString('curta', $response->body);
        $this->assertStringNotContainsString(self::SENHA, $response->body);
        $this->assertDoesNotMatchRegularExpression('/id="senha"[^>]*value=/', $response->body);
    }

    public function testStoreDuplicateEmailReturns409(): void
    {
        $response = $this->store($this->valid(['email' => 'BIA@x.com']));

        $this->assertSame(409, $response->status);
        $this->assertStringContainsString('Já existe um usuário com este e-mail', $response->body);
        $this->assertStringContainsString('value="BIA@x.com"', $response->body);
        $this->assertCount(2, $this->users->rows);
    }

    public function testStoreIgnoresExtraFieldsLikeActiveFlagAndHash(): void
    {
        $this->store($this->valid(['ativo' => '0', 'senha_hash' => 'x', 'id' => '99', 'tentativas_falhas' => '9']));

        $user = $this->users->findByEmail('carla@escritorio.com');
        $this->assertNotNull($user);
        $this->assertTrue($user->ativo);
        $this->assertSame(3, $user->id);
        $this->assertSame(0, $user->tentativasFalhas);
        $this->assertNotSame('x', $user->senhaHash);
    }

    public function testStoreEscapesEchoedInput(): void
    {
        $response = $this->store($this->valid(['nome' => '"><script>alert(1)</script>', 'senha' => 'x']));

        $this->assertStringNotContainsString('<script>alert(1)', $response->body);
    }

    // ---------- ativar e desativar ----------

    public function testAdminCanDeactivateAnotherUser(): void
    {
        $response = $this->toggle(2, '0');

        $this->assertSame(302, $response->status);
        $this->assertSame('/usuarios', $response->headers['Location']);
        $this->assertFalse($this->users->rows[2]['ativo']);
        $this->assertSame([['type' => 'success', 'message' => 'Bia foi desativado.']], $this->session->pullFlash());
    }

    public function testAdminCanReactivateAUser(): void
    {
        $this->users->rows[2]['ativo'] = false;

        $this->toggle(2, '1');

        $this->assertTrue($this->users->rows[2]['ativo']);
        $this->assertSame('Bia foi reativado.', $this->session->pullFlash()[0]['message']);
    }

    public function testAdminCannotDeactivateThemself(): void
    {
        $response = $this->toggle(1, '0');

        $this->assertSame(302, $response->status);
        $this->assertTrue($this->users->rows[1]['ativo']);
        $this->assertSame('danger', $this->session->pullFlash()[0]['type']);
        $this->assertNotContains('deactivate:1', $this->users->writes);
    }

    public function testActivatingYourselfIsAHarmlessNoop(): void
    {
        $this->toggle(1, '1');

        $this->assertTrue($this->users->rows[1]['ativo']);
    }

    public function testUnknownUserReturns404(): void
    {
        $this->assertSame(404, $this->toggle(99, '0')->status);
    }

    public function testInvalidActiveValueIsRejectedAndChangesNothing(): void
    {
        foreach (['', 'talvez', '2', 'true'] as $value) {
            $response = $this->toggle(2, $value);

            $this->assertSame(302, $response->status);
            $this->assertTrue($this->users->rows[2]['ativo'], $value);
            $this->assertSame('danger', $this->session->pullFlash()[0]['type']);
        }
    }

    public function testDeactivatingTwiceIsIdempotent(): void
    {
        $this->toggle(2, '0');
        $this->toggle(2, '0');

        $this->assertFalse($this->users->rows[2]['ativo']);
    }
}

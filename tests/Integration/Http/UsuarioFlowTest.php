<?php

declare(strict_types=1);

namespace ContabilFlow\Tests\Integration\Http;

use ContabilFlow\Tests\Support\Http\HttpTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/** Gestão de usuários (só admin) e o efeito de desativar uma conta sobre as sessões abertas. */
final class UsuarioFlowTest extends HttpTestCase
{
    private const NEW_PASSWORD = 'Senha-Nova-2026!';

    /**
     * @param array<string, string> $override
     * @return array<string, string>
     */
    private function valid(array $override = []): array
    {
        return $override + [
            'nome' => 'Carla Dias', 'email' => 'Carla.Dias@Escritorio.com', 'perfil' => 'contador',
            'senha' => self::NEW_PASSWORD, 'senha_confirmacao' => self::NEW_PASSWORD,
        ];
    }

    // ---------- acesso ----------

    public function testOnlyAdministratorsCanSeeOrChangeUsers(): void
    {
        $contador = $this->contador();

        $this->assertSame(403, $contador->get('/usuarios')->status);
        $this->assertSame(403, $contador->get('/usuarios/novo')->status);
        $this->assertSame(403, $contador->post('/usuarios', $this->valid())->status);
        $this->assertSame(403, $contador->patch('/usuarios/1/ativo', ['ativo' => '0'])->status);
        $this->assertSame(2, $this->rows('usuarios'));
        $this->assertSame(1, (int) $this->scalar('SELECT ativo FROM usuarios WHERE id = 1'));
    }

    // ---------- listagem ----------

    public function testListShowsUsersProfilesAndStatusWithoutLeakingHashes(): void
    {
        $response = $this->admin()->get('/usuarios');

        $this->assertSame(200, $response->status);
        $this->assertBodyContains('admin@contabilflow.local', $response);
        $this->assertBodyContains('contador@contabilflow.local', $response);
        $this->assertBodyContains('Administrador', $response);
        $this->assertBodyContains('2 usuário(s)', $response);
        $this->assertBodyNotContains('$2y$', $response);
        $this->assertBodyNotContains('senha_hash', $response);
    }

    public function testListMarksTheLoggedUserAndOffersNoToggleForThem(): void
    {
        $body = $this->admin()->get('/usuarios')->body;

        $this->assertStringContainsString('você', $body);
        $this->assertStringNotContainsString('action="/usuarios/1/ativo"', $body);
        $this->assertStringContainsString('action="/usuarios/2/ativo"', $body);
    }

    public function testListPaginatesAtTwentyPerPageWithActiveUsersFirst(): void
    {
        for ($i = 1; $i <= 23; $i++) {
            $this->pdo->prepare("INSERT INTO usuarios (nome, email, senha_hash, perfil, ativo) VALUES (?, ?, 'h', 'contador', ?)")
                ->execute([sprintf('Extra %02d', $i), "extra{$i}@x.com", $i === 1 ? 0 : 1]);
        }
        $admin = $this->admin();

        $first = $admin->get('/usuarios');
        $second = $admin->get('/usuarios?page=2');

        $this->assertBodyContains('25 usuário(s) · página 1 de 2', $first);
        $this->assertSame(20, substr_count($first->body, '<tr id="usuario-'));
        $this->assertSame(5, substr_count($second->body, '<tr id="usuario-'));
        $this->assertBodyContains('Extra 01', $second); // o inativo vai para o fim
        $this->assertSame(422, $admin->get('/usuarios?page=0')->status);
    }

    public function testNamesAreEscaped(): void
    {
        $this->pdo->exec("UPDATE usuarios SET nome = '<script>alert(1)</script>' WHERE id = 2");

        $body = $this->admin()->get('/usuarios')->body;

        $this->assertStringNotContainsString('<script>alert(1)', $body);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $body);
    }

    // ---------- cadastro ----------

    public function testCreatedUserCanLoginWithThePasswordTheAdminChose(): void
    {
        $admin = $this->admin();

        $response = $admin->post('/usuarios', $this->valid());

        $this->assertRedirectStartsWith('/usuarios', $response);
        $row = $this->row("SELECT * FROM usuarios WHERE email = 'carla.dias@escritorio.com'");
        $this->assertNotNull($row);
        $this->assertSame('Carla Dias', $row['nome']);
        $this->assertSame('contador', $row['perfil']);
        $this->assertSame(1, (int) $row['ativo']);
        $this->assertStringStartsWith('$2y$', $row['senha_hash']);
        $this->assertNotSame(self::NEW_PASSWORD, $row['senha_hash']);
        $this->assertSame('Usuário cadastrado.', $this->alertText($admin->follow($response)));

        $carla = $this->browser();
        $this->assertRedirectTo('/', $carla->login('carla.dias@escritorio.com', self::NEW_PASSWORD));
        $this->assertSame('contador', $carla->sessionValue('perfil'));
        $this->assertSame(403, $carla->get('/usuarios')->status); // contador não vira admin
    }

    public function testAdministratorsCanBeCreatedAndGetAdminAccess(): void
    {
        $this->admin()->post('/usuarios', $this->valid(['perfil' => 'admin']));
        $novo = $this->browser();
        $novo->login('carla.dias@escritorio.com', self::NEW_PASSWORD);

        $this->assertSame(200, $novo->get('/usuarios')->status);
        $this->assertSame(200, $novo->get('/avaliacoes/resumo')->status);
    }

    public function testPasswordWithSpacesAtTheEndsIsStoredExactlyAsTyped(): void
    {
        $senha = '  espacos nas pontas  ';
        $this->admin()->post('/usuarios', $this->valid(['senha' => $senha, 'senha_confirmacao' => $senha]));

        $this->assertRedirectTo('/', $this->browser()->login('carla.dias@escritorio.com', $senha));
        $this->assertSame(401, $this->browser()->login('carla.dias@escritorio.com', trim($senha))->status);
    }

    /** @return array<string, array{0: array<string, string>, 1: string}> */
    public static function invalidUsers(): array
    {
        return [
            'nome vazio' => [['nome' => ''], 'Campo obrigatório'],
            'email inválido' => [['email' => 'sem-arroba'], 'E-mail inválido'],
            'perfil cliente' => [['perfil' => 'cliente'], 'Valor não permitido'],
            'perfil vazio' => [['perfil' => ''], 'Campo obrigatório'],
            'senha curta' => [['senha' => 'curta123', 'senha_confirmacao' => 'curta123'], 'Mínimo de 10'],
            'senha vazia' => [['senha' => '', 'senha_confirmacao' => ''], 'Campo obrigatório'],
            'confirmação diferente' => [['senha_confirmacao' => 'Outra-Senha-2026!'], 'não confere'],
            'nome longo' => [['nome' => str_repeat('a', 121)], 'Máximo de 120'],
            'utf-8 inválido' => [['nome' => "Jo\xE3o"], 'caracteres inválidos'],
        ];
    }

    /** @param array<string, string> $override */
    #[DataProvider('invalidUsers')]
    public function testInvalidUsersAre422AndNothingIsSaved(array $override, string $message): void
    {
        $response = $this->admin()->post('/usuarios', $this->valid($override));

        $this->assertSame(422, $response->status);
        $this->assertBodyContains($message, $response);
        $this->assertSame(2, $this->rows('usuarios'));
    }

    public function testFormNeverEchoesPasswordsBack(): void
    {
        $response = $this->admin()->post('/usuarios', $this->valid(['nome' => '', 'senha' => 'Minha-Senha-Secreta-1']));

        $this->assertBodyNotContains('Minha-Senha-Secreta-1', $response);
        $this->assertDoesNotMatchRegularExpression('/id="senha"[^>]*value=/', $response->body);
        $this->assertBodyContains('value="Carla.Dias@Escritorio.com"', $response);
    }

    public function testDuplicateEmailIs409EvenWithDifferentCase(): void
    {
        $response = $this->admin()->post('/usuarios', $this->valid(['email' => 'CONTADOR@contabilflow.local']));

        $this->assertSame(409, $response->status);
        $this->assertBodyContains('Já existe um usuário com este e-mail', $response);
        $this->assertSame(2, $this->rows('usuarios'));
    }

    public function testExtraFieldsLikeActiveFlagHashAndIdAreIgnored(): void
    {
        $this->admin()->post('/usuarios', $this->valid(['ativo' => '0', 'senha_hash' => 'x', 'id' => '99', 'tentativas_falhas' => '9']));

        $row = $this->row("SELECT id, ativo, senha_hash, tentativas_falhas FROM usuarios WHERE email = 'carla.dias@escritorio.com'");
        $this->assertSame(3, (int) $row['id']);
        $this->assertSame(1, (int) $row['ativo']);
        $this->assertStringStartsWith('$2y$', $row['senha_hash']);
        $this->assertSame(0, (int) $row['tentativas_falhas']);
    }

    // ---------- ativar e desativar ----------

    public function testDeactivatingAUserKillsTheirOpenSessionImmediately(): void
    {
        $admin = $this->admin();
        $contador = $this->contador();
        $this->assertSame(200, $contador->get('/empresas')->status);

        $response = $admin->patch('/usuarios/2/ativo', ['ativo' => '0']);

        $this->assertRedirectTo('/usuarios', $response);
        $this->assertSame('Contador Demo foi desativado.', $this->alertText($admin->follow($response)));
        $this->assertRedirectTo('/login', $contador->get('/empresas'));
        $this->assertFalse($contador->isLoggedIn()); // a sessão foi limpa
        $this->assertSame(401, $this->contadorApiStatus());
    }

    private function contadorApiStatus(): int
    {
        // Um contador que ainda tente usar a API com a conta desativada.
        return $this->contador()->api('GET', '/api/dashboard/indicadores')->status;
    }

    public function testADeactivatedUserCannotLoginUntilReactivated(): void
    {
        $admin = $this->admin();
        $admin->follow($admin->patch('/usuarios/2/ativo', ['ativo' => '0']));

        $this->assertSame(401, $this->browser()->login(self::CONTADOR_EMAIL, self::CONTADOR_PASSWORD)->status);

        $response = $admin->patch('/usuarios/2/ativo', ['ativo' => '1']);
        $this->assertSame('Contador Demo foi reativado.', $this->alertText($admin->follow($response)));
        $this->assertRedirectTo('/', $this->browser()->login(self::CONTADOR_EMAIL, self::CONTADOR_PASSWORD));
    }

    public function testAdminCannotDeactivateThemselvesNorLockThemselvesOut(): void
    {
        $admin = $this->admin();

        $response = $admin->patch('/usuarios/1/ativo', ['ativo' => '0']);

        $this->assertRedirectTo('/usuarios', $response);
        $this->assertSame('Você não pode desativar a sua própria conta.', $this->alertText($admin->follow($response)));
        $this->assertSame(1, (int) $this->scalar('SELECT ativo FROM usuarios WHERE id = 1'));
        $this->assertSame(200, $admin->get('/usuarios')->status);
    }

    public function testOneAdminCanDeactivateAnotherAdmin(): void
    {
        $admin = $this->admin();
        $admin->post('/usuarios', $this->valid(['perfil' => 'admin']));
        $outro = $this->browser();
        $outro->login('carla.dias@escritorio.com', self::NEW_PASSWORD);

        $admin->patch('/usuarios/3/ativo', ['ativo' => '0']);

        $this->assertRedirectTo('/login', $outro->get('/usuarios'));
    }

    public function testInvalidToggleRequestsChangeNothing(): void
    {
        $admin = $this->admin();

        foreach (['', 'talvez', '2', 'true'] as $value) {
            $this->assertRedirectTo('/usuarios', $admin->patch('/usuarios/2/ativo', ['ativo' => $value]));
            $this->assertSame(1, (int) $this->scalar('SELECT ativo FROM usuarios WHERE id = 2'), $value);
        }
        $this->assertSame(404, $admin->patch('/usuarios/999/ativo', ['ativo' => '0'])->status);
    }

    public function testDeactivatingTwiceIsHarmless(): void
    {
        $admin = $this->admin();

        $admin->patch('/usuarios/2/ativo', ['ativo' => '0']);
        $admin->patch('/usuarios/2/ativo', ['ativo' => '0']);

        $this->assertSame(0, (int) $this->scalar('SELECT ativo FROM usuarios WHERE id = 2'));
    }
}

<?php

declare(strict_types=1);

namespace ContabilFlow\Tests\Integration\Http;

use ContabilFlow\Tests\Support\Http\HttpTestCase;

/** Login, logout, bloqueio de conta e expiração de sessão, com a senha de verdade (bcrypt). */
final class AuthFlowTest extends HttpTestCase
{
    private const GENERIC_ERROR = 'E-mail ou senha inválidos.';

    public function testLoginPageShowsTheFormWithCsrfAndNoMenu(): void
    {
        $response = $this->browser()->get('/login');

        $this->assertSame(200, $response->status);
        $this->assertBodyContains('action="/login"', $response);
        $this->assertBodyContains('name="_csrf"', $response);
        $this->assertBodyContains('type="password"', $response);
        $this->assertBodyNotContains('action="/logout"', $response);
    }

    public function testSuccessfulLoginRedirectsToTheDashboardAndStartsASession(): void
    {
        $browser = $this->browser();

        $response = $browser->login(self::ADMIN_EMAIL, self::ADMIN_PASSWORD);

        $this->assertRedirectTo('/', $response);
        $this->assertTrue($browser->isLoggedIn());
        $this->assertSame(1, $browser->sessionValue('user_id'));
        $this->assertSame('admin', $browser->sessionValue('perfil'));
        $page = $browser->follow($response);
        $this->assertSame(200, $page->status);
        $this->assertBodyContains('Painel', $page);
        $this->assertBodyContains('Administrador', $page);
    }

    public function testLoginWorksForAccountantsToo(): void
    {
        $browser = $this->browser();

        $this->assertRedirectTo('/', $browser->login(self::CONTADOR_EMAIL, self::CONTADOR_PASSWORD));
        $this->assertSame('contador', $browser->sessionValue('perfil'));
    }

    public function testEmailIsCaseInsensitiveAndTrimmed(): void
    {
        $browser = $this->browser();

        $response = $browser->login('  ADMIN@ContabilFlow.LOCAL ', self::ADMIN_PASSWORD);

        $this->assertRedirectTo('/', $response);
    }

    public function testLoginRotatesTheCsrfTokenToPreventFixation(): void
    {
        $browser = $this->browser();
        $before = $browser->token();

        $browser->login(self::ADMIN_EMAIL, self::ADMIN_PASSWORD);

        $this->assertNotSame($before, $browser->token());
    }

    public function testWrongPasswordAndUnknownEmailGiveTheSameGenericAnswer(): void
    {
        $wrongPassword = $this->browser()->login(self::ADMIN_EMAIL, 'senha-errada-123');
        $unknownEmail = $this->browser()->login('ninguem@contabilflow.local', 'senha-errada-123');

        $this->assertSame(401, $wrongPassword->status);
        $this->assertSame(401, $unknownEmail->status);
        $this->assertSame($this->alertText($wrongPassword), $this->alertText($unknownEmail));
        $this->assertSame(self::GENERIC_ERROR, $this->alertText($wrongPassword));
    }

    public function testFailedLoginKeepsTheEmailButNeverEchoesThePassword(): void
    {
        $response = $this->browser()->login(self::ADMIN_EMAIL, 'senha-errada-123');

        $this->assertBodyContains('value="' . self::ADMIN_EMAIL . '"', $response);
        $this->assertBodyNotContains('senha-errada-123', $response);
    }

    public function testFailedLoginDoesNotCreateASession(): void
    {
        $browser = $this->browser();

        $browser->login(self::ADMIN_EMAIL, 'senha-errada-123');

        $this->assertFalse($browser->isLoggedIn());
        $this->assertRedirectTo('/login', $browser->get('/empresas'));
    }

    public function testEmptyAndMalformedFieldsAreRejectedWith422(): void
    {
        $empty = $this->browser()->login('', '');
        $badEmail = $this->browser()->login('isso-nao-e-email', 'qualquer-coisa-123');

        $this->assertSame(422, $empty->status);
        $this->assertBodyContains('Campo obrigatório', $empty);
        $this->assertSame(422, $badEmail->status);
        $this->assertBodyContains('E-mail inválido', $badEmail);
    }

    public function testSqlInjectionAttemptsInTheLoginFormAreHarmless(): void
    {
        foreach (["' OR '1'='1", "admin@contabilflow.local' --", "x@y.com'; DROP TABLE usuarios; --"] as $payload) {
            $browser = $this->browser();

            $response = $browser->login($payload, "' OR '1'='1");

            $this->assertContains($response->status, [401, 422], $payload);
            $this->assertFalse($browser->isLoggedIn());
        }
        $this->assertSame(2, $this->rows('usuarios'));
    }

    public function testLoginWithoutCsrfTokenIsRejected(): void
    {
        $response = $this->browser()->post('/login', ['email' => self::ADMIN_EMAIL, 'senha' => self::ADMIN_PASSWORD], [], false);

        $this->assertSame(419, $response->status);
    }

    // ---------- bloqueio ----------

    public function testFiveWrongPasswordsLockTheAccountEvenForTheCorrectPassword(): void
    {
        $browser = $this->browser();
        for ($i = 1; $i <= 5; $i++) {
            $browser->login(self::ADMIN_EMAIL, 'senha-errada-' . $i);
        }

        $response = $browser->login(self::ADMIN_EMAIL, self::ADMIN_PASSWORD);

        $this->assertSame(429, $response->status);
        $this->assertGreaterThan(0, (int) ($response->headers['Retry-After'] ?? 0));
        $this->assertBodyContains('temporariamente bloqueada', $response);
        $this->assertFalse($browser->isLoggedIn());
        $this->assertNotNull($this->scalar("SELECT bloqueado_ate FROM usuarios WHERE id = 1"));
    }

    public function testFourWrongPasswordsDoNotLockTheAccount(): void
    {
        $browser = $this->browser();
        for ($i = 1; $i <= 4; $i++) {
            $browser->login(self::ADMIN_EMAIL, 'senha-errada-' . $i);
        }

        $this->assertRedirectTo('/', $browser->login(self::ADMIN_EMAIL, self::ADMIN_PASSWORD));
    }

    public function testTheLockEndsAfterFifteenMinutes(): void
    {
        $browser = $this->browser();
        for ($i = 1; $i <= 5; $i++) {
            $browser->login(self::ADMIN_EMAIL, 'senha-errada-' . $i);
        }
        $this->assertSame(429, $browser->login(self::ADMIN_EMAIL, self::ADMIN_PASSWORD)->status);

        $this->clock->advance('+16 minutes');

        $this->assertRedirectTo('/', $browser->login(self::ADMIN_EMAIL, self::ADMIN_PASSWORD));
    }

    public function testASuccessfulLoginResetsTheFailureCounter(): void
    {
        $browser = $this->browser();
        for ($i = 1; $i <= 3; $i++) {
            $browser->login(self::ADMIN_EMAIL, 'senha-errada-' . $i);
        }
        $this->assertSame(3, (int) $this->scalar('SELECT tentativas_falhas FROM usuarios WHERE id = 1'));

        $browser->login(self::ADMIN_EMAIL, self::ADMIN_PASSWORD);

        $this->assertSame(0, (int) $this->scalar('SELECT tentativas_falhas FROM usuarios WHERE id = 1'));
    }

    public function testTheLockOfOneAccountDoesNotAffectAnother(): void
    {
        $attacker = $this->browser();
        for ($i = 1; $i <= 5; $i++) {
            $attacker->login(self::ADMIN_EMAIL, 'senha-errada-' . $i);
        }

        $this->assertRedirectTo('/', $this->browser()->login(self::CONTADOR_EMAIL, self::CONTADOR_PASSWORD));
    }

    public function testInactiveUserCannotLoginAndGetsTheGenericAnswer(): void
    {
        $this->pdo->exec('UPDATE usuarios SET ativo = 0 WHERE id = 2');
        $browser = $this->browser();

        $response = $browser->login(self::CONTADOR_EMAIL, self::CONTADOR_PASSWORD);

        $this->assertSame(401, $response->status);
        $this->assertSame(self::GENERIC_ERROR, $this->alertText($response));
        $this->assertFalse($browser->isLoggedIn());
    }

    // ---------- já logado, logout e sessão ----------

    public function testLoggedInUserVisitingLoginGoesToTheDashboard(): void
    {
        $browser = $this->browser();
        $browser->login(self::ADMIN_EMAIL, self::ADMIN_PASSWORD);

        $this->assertRedirectTo('/', $browser->get('/login'));
    }

    public function testLogoutEndsTheSessionAndProtectedPagesRequireLoginAgain(): void
    {
        $browser = $this->browser();
        $browser->login(self::ADMIN_EMAIL, self::ADMIN_PASSWORD);
        $tokenBefore = $browser->token();

        $response = $browser->post('/logout');

        $this->assertRedirectTo('/login', $response);
        $this->assertFalse($browser->isLoggedIn());
        $this->assertNull($browser->sessionValue('perfil'));
        $this->assertNotSame($tokenBefore, $browser->token());
        $this->assertRedirectTo('/login', $browser->get('/empresas'));
        $this->assertRedirectTo('/login', $browser->get('/'));
    }

    public function testLogoutWithoutCsrfTokenDoesNotLogTheUserOut(): void
    {
        $browser = $this->admin();

        $response = $browser->post('/logout', [], [], false);

        $this->assertSame(419, $response->status);
        $this->assertTrue($browser->isLoggedIn());
    }

    public function testLogoutIsNotAvailableThroughGet(): void
    {
        $browser = $this->admin();

        $this->assertSame(405, $browser->get('/logout')->status);
        $this->assertTrue($browser->isLoggedIn());
    }

    public function testIdleSessionExpiresAfterThirtyMinutes(): void
    {
        $browser = $this->browser();
        $browser->login(self::ADMIN_EMAIL, self::ADMIN_PASSWORD);
        $this->assertSame(200, $browser->get('/empresas')->status);

        $browser->setSession('_last_activity', time() - 1801);
        $response = $browser->get('/empresas');

        $this->assertRedirectTo('/login', $response);
        $this->assertFalse($browser->isLoggedIn());
    }

    public function testActiveSessionIsRenewedByActivity(): void
    {
        $browser = $this->browser();
        $browser->login(self::ADMIN_EMAIL, self::ADMIN_PASSWORD);

        $browser->setSession('_last_activity', time() - 1500);

        $this->assertSame(200, $browser->get('/empresas')->status);
        $this->assertGreaterThan(time() - 5, (int) $browser->sessionValue('_last_activity'));
    }

    public function testTwoBrowsersNeverShareASession(): void
    {
        $alice = $this->browser();
        $bob = $this->browser();
        $alice->login(self::ADMIN_EMAIL, self::ADMIN_PASSWORD);

        $this->assertTrue($alice->isLoggedIn());
        $this->assertFalse($bob->isLoggedIn());
        $this->assertRedirectTo('/login', $bob->get('/empresas'));
    }
}

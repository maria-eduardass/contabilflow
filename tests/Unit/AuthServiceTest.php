<?php

declare(strict_types=1);

namespace ContabilFlow\Tests\Unit;

use ContabilFlow\Services\AuthService;
use ContabilFlow\Services\LoginStatus;
use ContabilFlow\Tests\Support\FixedClock;
use ContabilFlow\Tests\Support\InMemoryUserRepository;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class AuthServiceTest extends TestCase
{
    private const PASSWORD = 'segredo-12345';

    private static string $hash;
    private InMemoryUserRepository $users;
    private FixedClock $clock;
    private AuthService $auth;

    public static function setUpBeforeClass(): void
    {
        self::$hash = password_hash(self::PASSWORD, PASSWORD_DEFAULT);
    }

    protected function setUp(): void
    {
        $this->users = new InMemoryUserRepository();
        $this->clock = new FixedClock(new DateTimeImmutable('2026-11-14 10:00:00'));
        $this->auth = new AuthService($this->users, $this->clock);
        $this->users->add(1, 'ana@escritorio.com', self::$hash);
    }

    public function testValidCredentialsSucceed(): void
    {
        $result = $this->auth->login('ana@escritorio.com', self::PASSWORD);

        $this->assertSame(LoginStatus::Success, $result->status);
        $this->assertNotNull($result->user);
        $this->assertSame(1, $result->user->id);
        $this->assertSame('contador', $result->user->perfil);
    }

    public function testEmailIsNormalized(): void
    {
        $result = $this->auth->login('  ANA@Escritorio.com ', self::PASSWORD);

        $this->assertSame(LoginStatus::Success, $result->status);
    }

    public function testSuccessResetsFailureCounter(): void
    {
        $this->auth->login('ana@escritorio.com', 'errada');
        $this->auth->login('ana@escritorio.com', self::PASSWORD);

        $this->assertSame(0, $this->users->rows[1]['falhas']);
    }

    public function testWrongPasswordIsInvalidAndCountsFailure(): void
    {
        $result = $this->auth->login('ana@escritorio.com', 'errada');

        $this->assertSame(LoginStatus::InvalidCredentials, $result->status);
        $this->assertNull($result->user);
        $this->assertSame(1, $this->users->rows[1]['falhas']);
    }

    public function testUnknownEmailIsInvalidAndWritesNothing(): void
    {
        $result = $this->auth->login('ninguem@x.com', self::PASSWORD);

        $this->assertSame(LoginStatus::InvalidCredentials, $result->status);
        $this->assertSame([], $this->users->writes);
    }

    public function testEmptyPasswordIsInvalid(): void
    {
        $this->assertSame(LoginStatus::InvalidCredentials, $this->auth->login('ana@escritorio.com', '')->status);
    }

    public function testInactiveUserWithCorrectPasswordIsInvalid(): void
    {
        $this->users->add(2, 'off@escritorio.com', self::$hash, ativo: false);

        $result = $this->auth->login('off@escritorio.com', self::PASSWORD);

        $this->assertSame(LoginStatus::InvalidCredentials, $result->status);
        $this->assertNull($result->user);
    }

    public function testFifthFailureLocksAccount(): void
    {
        for ($i = 1; $i <= 4; $i++) {
            $this->assertSame(
                LoginStatus::InvalidCredentials,
                $this->auth->login('ana@escritorio.com', 'errada')->status,
                "tentativa {$i}"
            );
        }

        $fifth = $this->auth->login('ana@escritorio.com', 'errada');

        $this->assertSame(LoginStatus::Locked, $fifth->status);
        $this->assertEquals(new DateTimeImmutable('2026-11-14 10:15:00'), $this->users->rows[1]['ate']);
    }

    public function testLockedAccountRejectsEvenCorrectPassword(): void
    {
        $this->users->add(1, 'ana@escritorio.com', self::$hash, ate: new DateTimeImmutable('2026-11-14 10:15:00'));
        $this->clock->advance('+14 minutes');

        $result = $this->auth->login('ana@escritorio.com', self::PASSWORD);

        $this->assertSame(LoginStatus::Locked, $result->status);
        $this->assertNull($result->user);
        $this->assertSame([], $this->users->writes, 'durante o bloqueio não conta nem reseta nada');
    }

    public function testLockExpiresExactlyAtUntil(): void
    {
        $this->users->add(1, 'ana@escritorio.com', self::$hash, ate: new DateTimeImmutable('2026-11-14 10:15:00'));
        $this->clock->advance('+15 minutes');

        $this->assertSame(LoginStatus::Success, $this->auth->login('ana@escritorio.com', self::PASSWORD)->status);
        $this->assertNull($this->users->rows[1]['ate']);
    }

    public function testLockResultCarriesRetryTime(): void
    {
        $until = new DateTimeImmutable('2026-11-14 10:15:00');
        $this->users->add(1, 'ana@escritorio.com', self::$hash, ate: $until);

        $this->assertEquals($until, $this->auth->login('ana@escritorio.com', 'x')->lockedUntil);
    }

    public function testOutdatedHashIsUpgradedOnLogin(): void
    {
        $weak = password_hash(self::PASSWORD, PASSWORD_BCRYPT, ['cost' => 4]);
        $this->users->add(3, 'velho@escritorio.com', $weak);

        $result = $this->auth->login('velho@escritorio.com', self::PASSWORD);

        $this->assertSame(LoginStatus::Success, $result->status);
        $this->assertNotSame($weak, $this->users->rows[3]['hash']);
        $this->assertTrue(password_verify(self::PASSWORD, $this->users->rows[3]['hash']));
    }

    public function testCurrentHashIsNotRewritten(): void
    {
        $this->auth->login('ana@escritorio.com', self::PASSWORD);

        $this->assertNotContains('rehash:1', $this->users->writes);
    }
}

<?php

declare(strict_types=1);

namespace ContabilFlow\Tests\Support\Http;

use ContabilFlow\App;
use ContabilFlow\Core\LazyPdo;
use ContabilFlow\Core\Migrator;
use ContabilFlow\Core\Response;
use ContabilFlow\Core\Seeder;
use ContabilFlow\Core\Session;
use ContabilFlow\Tests\Support\ArrayLogger;
use ContabilFlow\Tests\Support\FixedClock;
use ContabilFlow\Tests\Support\TestDatabase;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Base dos testes HTTP de ponta a ponta: banco de testes real e limpo, seed de
 * demonstração e o app montado por ContabilFlow\App, o mesmo do site.
 *
 * Relógio fixo em 16/11/2026 10:00, então o semáforo e o dashboard dão sempre
 * os mesmos números. Qualquer erro registrado no log do app (um 500 escondido)
 * derruba o teste, a menos que ele declare que o esperava.
 *
 * Dados do seed: usuários 1 (admin) e 2 (contador); empresas 1 a 3
 * (Padaria Pão Quente, Oficina Silva, Tech Soluções) e guias 1 a 9.
 */
abstract class HttpTestCase extends TestCase
{
    protected const TODAY = '2026-11-16';
    protected const ADMIN_EMAIL = 'admin@contabilflow.local';
    protected const ADMIN_PASSWORD = 'Admin-Senha-2026!';
    protected const CONTADOR_EMAIL = 'contador@contabilflow.local';
    protected const CONTADOR_PASSWORD = 'Contador-Senha-2026!';

    protected PDO $pdo;
    protected ArrayLogger $logger;
    protected FixedClock $clock;
    private bool $expectsServerErrors = false;

    protected function setUp(): void
    {
        $pdo = TestDatabase::connectOrNull();
        if ($pdo === null) {
            $this->markTestSkipped('Crie o .env a partir do .env.example para rodar a integração.');
        }
        $this->pdo = $pdo;
        $this->prepareSchemaAndEmptyTables();

        $this->seedDemoData();

        $this->logger = new ArrayLogger();
        $this->clock = new FixedClock(new DateTimeImmutable(self::TODAY . ' 10:00:00'));
    }

    /**
     * Por quê: recriar as 6 tabelas a cada teste levaria minutos. O esquema só é
     * (re)criado quando falta ou outro teste o derrubou; nos demais casos basta
     * esvaziar os dados, o que também zera os ids (empresa 1, guia 1...).
     */
    private function prepareSchemaAndEmptyTables(): void
    {
        $tables = $this->pdo->query('SHOW TABLES');
        $existing = $tables === false ? [] : $tables->fetchAll(PDO::FETCH_COLUMN);
        $expected = ['avaliacoes', 'empresas', 'lembretes', 'obrigacoes', 'schema_migrations', 'usuarios'];
        sort($existing);

        if ($existing !== $expected) {
            TestDatabase::reset($this->pdo);
            (new Migrator($this->pdo, __DIR__ . '/../../../database/migrations'))->run();

            return;
        }

        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['avaliacoes', 'lembretes', 'obrigacoes', 'empresas', 'usuarios'] as $table) {
            $this->pdo->exec('TRUNCATE TABLE ' . $table);
        }
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    /** @var array<string, list<array<string, mixed>>>|null dados do seed, guardados após a primeira vez */
    private static ?array $seedSnapshot = null;

    /**
     * Por quê: o Seeder gera hashes bcrypt (lentos de propósito). Rodamos o seed
     * de verdade uma vez por processo e, nos demais testes, restauramos as mesmas
     * linhas, com os mesmos hashes e ids, sem refazer os hashes.
     */
    private function seedDemoData(): void
    {
        $tables = ['usuarios', 'empresas', 'obrigacoes'];
        if (self::$seedSnapshot === null) {
            $today = new DateTimeImmutable(self::TODAY, new \DateTimeZone('America/Sao_Paulo'));
            (new Seeder($this->pdo))->run($today, self::ADMIN_PASSWORD, self::CONTADOR_PASSWORD);
            foreach ($tables as $table) {
                $stmt = $this->pdo->query('SELECT * FROM ' . $table . ' ORDER BY id');
                self::$seedSnapshot[$table] = $stmt === false ? [] : array_values($stmt->fetchAll());
            }

            return;
        }

        foreach ($tables as $table) {
            $columns = $this->pdo->query("SHOW COLUMNS FROM {$table} WHERE Extra NOT LIKE '%GENERATED%'");
            $names = $columns === false ? [] : $columns->fetchAll(PDO::FETCH_COLUMN);
            $insert = $this->pdo->prepare(
                'INSERT INTO ' . $table . ' (' . implode(', ', $names) . ') VALUES ('
                . implode(', ', array_fill(0, count($names), '?')) . ')'
            );
            foreach (self::$seedSnapshot[$table] as $row) {
                $insert->execute(array_map(static fn(string $c): mixed => $row[$c], $names));
            }
        }
    }

    protected function tearDown(): void
    {
        if (!$this->expectsServerErrors) {
            $this->assertSame([], $this->logger->messages, 'O app registrou erro(s) inesperado(s) no log.');
        }
    }

    protected function expectServerErrors(): void
    {
        $this->expectsServerErrors = true;
    }

    /** Visitante sem login. */
    protected function browser(): TestBrowser
    {
        $pdo = $this->pdo;
        $logger = $this->logger;
        $clock = $this->clock;

        return new TestBrowser(
            static fn(Session $session): App => App::build(
                dirname(__DIR__, 3),
                $session,
                new LazyPdo(static fn(): PDO => $pdo),
                $clock,
                $logger
            )
        );
    }

    protected function loginAs(string $email, string $password): TestBrowser
    {
        $browser = $this->browser();
        $response = $browser->login($email, $password);
        $this->assertSame(302, $response->status, "Login de {$email} falhou");
        $this->assertTrue($browser->isLoggedIn());

        return $browser;
    }

    /** Administrador já logado (id 1 do seed), sem custo de bcrypt. */
    protected function admin(): TestBrowser
    {
        $browser = $this->browser();
        $browser->actAs(1, 'admin', 'Administrador');

        return $browser;
    }

    /** Contador já logado (id 2 do seed), sem custo de bcrypt. */
    protected function contador(): TestBrowser
    {
        $browser = $this->browser();
        $browser->actAs(2, 'contador', 'Contador Demo');

        return $browser;
    }

    // ---------- consultas diretas ao banco, para conferir o efeito de uma ação ----------

    /**
     * Uma linha do banco; o teste falha se ela não existir.
     *
     * @param list<mixed> $params
     * @return array<string, mixed>
     */
    protected function row(string $sql, array $params = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        $this->assertIsArray($row, 'Nenhuma linha encontrada para: ' . $sql);

        return $row;
    }

    /** @param list<mixed> $params */
    protected function scalar(string $sql, array $params = []): mixed
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchColumn();
    }

    protected function rows(string $table, string $where = '1=1'): int
    {
        return (int) $this->scalar("SELECT COUNT(*) FROM {$table} WHERE {$where}");
    }

    // ---------- asserções sobre respostas ----------

    protected function assertRedirectTo(string $location, Response $response): void
    {
        $this->assertSame(302, $response->status, 'esperava redirecionamento, veio ' . $response->status);
        $this->assertSame($location, $response->headers['Location'] ?? null);
    }

    protected function assertRedirectStartsWith(string $prefix, Response $response): void
    {
        $this->assertSame(302, $response->status, 'esperava redirecionamento, veio ' . $response->status);
        $this->assertTrue(
            str_starts_with($response->headers['Location'] ?? '', $prefix),
            'Location esperado começando com ' . $prefix . ', veio ' . ($response->headers['Location'] ?? '(nenhum)')
        );
    }

    protected function assertBodyContains(string $needle, Response $response): void
    {
        $this->assertStringContainsString($needle, $response->body);
    }

    protected function assertBodyNotContains(string $needle, Response $response): void
    {
        $this->assertStringNotContainsString($needle, $response->body);
    }

    /** @return array<string, mixed> */
    protected function json(Response $response): array
    {
        $this->assertStringContainsString('application/json', $response->headers['Content-Type'] ?? '');
        $data = json_decode($response->body, true);
        $this->assertIsArray($data, 'o corpo não é JSON válido: ' . $response->body);

        return $data;
    }

    /** Última mensagem flash exibida na página (alerta do Bootstrap). */
    protected function alertText(Response $response): ?string
    {
        return preg_match('#<div class="alert alert-[a-z]+" role="alert">(.*?)</div>#s', $response->body, $m) === 1
            ? html_entity_decode(trim($m[1]))
            : null;
    }
}

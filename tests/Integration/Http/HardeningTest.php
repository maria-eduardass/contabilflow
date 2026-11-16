<?php

declare(strict_types=1);

namespace ContabilFlow\Tests\Integration\Http;

use ContabilFlow\Core\Request;
use ContabilFlow\Tests\Support\Http\HttpTestCase;

/** Entradas malformadas e falhas internas: nunca 500 por culpa do usuário, nunca vazamento de detalhes. */
final class HardeningTest extends HttpTestCase
{
    public function testInternalFailureShowsAGenericPageAndLogsTheDetailsOnly(): void
    {
        $this->expectServerErrors();
        $admin = $this->admin();
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        $this->pdo->exec('DROP TABLE lembretes');
        $this->pdo->exec('DROP TABLE obrigacoes');
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

        $response = $admin->get('/obrigacoes');

        $this->assertSame(500, $response->status);
        foreach (['SQLSTATE', 'PDOException', 'obrigacoes', 'Stack trace', '.php', 'C:\\'] as $leak) {
            $this->assertStringNotContainsString($leak, $response->body, "vazou: {$leak}");
        }
        $this->assertBodyContains('Erro interno', $response);
        $this->assertSame('DENY', $response->headers['X-Frame-Options'] ?? null);
        $this->assertCount(1, $this->logger->messages);
        $this->assertStringContainsString('SQLSTATE', $this->logger->messages[0]);
    }

    public function testLogNeverContainsPasswordsOrTokens(): void
    {
        $this->expectServerErrors();
        $browser = $this->browser();
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        $this->pdo->exec('DROP TABLE usuarios');
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

        $browser->login(self::ADMIN_EMAIL, 'Minha-Senha-Super-Secreta-9');

        foreach ($this->logger->messages as $message) {
            $this->assertStringNotContainsString('Minha-Senha-Super-Secreta-9', $message);
        }
    }

    public function testArraysWhereTextIsExpectedAreRejectedWithoutErrors(): void
    {
        $admin = $this->admin();

        foreach (['/empresas?q[]=a', '/empresas?regime[x]=1', '/obrigacoes?tipo[]=DAS', '/obrigacoes?sort[]=vencimento', '/usuarios?page[]=1'] as $uri) {
            $this->assertSame(422, $admin->get($uri)->status, $uri);
        }
        $post = $admin->post('/empresas', ['razao_social' => ['x'], 'cnpj' => ['1'], 'regime' => ['mei']]);
        $this->assertSame(422, $post->status);
        $this->assertSame(422, $admin->post('/avaliacao', ['q1' => ['5'], 'nps' => ['9']])->status);
        $this->assertSame(422, $admin->put('/empresas/1', ['razao_social' => 'x', 'versao' => ['1']])->status);
    }

    public function testHugeNumbersInThePathAreSimply404(): void
    {
        $admin = $this->admin();

        foreach (['/empresas/99999999999999999999', '/obrigacoes/99999999999999999999/editar', '/api/obrigacoes/99999999999999999999/lembrete'] as $uri) {
            $this->assertSame(404, $admin->get($uri, ['Accept' => 'application/json'])->status, $uri);
        }
        $this->assertSame(404, $admin->delete('/empresas/99999999999999999999')->status);
        $this->assertSame(404, $admin->patch('/usuarios/99999999999999999999/ativo', ['ativo' => '0'])->status);
    }

    public function testPathTraversalAndOddPathsDoNotReachAnything(): void
    {
        $admin = $this->admin();

        foreach (['/../../.env', '/empresas/../.env', '/%2e%2e/.env', '/empresas/1/../../.env', '//empresas', '/empresas//1'] as $uri) {
            $status = $admin->get($uri)->status;

            $this->assertContains($status, [200, 302, 404], $uri);
            $this->assertNotSame(500, $status, $uri);
        }
        $this->assertStringNotContainsString('DB_PASS', $admin->get('/../.env')->body);
    }

    public function testNullBytesAndVeryLongInputsNeverCauseAServerError(): void
    {
        $admin = $this->admin();
        $long = str_repeat('a', 100000);

        $responses = [
            $admin->post('/empresas', ['razao_social' => "Nome\0Nulo", 'cnpj' => '10.000.000/0001-45', 'regime' => 'mei']),
            $admin->post('/empresas', ['razao_social' => $long, 'cnpj' => '10.000.000/0001-45', 'regime' => 'mei']),
            $admin->get('/empresas?q=' . $long),
            $admin->post('/avaliacao', ['q1' => '5', 'q2' => '5', 'q3' => '5', 'q4' => '5', 'q5' => '5', 'nps' => '9', 'comentario' => $long]),
            $admin->api('POST', '/api/obrigacoes/1/lembretes', ['canal' => 'email', 'mensagem' => $long]),
        ];

        foreach ($responses as $i => $response) {
            $this->assertLessThan(500, $response->status, "caso {$i}");
        }
    }

    public function testSqlInjectionPayloadsInEveryTextEntryPointAreStoredOrRejectedButNeverExecuted(): void
    {
        $admin = $this->admin();
        $payload = "'); DROP TABLE empresas; --";

        $admin->post('/empresas', ['razao_social' => $payload, 'cnpj' => '10.000.000/0001-45', 'regime' => 'mei', 'ativo' => '1']);
        $admin->post('/obrigacoes', ['empresa_id' => '1', 'tipo' => 'OUTRA', 'descricao' => $payload, 'competencia' => '2026-10', 'vencimento' => '2026-11-30']);
        $admin->post('/usuarios', ['nome' => $payload, 'email' => 'inj@x.com', 'perfil' => 'contador', 'senha' => $payload . 'xx', 'senha_confirmacao' => $payload . 'xx']);
        $admin->api('POST', '/api/obrigacoes/1/lembretes', ['canal' => 'email', 'mensagem' => $payload]);
        $admin->post('/avaliacao', ['q1' => '5', 'q2' => '5', 'q3' => '5', 'q4' => '5', 'q5' => '5', 'nps' => '9', 'comentario' => $payload]);
        foreach (['/empresas?q=', '/obrigacoes?q='] as $search) {
            $admin->get($search . rawurlencode($payload));
        }

        // as tabelas continuam lá e o texto foi tratado como texto
        foreach (['empresas', 'obrigacoes', 'usuarios', 'lembretes', 'avaliacoes'] as $table) {
            $this->assertGreaterThanOrEqual(0, $this->rows($table), $table);
        }
        $this->assertSame($payload, $this->scalar('SELECT razao_social FROM empresas WHERE id = 4'));
        $this->assertSame(3, $this->rows('empresas', 'id <= 3'));
    }

    public function testRequestsWithoutAnyHeadersOrWithGarbageHeadersAreHandled(): void
    {
        $admin = $this->admin();

        $weird = $admin->get('/empresas', ['Accept' => '*/*; q=garbage', 'X-Forwarded-For' => "1.1.1.1\r\nInjected: yes", 'Cookie' => 'x']);

        $this->assertSame(200, $weird->status);
        $this->assertArrayNotHasKey('Injected', $weird->headers);
    }

    public function testHeadAndOptionsRequestsAreHandledGracefully(): void
    {
        $admin = $this->admin();

        foreach (['HEAD', 'OPTIONS', 'TRACE', 'CONNECT'] as $method) {
            $response = $admin->app()->handle(Request::create($method, '/empresas'));

            $this->assertContains($response->status, [200, 302, 404, 405, 419], $method); // 419: método inseguro sem token
        }
    }

    public function testNoResponseEverSetsACookieOrLeaksServerInternalsInHeaders(): void
    {
        $response = $this->admin()->get('/');

        foreach (array_keys($response->headers) as $name) {
            $this->assertNotContains(strtolower($name), ['x-powered-by', 'server', 'set-cookie']);
        }
    }
}

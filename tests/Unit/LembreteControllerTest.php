<?php

declare(strict_types=1);

namespace ContabilFlow\Tests\Unit;

use ContabilFlow\Controllers\LembreteController;
use ContabilFlow\Core\Request;
use ContabilFlow\Core\Response;
use ContabilFlow\Core\Session;
use ContabilFlow\Models\EmpresaDados;
use ContabilFlow\Models\LembreteCanal;
use ContabilFlow\Models\ObrigacaoStatus;
use ContabilFlow\Services\LembreteService;
use ContabilFlow\Services\SemaforoService;
use ContabilFlow\Tests\Support\FixedClock;
use ContabilFlow\Tests\Support\InMemoryEmpresaRepository;
use ContabilFlow\Tests\Support\InMemoryLembreteRepository;
use ContabilFlow\Tests\Support\InMemoryObrigacaoRepository;
use ContabilFlow\Validation\Validator;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LembreteControllerTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $sessionData = ['user_id' => 5, 'perfil' => 'contador', 'nome' => 'Ana'];
    private InMemoryEmpresaRepository $empresas;
    private InMemoryObrigacaoRepository $guias;
    private InMemoryLembreteRepository $lembretes;
    private LembreteController $controller;
    private int $empresa;

    protected function setUp(): void
    {
        $this->empresas = new InMemoryEmpresaRepository();
        $this->guias = new InMemoryObrigacaoRepository($this->empresas);
        $this->lembretes = new InMemoryLembreteRepository();
        $this->empresa = $this->empresas->create(new EmpresaDados(
            'Padaria Alfa',
            null,
            '11222333000181',
            'simples',
            'contato@alfa.com',
            '5511987654321',
            null
        ));
        $clock = new FixedClock(new DateTimeImmutable('2026-11-16 10:00:00'));

        $this->controller = new LembreteController(
            $this->guias,
            $this->empresas,
            $this->lembretes,
            new LembreteService(new SemaforoService($clock)),
            $clock,
            new Session($this->sessionData),
            new Validator()
        );
    }

    /** @return array<string, mixed> */
    private function json(Response $response): array
    {
        $this->assertSame('application/json; charset=UTF-8', $response->headers['Content-Type']);

        return (array) json_decode($response->body, true);
    }

    /** @param array<string, string> $post */
    private function register(int $id, array $post): Response
    {
        return $this->controller->registrar(
            Request::create('POST', "/api/obrigacoes/{$id}/lembretes", $post),
            ['id' => (string) $id]
        );
    }

    // ---------- prévia ----------

    public function testPreviewReturnsMessageLinksAndSemaphore(): void
    {
        $id = $this->guias->seed($this->empresa, 'DAS', '2026-10-01', '2026-11-10');

        $response = $this->controller->preview(Request::create('GET', '/x'), ['id' => (string) $id]);

        $body = $this->json($response);
        $this->assertSame(200, $response->status);
        $this->assertStringContainsString('vencida desde 10/11/2026', $body['mensagem']);
        $this->assertStringStartsWith('https://wa.me/5511987654321?text=', $body['whatsappUrl']);
        $this->assertStringStartsWith('mailto:contato@alfa.com?subject=', $body['mailtoUrl']);
        $this->assertSame('preto', $body['semaforo']);
    }

    public function testPreviewDoesNotRegisterAnything(): void
    {
        $id = $this->guias->seed($this->empresa);

        $this->controller->preview(Request::create('GET', '/x'), ['id' => (string) $id]);

        $this->assertSame([], $this->lembretes->rows);
    }

    public function testPreviewHasNullLinksWhenCompanyHasNoContact(): void
    {
        $semContato = $this->empresas->create(new EmpresaDados('Sem Contato', null, '11444777000161', 'mei', null, null, null));
        $id = $this->guias->seed($semContato);

        $body = $this->json($this->controller->preview(Request::create('GET', '/x'), ['id' => (string) $id]));

        $this->assertNull($body['whatsappUrl']);
        $this->assertNull($body['mailtoUrl']);
    }

    public function testPreviewOfUnknownGuideIs404Json(): void
    {
        $response = $this->controller->preview(Request::create('GET', '/x'), ['id' => '99']);

        $this->assertSame(404, $response->status);
        $this->assertSame('NOT_FOUND', $this->json($response)['error']['code']);
    }

    /** @return array<string, array{0: ObrigacaoStatus}> */
    public static function closedStatuses(): array
    {
        return ['paga' => [ObrigacaoStatus::Paga], 'cancelada' => [ObrigacaoStatus::Cancelada]];
    }

    #[DataProvider('closedStatuses')]
    public function testPreviewOfPaidOrCancelledGuideIs422(ObrigacaoStatus $status): void
    {
        $id = $this->guias->seed($this->empresa, 'DAS', '2026-10-01', '2026-11-10', $status);

        $response = $this->controller->preview(Request::create('GET', '/x'), ['id' => (string) $id]);

        $this->assertSame(422, $response->status);
        $this->assertSame('NOT_REMINDABLE', $this->json($response)['error']['code']);
    }

    // ---------- registro ----------

    public function testRegisterStoresTheSendWithUserChannelMessageAndTime(): void
    {
        $id = $this->guias->seed($this->empresa);

        $response = $this->register($id, ['canal' => 'whatsapp', 'mensagem' => "Olá!\nGuia vence amanhã."]);

        $body = $this->json($response);
        $this->assertSame(201, $response->status);
        $this->assertSame(1, $body['id']);
        $this->assertSame('whatsapp', $body['canal']);
        $this->assertSame('2026-11-16 10:00:00', $body['criadoEm']);
        $row = $this->lembretes->rows[0];
        $this->assertSame($id, $row->obrigacaoId);
        $this->assertSame(5, $row->usuarioId);
        $this->assertSame(LembreteCanal::Whatsapp, $row->canal);
        $this->assertSame("Olá!\nGuia vence amanhã.", $row->mensagem);
    }

    public function testEveryChannelIsAccepted(): void
    {
        $id = $this->guias->seed($this->empresa);

        foreach (['whatsapp', 'email', 'copiado'] as $canal) {
            $this->assertSame(201, $this->register($id, ['canal' => $canal, 'mensagem' => 'texto'])->status, $canal);
        }
        $this->assertCount(3, $this->lembretes->rows);
    }

    /** @return array<string, array{0: array<string, string>, 1: string}> */
    public static function invalidRegistrations(): array
    {
        return [
            'canal vazio' => [['canal' => '', 'mensagem' => 'x'], 'canal'],
            'canal inválido' => [['canal' => 'sms', 'mensagem' => 'x'], 'canal'],
            'sem canal' => [['mensagem' => 'x'], 'canal'],
            'mensagem vazia' => [['canal' => 'email', 'mensagem' => ''], 'mensagem'],
            'mensagem só espaços' => [['canal' => 'email', 'mensagem' => "  \n "], 'mensagem'],
            'sem mensagem' => [['canal' => 'email'], 'mensagem'],
            'mensagem longa' => [['canal' => 'email', 'mensagem' => str_repeat('a', 2001)], 'mensagem'],
        ];
    }

    /** @param array<string, string> $post */
    #[DataProvider('invalidRegistrations')]
    public function testInvalidRegistrationReturns422WithFieldErrors(array $post, string $field): void
    {
        $id = $this->guias->seed($this->empresa);

        $response = $this->register($id, $post);

        $error = $this->json($response)['error'];
        $this->assertSame(422, $response->status);
        $this->assertSame('VALIDATION_FAILED', $error['code']);
        $this->assertArrayHasKey($field, $error['fields']);
        $this->assertSame([], $this->lembretes->rows);
    }

    public function testMessageOfExactlyTheLimitIsAccepted(): void
    {
        $id = $this->guias->seed($this->empresa);

        $this->assertSame(201, $this->register($id, ['canal' => 'copiado', 'mensagem' => str_repeat('a', 2000)])->status);
    }

    public function testRegisterForUnknownGuideIs404(): void
    {
        $response = $this->register(99, ['canal' => 'email', 'mensagem' => 'x']);

        $this->assertSame(404, $response->status);
        $this->assertSame('NOT_FOUND', $this->json($response)['error']['code']);
    }

    #[DataProvider('closedStatuses')]
    public function testRegisterForPaidOrCancelledGuideIs422(ObrigacaoStatus $status): void
    {
        $id = $this->guias->seed($this->empresa, 'DAS', '2026-10-01', '2026-11-10', $status);

        $response = $this->register($id, ['canal' => 'email', 'mensagem' => 'x']);

        $this->assertSame(422, $response->status);
        $this->assertSame('NOT_REMINDABLE', $this->json($response)['error']['code']);
        $this->assertSame([], $this->lembretes->rows);
    }

    public function testRegisterIgnoresClientSuppliedUserAndTimestamp(): void
    {
        $id = $this->guias->seed($this->empresa);

        $this->register($id, ['canal' => 'email', 'mensagem' => 'x', 'usuario_id' => '99', 'criado_em' => '2000-01-01']);

        $this->assertSame(5, $this->lembretes->rows[0]->usuarioId);
        $this->assertSame('2026-11-16', $this->lembretes->rows[0]->criadoEm->format('Y-m-d'));
    }
}

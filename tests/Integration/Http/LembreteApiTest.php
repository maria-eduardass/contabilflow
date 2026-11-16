<?php

declare(strict_types=1);

namespace ContabilFlow\Tests\Integration\Http;

use ContabilFlow\Tests\Support\Http\HttpTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Lembrete rápido: prévia e registro do envio.
 * Guias do seed (hoje = 16/11): 2 FGTS vencida | 1 DAS amanhã | 5 ISS hoje (empresa 2, tem WhatsApp e e-mail) |
 * 4 DARF +5 | 3 INSS +20 | 9 OUTRA (empresa 3, sem WhatsApp nem e-mail) | 6 paga | 8 cancelada.
 */
final class LembreteApiTest extends HttpTestCase
{
    /** @return array<string, mixed> */
    private function preview(int $id): array
    {
        return $this->json($this->contador()->api('GET', "/api/obrigacoes/{$id}/lembrete"));
    }

    // ---------- prévia ----------

    /** @return array<string, array{0: int, 1: string, 2: string}> */
    public static function tones(): array
    {
        return [
            'vencida' => [2, 'preto', 'vencida desde 13/11/2026 (3 dias de atraso)'],
            'amanhã' => [1, 'vermelho', 'vence amanhã'],
            'hoje' => [5, 'vermelho', 'vence HOJE'],
            'em 5 dias' => [4, 'amarelo', 'vence em 5 dias'],
            'no prazo' => [3, 'verde', 'Passando para lembrar da guia abaixo, que vence em 06/12/2026'],
        ];
    }

    #[DataProvider('tones')]
    public function testMessageToneFollowsTheTrafficLight(int $guide, string $color, string $phrase): void
    {
        $data = $this->preview($guide);

        $this->assertSame($color, $data['semaforo']);
        $this->assertStringContainsString($phrase, $data['mensagem']);
        $this->assertStringStartsWith('Olá, ', $data['mensagem']);
        $this->assertStringContainsString('Qualquer dúvida, é só falar.', $data['mensagem']);
    }

    public function testPreviewHasGuideDetailsAndLinksThatCarryTheExactMessage(): void
    {
        $data = $this->preview(5); // ISS da Oficina Silva

        $this->assertStringStartsWith('Olá, Oficina Mecânica Silva ME!', $data['mensagem']);
        $this->assertStringContainsString('Guia: ISS', $data['mensagem']);
        $this->assertStringContainsString('Competência: 10/2026', $data['mensagem']);
        $this->assertStringContainsString('Vencimento: 16/11/2026', $data['mensagem']);
        $this->assertStringContainsString('Valor: R$ 452,00', $data['mensagem']);
        $this->assertStringStartsWith('https://wa.me/5521998765432?text=', $data['whatsappUrl']);
        $this->assertSame($data['mensagem'], rawurldecode(explode('?text=', $data['whatsappUrl'], 2)[1]));
        parse_str((string) parse_url($data['mailtoUrl'], PHP_URL_QUERY), $mail);
        $this->assertStringStartsWith('mailto:silva@oficina.example?subject=', $data['mailtoUrl']);
        $this->assertSame($data['mensagem'], $mail['body']);
        $this->assertSame('Lembrete: ISS vence em 16/11/2026', $mail['subject']);
    }

    public function testCompanyWithoutContactsGetsTheTextButNoLinks(): void
    {
        $data = $this->preview(9); // empresa 3 (Tech Soluções): sem WhatsApp nem e-mail

        $this->assertNull($data['whatsappUrl']);
        $this->assertNull($data['mailtoUrl']);
        $this->assertStringContainsString('Parcelamento de débitos', $data['mensagem']);
        $this->assertStringStartsWith('Olá, TechSol!', $data['mensagem']);
    }

    public function testPreviewOfPaidOrCancelledGuideIs422(): void
    {
        foreach ([6, 8] as $id) {
            $response = $this->contador()->api('GET', "/api/obrigacoes/{$id}/lembrete");

            $this->assertSame(422, $response->status, "guia {$id}");
            $this->assertSame('NOT_REMINDABLE', $this->json($response)['error']['code']);
        }
    }

    public function testPreviewOfUnknownGuideIs404Json(): void
    {
        $response = $this->contador()->api('GET', '/api/obrigacoes/999/lembrete');

        $this->assertSame(404, $response->status);
        $this->assertSame('NOT_FOUND', $this->json($response)['error']['code']);
    }

    public function testPreviewDoesNotRegisterAnything(): void
    {
        $this->preview(1);
        $this->preview(2);

        $this->assertSame(0, $this->rows('lembretes'));
    }

    public function testMessageReflectsTheClockAfterTimePasses(): void
    {
        $contador = $this->contador();
        $this->assertSame('vermelho', $this->json($contador->api('GET', '/api/obrigacoes/1/lembrete'))['semaforo']);

        $this->clock->advance('+5 days');

        $data = $this->json($contador->api('GET', '/api/obrigacoes/1/lembrete'));
        $this->assertSame('preto', $data['semaforo']);
        $this->assertStringContainsString('vencida desde 17/11/2026 (4 dias de atraso)', $data['mensagem']);
    }

    // ---------- registro ----------

    public function testRegisteringASendStoresUserChannelMessageAndTime(): void
    {
        $response = $this->contador()->api('POST', '/api/obrigacoes/2/lembretes', [
            'canal' => 'whatsapp', 'mensagem' => "Olá!\nSua guia venceu.",
        ]);

        $body = $this->json($response);
        $this->assertSame(201, $response->status);
        $this->assertSame(1, $body['id']);
        $this->assertSame('whatsapp', $body['canal']);
        $this->assertSame('2026-11-16 10:00:00', $body['criadoEm']);
        $row = $this->row('SELECT * FROM lembretes WHERE id = 1');
        $this->assertSame(2, (int) $row['obrigacao_id']);
        $this->assertSame(2, (int) $row['usuario_id']); // o contador logado
        $this->assertSame('whatsapp', $row['canal']);
        $this->assertSame("Olá!\nSua guia venceu.", $row['mensagem']);
        $this->assertSame('2026-11-16 10:00:00', $row['criado_em']);
    }

    public function testEveryChannelCanBeRegistered(): void
    {
        $admin = $this->admin();

        foreach (['whatsapp', 'email', 'copiado'] as $canal) {
            $this->assertSame(201, $admin->api('POST', '/api/obrigacoes/1/lembretes', ['canal' => $canal, 'mensagem' => 'x'])->status);
        }
        $this->assertSame(3, $this->rows('lembretes'));
    }

    /** @return array<string, array{0: array<string, string>, 1: string}> */
    public static function invalidRegistrations(): array
    {
        return [
            'canal inválido' => [['canal' => 'sms', 'mensagem' => 'x'], 'canal'],
            'sem canal' => [['mensagem' => 'x'], 'canal'],
            'mensagem vazia' => [['canal' => 'email', 'mensagem' => ''], 'mensagem'],
            'mensagem só espaços' => [['canal' => 'email', 'mensagem' => "   \n "], 'mensagem'],
            'mensagem longa' => [['canal' => 'email', 'mensagem' => str_repeat('a', 2001)], 'mensagem'],
            'utf-8 inválido' => [['canal' => 'email', 'mensagem' => "ol\xE1"], 'mensagem'],
        ];
    }

    /** @param array<string, string> $post */
    #[DataProvider('invalidRegistrations')]
    public function testInvalidRegistrationsAre422WithFieldErrors(array $post, string $field): void
    {
        $response = $this->contador()->api('POST', '/api/obrigacoes/1/lembretes', $post);

        $error = $this->json($response)['error'];
        $this->assertSame(422, $response->status);
        $this->assertSame('VALIDATION_FAILED', $error['code']);
        $this->assertArrayHasKey($field, $error['fields']);
        $this->assertSame(0, $this->rows('lembretes'));
    }

    public function testMessageOfExactlyTheLimitIsAccepted(): void
    {
        $response = $this->contador()->api('POST', '/api/obrigacoes/1/lembretes', ['canal' => 'copiado', 'mensagem' => str_repeat('a', 2000)]);

        $this->assertSame(201, $response->status);
    }

    public function testRegistrationForPaidCancelledOrUnknownGuideIsRejected(): void
    {
        $contador = $this->contador();

        $this->assertSame(422, $contador->api('POST', '/api/obrigacoes/6/lembretes', ['canal' => 'email', 'mensagem' => 'x'])->status);
        $this->assertSame(422, $contador->api('POST', '/api/obrigacoes/8/lembretes', ['canal' => 'email', 'mensagem' => 'x'])->status);
        $this->assertSame(404, $contador->api('POST', '/api/obrigacoes/999/lembretes', ['canal' => 'email', 'mensagem' => 'x'])->status);
        $this->assertSame(0, $this->rows('lembretes'));
    }

    public function testClientCannotChooseTheUserOrTheTimestamp(): void
    {
        $this->contador()->api('POST', '/api/obrigacoes/1/lembretes', [
            'canal' => 'email', 'mensagem' => 'x', 'usuario_id' => '1', 'criado_em' => '2000-01-01 00:00:00',
        ]);

        $row = $this->row('SELECT usuario_id, criado_em FROM lembretes');
        $this->assertSame(2, (int) $row['usuario_id']);
        $this->assertSame('2026-11-16 10:00:00', $row['criado_em']);
    }

    public function testAccentsEmojiAndSymbolsSurviveTheRoundTrip(): void
    {
        $text = "Olá 👋 ação — R\$ 1.234,50; A&B #1 100% <b>";

        $this->contador()->api('POST', '/api/obrigacoes/1/lembretes', ['canal' => 'copiado', 'mensagem' => $text]);

        $this->assertSame($text, $this->scalar('SELECT mensagem FROM lembretes'));
    }

    // ---------- histórico na tela ----------

    public function testHistoryAppearsOnTheGuidePageNewestFirstAndEscaped(): void
    {
        $contador = $this->contador();
        $contador->api('POST', '/api/obrigacoes/1/lembretes', ['canal' => 'copiado', 'mensagem' => 'primeiro envio']);
        $this->clock->advance('+1 hour');
        $contador->api('POST', '/api/obrigacoes/1/lembretes', ['canal' => 'whatsapp', 'mensagem' => '<script>alert(1)</script>']);

        $page = $contador->get('/obrigacoes/1');

        $this->assertBodyContains('Histórico de lembretes', $page);
        $this->assertBodyContains('Texto copiado', $page);
        $this->assertBodyContains('WhatsApp', $page);
        $this->assertBodyContains('por Contador Demo', $page);
        $this->assertBodyContains('16/11/2026 11:00', $page);
        $this->assertBodyContains('&lt;script&gt;alert(1)&lt;/script&gt;', $page);
        $this->assertBodyNotContains('<script>alert(1)', $page);
        $this->assertLessThan(strpos($page->body, 'primeiro envio'), strpos($page->body, '16/11/2026 11:00'));
    }

    public function testHistoryIsPerGuide(): void
    {
        $this->contador()->api('POST', '/api/obrigacoes/1/lembretes', ['canal' => 'email', 'mensagem' => 'só da guia 1']);

        $this->assertBodyContains('Nenhum lembrete gerado', $this->contador()->get('/obrigacoes/2'));
    }

    public function testReminderButtonAndModalOnlyOnOpenGuides(): void
    {
        $admin = $this->admin();

        foreach ([1, 2, 3, 5] as $open) {
            $this->assertBodyContains('data-lembrete-abrir', $admin->get("/obrigacoes/{$open}"));
        }
        foreach ([6, 8] as $closed) {
            $page = $admin->get("/obrigacoes/{$closed}");
            $this->assertBodyNotContains('data-lembrete-abrir', $page);
            $this->assertBodyNotContains('lembrete-modal', $page);
        }
    }

    public function testDeletingTheGuideDeletesItsHistory(): void
    {
        $admin = $this->admin();
        $admin->api('POST', '/api/obrigacoes/3/lembretes', ['canal' => 'email', 'mensagem' => 'x']);

        $admin->delete('/obrigacoes/3');

        $this->assertSame(0, $this->rows('lembretes'));
    }
}

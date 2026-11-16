<?php

declare(strict_types=1);

namespace ContabilFlow\Tests\Unit;

use ContabilFlow\Models\Empresa;
use ContabilFlow\Models\InvalidStateException;
use ContabilFlow\Models\Obrigacao;
use ContabilFlow\Models\ObrigacaoStatus;
use ContabilFlow\Models\Semaforo;
use ContabilFlow\Services\LembreteService;
use ContabilFlow\Services\SemaforoService;
use ContabilFlow\Tests\Support\FixedClock;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class LembreteServiceTest extends TestCase
{
    private LembreteService $service;

    protected function setUp(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2026-11-16 10:00:00'));
        $this->service = new LembreteService(new SemaforoService($clock));
    }

    private function empresa(
        ?string $whatsapp = '5511987654321',
        ?string $email = 'contato@padaria.com',
        ?string $fantasia = 'Pão Quente',
    ): Empresa {
        return new Empresa(1, 'Padaria Pão Quente Ltda', $fantasia, '11222333000181', 'simples', $email, $whatsapp, null, true, 1);
    }

    private function guia(
        string $vencimento = '2026-11-20',
        ObrigacaoStatus $status = ObrigacaoStatus::Pendente,
        ?string $valor = '1234.50',
        ?string $descricao = null,
        string $tipo = 'DAS',
    ): Obrigacao {
        return new Obrigacao(
            7,
            1,
            'Padaria Pão Quente Ltda',
            $tipo,
            $descricao,
            new DateTimeImmutable('2026-10-01'),
            new DateTimeImmutable($vencimento),
            $valor,
            $status,
            null,
            null,
            null,
            1,
            1
        );
    }

    private function text(Obrigacao $guia, ?Empresa $empresa = null): string
    {
        return $this->service->montar($guia, $empresa ?? $this->empresa())->mensagem;
    }

    // ---------- tom por cor ----------

    public function testOverdueTone(): void
    {
        $text = $this->text($this->guia('2026-11-10'));

        $this->assertStringContainsString('está vencida desde 10/11/2026 (6 dias de atraso)', $text);
        $this->assertStringContainsString('regularizar o quanto antes', $text);
    }

    public function testOverdueByOneDayUsesSingular(): void
    {
        $this->assertStringContainsString('(1 dia de atraso)', $this->text($this->guia('2026-11-15')));
    }

    public function testDueTodayTomorrowAndInTwoDays(): void
    {
        $this->assertStringContainsString('vence HOJE', $this->text($this->guia('2026-11-16')));
        $this->assertStringContainsString('vence amanhã', $this->text($this->guia('2026-11-17')));
        $this->assertStringContainsString('vence em 2 dias', $this->text($this->guia('2026-11-18')));
    }

    public function testYellowTone(): void
    {
        $this->assertStringContainsString('Lembrete: a guia abaixo vence em 5 dias', $this->text($this->guia('2026-11-21')));
    }

    public function testGreenIsAFriendlyReminderWithTheDate(): void
    {
        $text = $this->text($this->guia('2026-12-10'));

        $this->assertStringContainsString('Passando para lembrar da guia abaixo, que vence em 10/12/2026', $text);
        $this->assertStringNotContainsString('Atenção', $text);
    }

    public function testEachColorMapsToItsSemaphore(): void
    {
        $this->assertSame(Semaforo::Preto, $this->service->montar($this->guia('2026-11-10'), $this->empresa())->semaforo);
        $this->assertSame(Semaforo::Vermelho, $this->service->montar($this->guia('2026-11-16'), $this->empresa())->semaforo);
        $this->assertSame(Semaforo::Amarelo, $this->service->montar($this->guia('2026-11-21'), $this->empresa())->semaforo);
        $this->assertSame(Semaforo::Verde, $this->service->montar($this->guia('2026-12-10'), $this->empresa())->semaforo);
    }

    // ---------- conteúdo ----------

    public function testMessageHasGreetingGuideDetailsAndValue(): void
    {
        $text = $this->text($this->guia(descricao: 'Parcela 3'));

        $this->assertStringStartsWith("Olá, Pão Quente!\n", $text);
        $this->assertStringContainsString('Guia: DAS - Parcela 3', $text);
        $this->assertStringContainsString('Competência: 10/2026', $text);
        $this->assertStringContainsString('Vencimento: 20/11/2026', $text);
        $this->assertStringContainsString('Valor: R$ 1.234,50', $text);
        $this->assertStringEndsWith('Qualquer dúvida, é só falar. Obrigado!', $text);
    }

    public function testGreetingFallsBackToLegalNameWithoutTradeName(): void
    {
        $text = $this->text($this->guia(), $this->empresa(fantasia: null));

        $this->assertStringStartsWith('Olá, Padaria Pão Quente Ltda!', $text);
    }

    public function testValueLineIsOmittedWhenThereIsNoValue(): void
    {
        $text = $this->text($this->guia(valor: null));

        $this->assertStringNotContainsString('Valor:', $text);
        $this->assertStringNotContainsString('R$', $text);
    }

    public function testSentGuideStillGetsAReminder(): void
    {
        $text = $this->text($this->guia('2026-11-10', ObrigacaoStatus::Enviada));

        $this->assertStringContainsString('vencida desde', $text);
    }

    // ---------- links ----------

    public function testWhatsappLinkCarriesTheEncodedMessage(): void
    {
        $pronto = $this->service->montar($this->guia(), $this->empresa());

        $this->assertNotNull($pronto->whatsappUrl);
        $this->assertStringStartsWith('https://wa.me/5511987654321?text=', $pronto->whatsappUrl);
        $this->assertSame($pronto->mensagem, rawurldecode(substr($pronto->whatsappUrl, strlen('https://wa.me/5511987654321?text='))));
        $this->assertDoesNotMatchRegularExpression('/[\s"<>]/', $pronto->whatsappUrl);
    }

    public function testSpecialCharactersCannotBreakOrInjectUrlParameters(): void
    {
        $guia = $this->guia(descricao: 'A&B #1 100% ?x=y+z ação <b> "q"');

        $pronto = $this->service->montar($guia, $this->empresa());

        $query = (string) $pronto->whatsappUrl;
        $this->assertSame(1, substr_count($query, '?'));
        $this->assertStringNotContainsString('&', $query);
        $this->assertStringNotContainsString('#', $query);
        $this->assertSame($pronto->mensagem, rawurldecode(explode('?text=', $query, 2)[1]));
    }

    public function testMailtoLinkHasSubjectAndBody(): void
    {
        $pronto = $this->service->montar($this->guia('2026-11-20'), $this->empresa());

        $this->assertNotNull($pronto->mailtoUrl);
        $this->assertStringStartsWith('mailto:contato@padaria.com?subject=', $pronto->mailtoUrl);
        parse_str((string) parse_url($pronto->mailtoUrl, PHP_URL_QUERY), $query);
        $this->assertSame('Lembrete: DAS vence em 20/11/2026', $query['subject']);
        $this->assertSame($pronto->mensagem, $query['body']);
    }

    public function testMailtoSubjectSaysOverdueForOverdueGuides(): void
    {
        $pronto = $this->service->montar($this->guia('2026-11-10'), $this->empresa());

        parse_str((string) parse_url((string) $pronto->mailtoUrl, PHP_URL_QUERY), $query);
        $this->assertSame('Lembrete: DAS vencida em 10/11/2026', $query['subject']);
    }

    public function testMailtoAddressCannotInjectHeaders(): void
    {
        $empresa = $this->empresa(email: "x@y.com\r\nBcc: spy@evil.com");

        $url = (string) $this->service->montar($this->guia(), $empresa)->mailtoUrl;

        $this->assertStringNotContainsString("\r", $url);
        $this->assertStringNotContainsString("\n", $url);
        $this->assertStringNotContainsString(' ', $url);
        $this->assertStringContainsString('%0D%0ABcc%3A', $url);
    }

    public function testMailtoKeepsPlusAndAtInTheAddress(): void
    {
        $url = (string) $this->service->montar($this->guia(), $this->empresa(email: 'ana+fiscal@x.com'))->mailtoUrl;

        $this->assertStringStartsWith('mailto:ana%2Bfiscal@x.com?subject=', $url);
    }

    public function testLinksAreNullWhenTheCompanyHasNoContact(): void
    {
        $pronto = $this->service->montar($this->guia(), $this->empresa(whatsapp: null, email: null));

        $this->assertNull($pronto->whatsappUrl);
        $this->assertNull($pronto->mailtoUrl);
        $this->assertNotSame('', $pronto->mensagem);
    }

    public function testOnlyTheAvailableChannelGetsALink(): void
    {
        $semEmail = $this->service->montar($this->guia(), $this->empresa(email: null));
        $semZap = $this->service->montar($this->guia(), $this->empresa(whatsapp: null));

        $this->assertNotNull($semEmail->whatsappUrl);
        $this->assertNull($semEmail->mailtoUrl);
        $this->assertNull($semZap->whatsappUrl);
        $this->assertNotNull($semZap->mailtoUrl);
    }

    // ---------- estados ----------

    public function testPaidAndCancelledGuidesCannotHaveReminders(): void
    {
        foreach ([ObrigacaoStatus::Paga, ObrigacaoStatus::Cancelada] as $status) {
            try {
                $this->service->montar($this->guia(status: $status), $this->empresa());
                $this->fail('Deveria lançar InvalidStateException para ' . $status->value);
            } catch (InvalidStateException $e) {
                $this->assertStringContainsString('não precisa de lembrete', $e->getMessage());
            }
        }
    }

    public function testToArrayExposesTheApiPayload(): void
    {
        $data = $this->service->montar($this->guia('2026-11-10'), $this->empresa())->toArray();

        $this->assertSame(
            ['mensagem', 'whatsappUrl', 'mailtoUrl', 'semaforo', 'semaforoLabel', 'semaforoBadge'],
            array_keys($data)
        );
        $this->assertSame('preto', $data['semaforo']);
        $this->assertSame('Vencida', $data['semaforoLabel']);
        $this->assertSame('text-bg-dark', $data['semaforoBadge']);
    }
}

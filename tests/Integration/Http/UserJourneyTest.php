<?php

declare(strict_types=1);

namespace ContabilFlow\Tests\Integration\Http;

use ContabilFlow\Tests\Support\Http\HttpTestCase;

/**
 * Os 7 cenários de ponta a ponta do plano (no lugar do Playwright, que exige
 * Node.js), percorridos como uma pessoa usaria o sistema: login, formulários,
 * redirecionamentos, mensagens, tela por tela.
 */
final class UserJourneyTest extends HttpTestCase
{
    /** Cenário 1: login inválido e depois válido. */
    public function testScenario1InvalidThenValidLogin(): void
    {
        $browser = $this->browser();

        $wrong = $browser->login(self::CONTADOR_EMAIL, 'senha-errada-123');
        $this->assertSame(401, $wrong->status);
        $this->assertSame('E-mail ou senha inválidos.', $this->alertText($wrong));
        $this->assertRedirectTo('/login', $browser->get('/empresas'));

        $right = $browser->login(self::CONTADOR_EMAIL, self::CONTADOR_PASSWORD);
        $page = $browser->follow($right);

        $this->assertRedirectTo('/', $right);
        $this->assertSame(200, $page->status);
        $this->assertBodyContains('Painel', $page);
        $this->assertBodyContains('Contador Demo', $page);
    }

    /** Cenário 2: criar empresa com CNPJ inválido e depois corrigir. */
    public function testScenario2CreateCompanyWithInvalidCnpjThenFixIt(): void
    {
        $browser = $this->contador();
        $data = [
            'razao_social' => 'Mercearia Nova Ltda', 'cnpj' => '12.345.678/0001-00', 'regime' => 'simples',
            'email' => 'contato@nova.com', 'whatsapp' => '(11) 98765-1234', 'ativo' => '1',
        ];

        $invalid = $browser->post('/empresas', $data);
        $this->assertSame(422, $invalid->status);
        $this->assertBodyContains('CNPJ inválido', $invalid);
        $this->assertBodyContains('value="Mercearia Nova Ltda"', $invalid);   // o que foi digitado não se perde
        $this->assertSame(3, $this->rows('empresas'));

        $fixed = $browser->post('/empresas', ['cnpj' => '10.000.000/0001-45'] + $data);
        $page = $browser->follow($fixed);

        $this->assertRedirectTo('/empresas/4', $fixed);
        $this->assertBodyContains('Mercearia Nova Ltda', $page);
        $this->assertBodyContains('10.000.000/0001-45', $page);
        $this->assertSame('Empresa cadastrada.', $this->alertText($page));
        $this->assertBodyContains('Mercearia Nova Ltda', $browser->get('/empresas'));
    }

    /** Cenário 3: criar guia e vê-la com a cor certa no painel. */
    public function testScenario3CreateAGuideAndSeeItWithTheRightColorOnTheDashboard(): void
    {
        $browser = $this->contador();
        $before = $this->json($browser->get('/api/dashboard/indicadores', ['Accept' => 'application/json']));

        $created = $browser->post('/obrigacoes', [
            'empresa_id' => '3', 'tipo' => 'ISS', 'competencia' => '2026-10', 'vencimento' => '2026-11-17', 'valor' => '500,00',
        ]);
        $guide = $browser->follow($created);

        $this->assertBodyContains('Vence em até 2 dias', $guide);       // amanhã: vermelho
        $dashboard = $browser->get('/');
        $after = $this->json($browser->get('/api/dashboard/indicadores', ['Accept' => 'application/json']));
        $this->assertSame($before['ate2dias'] + 1, $after['ate2dias']);
        $this->assertSame('3362.00', $after['valorPendente']);
        $this->assertBodyContains('17/11/2026', $dashboard);
        $this->assertBodyContains('Tech Soluções S.A.', $dashboard);
        $this->assertBodyContains('text-bg-danger', $dashboard);
        $this->assertGreaterThanOrEqual(1, substr_count($browser->get('/obrigacoes?semaforo=vermelho&empresa_id=3')->body, 'text-bg-danger'));
    }

    /** Cenário 4: gerar o lembrete e conferir que o link do WhatsApp contém o texto. */
    public function testScenario4ReminderLinkCarriesTheMessage(): void
    {
        $browser = $this->contador();
        $this->assertBodyContains('Gerar lembrete', $browser->get('/obrigacoes/2'));

        $data = $this->json($browser->api('GET', '/api/obrigacoes/2/lembrete'));
        $browser->api('POST', '/api/obrigacoes/2/lembretes', ['canal' => 'whatsapp', 'mensagem' => $data['mensagem']]);

        $this->assertStringStartsWith('https://wa.me/5511987654321?text=', $data['whatsappUrl']);
        $this->assertStringContainsString(rawurlencode('Guia: FGTS'), $data['whatsappUrl']);
        $this->assertStringContainsString(rawurlencode('vencida desde 13/11/2026'), $data['whatsappUrl']);
        $history = $browser->get('/obrigacoes/2');
        $this->assertBodyContains('Histórico de lembretes', $history);
        $this->assertBodyContains('WhatsApp', $history);
        $this->assertBodyContains('vencida desde 13/11/2026', $history);
    }

    /** Cenário 5: marcar como paga e ver o semáforo ficar cinza. */
    public function testScenario5MarkAsPaidAndSeeTheTrafficLightTurnGrey(): void
    {
        $browser = $this->contador();
        $this->assertBodyContains('Vencida', $browser->get('/obrigacoes/2'));
        $this->assertBodyContains('text-bg-dark', $browser->get('/obrigacoes/2'));

        $response = $browser->patch('/obrigacoes/2/status', ['status' => 'paga', 'versao' => '1']);
        $page = $browser->follow($response);

        $this->assertSame('Guia marcada como paga.', $this->alertText($page));
        $this->assertBodyContains('Paga ou cancelada', $page);
        $this->assertBodyContains('text-bg-secondary', $page);
        $this->assertBodyNotContains('Gerar lembrete', $page);
        $this->assertBodyNotContains('Vencida', $page);
        $this->assertSame(0, $this->json($browser->get('/api/dashboard/indicadores', ['Accept' => 'application/json']))['vencidas']);
    }

    /** Cenário 6: cada perfil só enxerga o que lhe cabe (o "cliente" foi substituído pelo controle admin x contador). */
    public function testScenario6EachProfileOnlySeesWhatItShould(): void
    {
        $anonymous = $this->browser();
        $contador = $this->contador();
        $admin = $this->admin();

        $this->assertRedirectTo('/login', $anonymous->get('/empresas'));
        $this->assertSame(403, $contador->get('/usuarios')->status);
        $this->assertSame(403, $contador->get('/avaliacoes/resumo')->status);
        $this->assertStringNotContainsString('href="/usuarios"', $contador->get('/')->body);

        $this->assertSame(200, $admin->get('/usuarios')->status);
        $this->assertSame(200, $admin->get('/avaliacoes/resumo')->status);
        $this->assertStringContainsString('href="/usuarios"', $admin->get('/')->body);
    }

    /** Cenário 7: enviar a avaliação e o administrador vê o resultado. */
    public function testScenario7SendTheEvaluationAndTheAdminSeesTheResult(): void
    {
        $contador = $this->contador();
        $form = $contador->get('/avaliacao');
        $this->assertBodyContains('Avaliar o sistema', $form);

        $sent = $contador->post('/avaliacao', [
            'q1' => '5', 'q2' => '5', 'q3' => '4', 'q4' => '4', 'q5' => '5', 'nps' => '10', 'comentario' => 'Substitui minha planilha!',
        ]);
        $this->assertStringContainsString('Obrigado pela avaliação', (string) $this->alertText($contador->follow($sent)));

        $summary = $this->admin()->get('/avaliacoes/resumo');
        $this->assertBodyContains('Zona de excelência', $summary);
        $this->assertBodyContains('Substitui minha planilha!', $summary);
        $this->assertBodyContains('4,60', $summary);   // média geral: 23 / 5
    }

    /** Fecha o ciclo: um dia de trabalho inteiro, do login ao logout. */
    public function testAFullWorkdayFromLoginToLogout(): void
    {
        $browser = $this->browser();
        $browser->login(self::CONTADOR_EMAIL, self::CONTADOR_PASSWORD);

        $browser->post('/empresas', [
            'razao_social' => 'Loja do Dia Ltda', 'cnpj' => '10.000.000/0001-45', 'regime' => 'mei',
            'email' => 'dia@loja.com', 'whatsapp' => '(11) 98765-1234', 'ativo' => '1',
        ]);
        $browser->post('/obrigacoes', ['empresa_id' => '4', 'tipo' => 'DAS', 'competencia' => '2026-10', 'vencimento' => '2026-11-18', 'valor' => '75,00']);
        $browser->api('POST', '/api/obrigacoes/10/lembretes', ['canal' => 'email', 'mensagem' => 'lembrete do dia']);
        $browser->patch('/obrigacoes/10/status', ['status' => 'enviada', 'versao' => '1']);
        $browser->patch('/obrigacoes/10/status', ['status' => 'paga', 'versao' => '2']);
        $browser->post('/avaliacao', ['q1' => '5', 'q2' => '5', 'q3' => '5', 'q4' => '5', 'q5' => '5', 'nps' => '10']);

        $this->assertSame('paga', $this->scalar('SELECT status FROM obrigacoes WHERE id = 10'));
        $this->assertSame(1, $this->rows('lembretes'));
        $this->assertSame(1, $this->rows('avaliacoes'));
        $this->assertRedirectTo('/login', $browser->post('/logout'));
        $this->assertRedirectTo('/login', $browser->get('/'));
    }
}

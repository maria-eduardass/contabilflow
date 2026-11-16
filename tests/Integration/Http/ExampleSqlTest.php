<?php

declare(strict_types=1);

namespace ContabilFlow\Tests\Integration\Http;

use ContabilFlow\Models\Semaforo;
use ContabilFlow\Services\SemaforoService;
use ContabilFlow\Tests\Support\FixedClock;
use ContabilFlow\Tests\Support\Http\HttpTestCase;
use ContabilFlow\Validation\Rules\Cnpj;
use ContabilFlow\Validation\Rules\Whatsapp;
use DateTimeImmutable;

/**
 * Executa o database/dados_exemplo.sql de verdade e confere o resultado, para o
 * script documentado no README nunca ficar quebrado ou desatualizado.
 */
final class ExampleSqlTest extends HttpTestCase
{
    private const DEMO_PASSWORD = 'Demo@Contabil2026';
    private const FILE = __DIR__ . '/../../../database/dados_exemplo.sql';

    protected function setUp(): void
    {
        parent::setUp();
        $this->emptyAllTables();
    }

    private function emptyAllTables(): void
    {
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['avaliacoes', 'lembretes', 'obrigacoes', 'empresas', 'usuarios'] as $table) {
            $this->pdo->exec('TRUNCATE TABLE ' . $table);
        }
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    private function loadExampleData(): void
    {
        $sql = file_get_contents(self::FILE);
        $this->assertIsString($sql);
        $this->pdo->exec($sql);

        // O script usa CURDATE() do MySQL: o relógio do app segue o mesmo dia.
        $today = (string) $this->scalar('SELECT CURDATE()');
        $this->clock = new FixedClock(new DateTimeImmutable($today . ' 10:00:00'));
    }

    // ---------- conteúdo carregado ----------

    public function testScriptLoadsEverythingWithoutErrors(): void
    {
        $this->loadExampleData();

        $this->assertSame(3, $this->rows('usuarios'));
        $this->assertSame(5, $this->rows('empresas'));
        $this->assertSame(14, $this->rows('obrigacoes'));
        $this->assertSame(3, $this->rows('lembretes'));
        $this->assertSame(4, $this->rows('avaliacoes'));
    }

    public function testUsersHaveTheDocumentedPasswordAndProfiles(): void
    {
        $this->loadExampleData();

        $expected = [
            'admin@demo.contabilflow.local' => ['admin', 1],
            'contador@demo.contabilflow.local' => ['contador', 1],
            'estagio@demo.contabilflow.local' => ['contador', 0],
        ];
        foreach ($expected as $email => [$perfil, $ativo]) {
            $row = $this->row('SELECT perfil, ativo, senha_hash FROM usuarios WHERE email = ?', [$email]);

            $this->assertSame($perfil, $row['perfil'], $email);
            $this->assertSame($ativo, (int) $row['ativo'], $email);
            $this->assertTrue(password_verify(self::DEMO_PASSWORD, (string) $row['senha_hash']), $email);
        }
    }

    public function testCompaniesHaveValidCnpjAndWhatsappAndTheDocumentedVariety(): void
    {
        $this->loadExampleData();

        $companies = $this->pdo->query('SELECT cnpj, whatsapp, email, ativo, regime FROM empresas');
        $this->assertNotFalse($companies);
        $regimes = [];
        foreach ($companies->fetchAll() as $c) {
            $this->assertNotNull(Cnpj::normalize((string) $c['cnpj']), 'CNPJ inválido: ' . $c['cnpj']);
            if ($c['whatsapp'] !== null) {
                $this->assertNotNull(Whatsapp::normalize((string) $c['whatsapp']), 'WhatsApp inválido: ' . $c['whatsapp']);
            }
            $regimes[$c['regime']] = true;
        }
        ksort($regimes);
        $this->assertSame(['mei', 'presumido', 'real', 'simples'], array_keys($regimes));
        $this->assertSame(1, $this->rows('empresas', 'ativo = 0'));
        $this->assertSame(1, $this->rows('empresas', 'whatsapp IS NULL AND email IS NULL'));
    }

    public function testEveryTrafficLightColorAppearsRegardlessOfTheDayItIsLoaded(): void
    {
        $this->loadExampleData();
        $semaforo = new SemaforoService($this->clock);

        $colors = [];
        $guides = $this->pdo->query('SELECT vencimento, status FROM obrigacoes');
        $this->assertNotFalse($guides);
        foreach ($guides->fetchAll() as $g) {
            $color = SemaforoService::classificarEm(
                \ContabilFlow\Models\ObrigacaoStatus::from((string) $g['status']),
                new DateTimeImmutable((string) $g['vencimento']),
                $semaforo->hoje()
            );
            $colors[$color->value] = ($colors[$color->value] ?? 0) + 1;
        }

        foreach (Semaforo::values() as $color) {
            $this->assertArrayHasKey($color, $colors, "faltou exemplo da cor {$color}");
        }
        $this->assertGreaterThanOrEqual(2, $colors['preto']);
        $this->assertGreaterThanOrEqual(2, $colors['vermelho']);
    }

    public function testGuidesCoverEveryStatusAndKeepTheirInvariants(): void
    {
        $this->loadExampleData();

        foreach (['pendente', 'enviada', 'paga', 'cancelada'] as $status) {
            $this->assertGreaterThanOrEqual(1, $this->rows('obrigacoes', "status = '{$status}'"), $status);
        }
        $this->assertSame(0, $this->rows('obrigacoes', "DAYOFMONTH(competencia) <> 1"));
        $this->assertSame(0, $this->rows('obrigacoes', "(status = 'paga') <> (paga_em IS NOT NULL)"));
        $this->assertSame(0, $this->rows('obrigacoes', "tipo = 'OUTRA' AND descricao IS NULL"));
        $this->assertSame(1, $this->rows('obrigacoes', "tipo = 'OUTRA'"));
    }

    // ---------- repetição e isolamento ----------

    public function testRunningTheScriptTwiceDoesNotDuplicateAnything(): void
    {
        $this->loadExampleData();
        $this->loadExampleData();

        $this->assertSame(3, $this->rows('usuarios'));
        $this->assertSame(5, $this->rows('empresas'));
        $this->assertSame(14, $this->rows('obrigacoes'));
        $this->assertSame(3, $this->rows('lembretes'));
        $this->assertSame(4, $this->rows('avaliacoes'));
    }

    public function testRerunningKeepsDataAddedByUsersAfterTheFirstLoadIntactExceptTheDemoOnes(): void
    {
        $this->loadExampleData();
        $this->pdo->exec("INSERT INTO usuarios (nome, email, senha_hash, perfil) VALUES ('Real', 'real@escritorio.com', 'h', 'contador')");
        $this->pdo->exec("INSERT INTO empresas (razao_social, cnpj, regime) VALUES ('Cliente Real', '12345678000195', 'mei')");

        $this->loadExampleData();

        $this->assertSame(1, $this->rows('usuarios', "email = 'real@escritorio.com'"));
        $this->assertSame(1, $this->rows('empresas', "cnpj = '12345678000195'"));
        $this->assertSame(4, $this->rows('usuarios'));
        $this->assertSame(6, $this->rows('empresas'));
    }

    // ---------- o sistema funciona com os dados de exemplo ----------

    public function testDemoUsersCanLoginAndSeeTheDashboardAndGuides(): void
    {
        $this->loadExampleData();
        $browser = $this->browser();

        $login = $browser->login('contador@demo.contabilflow.local', self::DEMO_PASSWORD);
        $dashboard = $browser->follow($login);
        $indicators = $this->json($browser->get('/api/dashboard/indicadores', ['Accept' => 'application/json']));

        $this->assertRedirectTo('/', $login);
        $this->assertSame(200, $dashboard->status);
        $this->assertGreaterThanOrEqual(2, $indicators['vencidas']);
        $this->assertGreaterThanOrEqual(1, $indicators['pagasMes']);
        $this->assertGreaterThan(0.0, (float) $indicators['valorPendente']);
        $this->assertBodyContains('Pão Quente', $browser->get('/empresas'));
        $this->assertBodyContains('Parcelamento de débitos federais', $browser->get('/obrigacoes'));
    }

    public function testDemoAdminSeesUsersAndTheEvaluationSummary(): void
    {
        $this->loadExampleData();
        $admin = $this->browser();
        $this->assertRedirectTo('/', $admin->login('admin@demo.contabilflow.local', self::DEMO_PASSWORD));

        $users = $admin->get('/usuarios');
        $summary = $admin->get('/avaliacoes/resumo');

        $this->assertBodyContains('estagio@demo.contabilflow.local', $users);
        $this->assertBodyContains('Inativo', $users);
        $this->assertMatchesRegularExpression('/display-6 fw-semibold">4</', $summary->body); // 4 respostas
        $this->assertBodyContains('O lembrete pronto para o WhatsApp', $summary);
        $this->assertBodyContains('Zona de', $summary);
    }

    public function testTheInactiveDemoUserCannotLogin(): void
    {
        $this->loadExampleData();

        $response = $this->browser()->login('estagio@demo.contabilflow.local', self::DEMO_PASSWORD);

        $this->assertSame(401, $response->status);
    }

    public function testRemindersHistoryShowsOnTheGuideDetail(): void
    {
        $this->loadExampleData();
        $contador = $this->browser();
        $contador->login('contador@demo.contabilflow.local', self::DEMO_PASSWORD);
        $guide = (int) $this->scalar("SELECT id FROM obrigacoes WHERE tipo = 'FGTS' AND empresa_id = (SELECT id FROM empresas WHERE cnpj = '11222333000181')");

        $page = $contador->get("/obrigacoes/{$guide}");

        $this->assertBodyContains('Histórico de lembretes', $page);
        $this->assertBodyContains('WhatsApp', $page);
        $this->assertBodyContains('por Contador Demo', $page);
    }
}

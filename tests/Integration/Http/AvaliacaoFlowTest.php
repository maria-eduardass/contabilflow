<?php

declare(strict_types=1);

namespace ContabilFlow\Tests\Integration\Http;

use ContabilFlow\Tests\Support\Http\HttpTestCase;
use ContabilFlow\Tests\Support\Http\TestBrowser;
use PHPUnit\Framework\Attributes\DataProvider;

/** Avaliação de usabilidade: formulário para todos, resumo (médias e NPS) só para administradores. */
final class AvaliacaoFlowTest extends HttpTestCase
{
    /**
     * @param array<string, string> $override
     * @return array<string, string>
     */
    private function answers(array $override = []): array
    {
        return $override + ['q1' => '5', 'q2' => '4', 'q3' => '3', 'q4' => '2', 'q5' => '1', 'nps' => '9', 'comentario' => ''];
    }

    /** @param array<string, string> $override */
    private function submit(TestBrowser $browser, array $override = []): void
    {
        $browser->post('/avaliacao', $this->answers($override));
    }

    // ---------- formulário ----------

    public function testFormShowsTheFiveQuestionsTheScaleAndTheNpsForEveryone(): void
    {
        foreach ([$this->contador(), $this->admin()] as $browser) {
            $response = $browser->get('/avaliacao');

            $this->assertSame(200, $response->status);
            $this->assertSame(36, substr_count($response->body, 'type="radio"')); // 5 perguntas x 5 notas + NPS 0 a 10
            $this->assertBodyContains('O sistema é fácil de usar.', $response);
            $this->assertBodyContains('As cores do semáforo de prazos são claras.', $response);
            $this->assertBodyContains('O lembrete rápido ajuda no meu dia a dia.', $response);
            $this->assertBodyContains('O sistema é rápido para as tarefas do dia a dia.', $response);
            $this->assertBodyContains('substituir minha planilha ou meu WhatsApp', $response);
            $this->assertBodyContains('recomendar o ContábilFlow', $response);
            $this->assertBodyContains('name="comentario"', $response);
            $this->assertBodyNotContains('name="perfil"', $response);
        }
    }

    // ---------- envio ----------

    public function testSubmissionStoresAnswersWithProfileUserAndTimeFromTheServer(): void
    {
        $contador = $this->contador();

        $response = $contador->post('/avaliacao', $this->answers(['comentario' => 'Muito útil.']));

        $this->assertRedirectTo('/', $response);
        $row = $this->row('SELECT * FROM avaliacoes');
        $this->assertSame(2, (int) $row['usuario_id']);
        $this->assertSame('contador', $row['perfil']);
        $this->assertSame([5, 4, 3, 2, 1], [(int) $row['q1'], (int) $row['q2'], (int) $row['q3'], (int) $row['q4'], (int) $row['q5']]);
        $this->assertSame(9, (int) $row['nps']);
        $this->assertSame('Muito útil.', $row['comentario']);
        $this->assertSame('2026-11-16 10:00:00', $row['criado_em']);
        $this->assertStringContainsString('Obrigado pela avaliação', (string) $this->alertText($contador->follow($response)));
    }

    public function testClientCannotForgeProfileUserOrTimestamp(): void
    {
        $this->contador()->post('/avaliacao', $this->answers(['perfil' => 'admin', 'usuario_id' => '1', 'criado_em' => '2000-01-01']));

        $row = $this->row('SELECT usuario_id, perfil, criado_em FROM avaliacoes');
        $this->assertSame(2, (int) $row['usuario_id']);
        $this->assertSame('contador', $row['perfil']);
        $this->assertSame('2026-11-16 10:00:00', $row['criado_em']);
    }

    public function testCommentIsOptionalAndSavedAsNullWhenEmpty(): void
    {
        $this->submit($this->contador(), ['comentario' => '   ']);

        $this->assertNull($this->scalar('SELECT comentario FROM avaliacoes'));
    }

    public function testScaleBoundariesAreAccepted(): void
    {
        $contador = $this->contador();

        $this->submit($contador, ['q1' => '1', 'q5' => '5', 'nps' => '0']);
        $this->submit($contador, ['nps' => '10', 'comentario' => str_repeat('a', 1000)]);

        $this->assertSame(2, $this->rows('avaliacoes'));
    }

    /** @return array<string, array{0: array<string, string>}> */
    public static function invalidAnswers(): array
    {
        return [
            'q1 zero' => [['q1' => '0']], 'q2 seis' => [['q2' => '6']], 'q3 negativa' => [['q3' => '-1']],
            'q4 texto' => [['q4' => 'ótimo']], 'q5 decimal' => [['q5' => '4.5']], 'q1 vazia' => [['q1' => '']],
            'nps onze' => [['nps' => '11']], 'nps negativo' => [['nps' => '-1']], 'nps vazio' => [['nps' => '']],
            'comentário longo' => [['comentario' => str_repeat('a', 1001)]],
            'comentário utf-8 inválido' => [['comentario' => "semáforo\xE1"]],
        ];
    }

    /** @param array<string, string> $override */
    #[DataProvider('invalidAnswers')]
    public function testInvalidAnswersAre422AndNothingIsSaved(array $override): void
    {
        $response = $this->contador()->post('/avaliacao', $this->answers($override));

        $this->assertSame(422, $response->status);
        $this->assertBodyContains('Responda todas as perguntas obrigatórias', $response);
        $this->assertSame(0, $this->rows('avaliacoes'));
    }

    public function testFormKeepsTheAnswersAlreadyGivenWhenIncomplete(): void
    {
        $response = $this->contador()->post('/avaliacao', ['q1' => '5', 'q2' => '4', 'comentario' => 'Texto digitado']);

        $this->assertSame(422, $response->status);
        $this->assertMatchesRegularExpression('/id="q1-5"[^>]*checked/', $response->body);
        $this->assertMatchesRegularExpression('/id="q2-4"[^>]*checked/', $response->body);
        $this->assertBodyContains('Texto digitado', $response);
        $this->assertSame(4, substr_count($response->body, 'Campo obrigatório')); // q3, q4, q5 e NPS
    }

    public function testCommentsAreEscapedInTheFormAndInTheSummary(): void
    {
        $contador = $this->contador();
        $payload = '</textarea><script>alert(1)</script>';

        $invalid = $contador->post('/avaliacao', $this->answers(['nps' => '', 'comentario' => $payload]));
        $this->assertBodyNotContains('<script>alert(1)', $invalid);

        $this->submit($contador, ['comentario' => $payload]);
        $summary = $this->admin()->get('/avaliacoes/resumo');
        $this->assertBodyNotContains('<script>alert(1)', $summary);
        $this->assertBodyContains('&lt;script&gt;alert(1)&lt;/script&gt;', $summary);
    }

    public function testTheSameUserCanEvaluateAgain(): void
    {
        $contador = $this->contador();

        $this->submit($contador);
        $this->submit($contador, ['nps' => '3']);

        $this->assertSame(2, $this->rows('avaliacoes'));
    }

    // ---------- resumo ----------

    public function testSummaryOnlyForAdministrators(): void
    {
        $this->assertSame(403, $this->contador()->get('/avaliacoes/resumo')->status);
        $this->assertSame(200, $this->admin()->get('/avaliacoes/resumo')->status);
    }

    public function testEmptySummaryInvitesTheTeamToAnswer(): void
    {
        $this->assertBodyContains('Ainda não há avaliações', $this->admin()->get('/avaliacoes/resumo'));
    }

    public function testSummaryHasTheCorrectAveragesNpsAndZone(): void
    {
        // Contador: notas 5,4,3,2,1 e NPS 10 (promotor). Admin: notas 3,3,3,3,3 e NPS 6 (detrator).
        $this->submit($this->contador(), ['nps' => '10', 'comentario' => 'Adorei o semáforo']);
        $this->submit($this->admin(), ['q1' => '3', 'q2' => '3', 'q3' => '3', 'q4' => '3', 'q5' => '3', 'nps' => '6']);

        $response = $this->admin()->get('/avaliacoes/resumo');

        $this->assertBodyContains('Resultado das avaliações', $response);
        $this->assertMatchesRegularExpression('/display-6 fw-semibold">2</', $response->body);           // respostas
        $this->assertMatchesRegularExpression('/display-6 fw-semibold">0</', $response->body);           // NPS = (1 - 1) / 2
        $this->assertBodyContains('Zona de aperfeiçoamento', $response);
        $this->assertBodyContains('1 promotor(es) · 0 neutro(s) · 1 detrator(es)', $response);
        $this->assertBodyContains('3,00', $response);   // média geral: (15 + 15) / 10 respostas de nota
        $this->assertBodyContains('4,00', $response);   // média da q1: (5 + 3) / 2
        $this->assertBodyContains('Administrador', $response);
        $this->assertBodyContains('Contador', $response);
        $this->assertBodyContains('Adorei o semáforo', $response);
        $this->assertBodyContains('<progress', $response);
    }

    public function testSummaryNpsZonesFollowTheScore(): void
    {
        $admin = $this->admin();
        foreach ([10, 10, 9] as $nps) {   // 3 promotores: NPS 100
            $this->submit($admin, ['nps' => (string) $nps]);
        }

        $excellent = $admin->get('/avaliacoes/resumo');
        $this->assertBodyContains('Zona de excelência', $excellent);

        foreach ([0, 0, 0, 0, 0, 0] as $nps) {   // + 6 detratores: (3 - 6) / 9 = -33
            $this->submit($admin, ['nps' => (string) $nps]);
        }
        $this->assertBodyContains('Zona crítica', $admin->get('/avaliacoes/resumo'));
    }

    public function testSummaryShowsTheAuthorOfEachComment(): void
    {
        $this->submit($this->contador(), ['comentario' => 'Do contador']);
        $this->submit($this->admin(), ['comentario' => 'Do administrador']);

        $page = $this->admin()->get('/avaliacoes/resumo');

        $this->assertBodyContains('Contador Demo', $page);
        $this->assertBodyContains('Do administrador', $page);
        $this->assertLessThan(strpos($page->body, 'Do contador'), strpos($page->body, 'Do administrador')); // mais recente primeiro
    }

    public function testRemovedUsersKeepTheirEvaluationWithoutAnAuthor(): void
    {
        $this->pdo->exec("INSERT INTO usuarios (nome, email, senha_hash, perfil) VALUES ('Temporário', 't@x.com', 'h', 'contador')");
        $temp = $this->browser();
        $temp->actAs(3, 'contador', 'Temporário');
        $this->submit($temp, ['comentario' => 'Saí da empresa']);

        $this->pdo->exec('DELETE FROM usuarios WHERE id = 3');

        $page = $this->admin()->get('/avaliacoes/resumo');
        $this->assertBodyContains('Saí da empresa', $page);
        $this->assertBodyContains('usuário removido', $page);
    }
}

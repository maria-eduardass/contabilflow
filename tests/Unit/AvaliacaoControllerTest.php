<?php

declare(strict_types=1);

namespace ContabilFlow\Tests\Unit;

use ContabilFlow\Controllers\AvaliacaoController;
use ContabilFlow\Core\Csrf;
use ContabilFlow\Core\Request;
use ContabilFlow\Core\Response;
use ContabilFlow\Core\Session;
use ContabilFlow\Core\View;
use ContabilFlow\Models\AvaliacaoDados;
use ContabilFlow\Tests\Support\FixedClock;
use ContabilFlow\Tests\Support\InMemoryAvaliacaoRepository;
use ContabilFlow\Validation\Validator;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AvaliacaoControllerTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $sessionData = ['user_id' => 4, 'perfil' => 'contador', 'nome' => 'Ana'];
    private Session $session;
    private InMemoryAvaliacaoRepository $repo;
    private AvaliacaoController $controller;

    protected function setUp(): void
    {
        $this->session = new Session($this->sessionData);
        $this->repo = new InMemoryAvaliacaoRepository();
        $this->controller = new AvaliacaoController(
            $this->repo,
            new FixedClock(new DateTimeImmutable('2026-11-16 10:00:00')),
            new View(__DIR__ . '/../../views'),
            $this->session,
            new Csrf($this->session),
            new Validator()
        );
    }

    /**
     * @param array<string, string> $override
     * @return array<string, string>
     */
    private function valid(array $override = []): array
    {
        return $override + [
            'q1' => '5', 'q2' => '4', 'q3' => '3', 'q4' => '2', 'q5' => '1', 'nps' => '9',
            'comentario' => 'Muito útil.',
        ];
    }

    /** @param array<string, string> $post */
    private function store(array $post): Response
    {
        return $this->controller->store(Request::create('POST', '/avaliacao', $post));
    }

    // ---------- formulário ----------

    public function testFormShowsFiveQuestionsScaleNpsAndCsrf(): void
    {
        $response = $this->controller->form(Request::create('GET', '/avaliacao'));

        $body = $response->body;
        $this->assertSame(200, $response->status);
        $this->assertStringContainsString('name="_csrf"', $body);
        $this->assertStringContainsString('action="/avaliacao"', $body);
        foreach (['q1', 'q2', 'q3', 'q4', 'q5'] as $q) {
            for ($nota = 1; $nota <= 5; $nota++) {
                $this->assertStringContainsString('id="' . $q . '-' . $nota . '"', $body);
            }
        }
        for ($n = 0; $n <= 10; $n++) {
            $this->assertStringContainsString('id="nps-' . $n . '"', $body);
        }
        $this->assertStringContainsString('O sistema é fácil de usar.', $body);
        $this->assertStringContainsString('As cores do semáforo de prazos são claras.', $body);
        $this->assertStringContainsString('lembrete rápido', $body);
        $this->assertStringContainsString('substituir minha planilha ou meu WhatsApp', $body);
        $this->assertStringContainsString('recomendar o ContábilFlow', $body);
        $this->assertStringContainsString('Concordo totalmente', $body);
        $this->assertStringContainsString('name="comentario"', $body);
    }

    public function testFormHasNoUserOrProfileFieldsSoNobodyCanAnswerAsAnother(): void
    {
        $body = $this->controller->form(Request::create('GET', '/avaliacao'))->body;

        $this->assertStringNotContainsString('name="perfil"', $body);
        $this->assertStringNotContainsString('name="usuario_id"', $body);
    }

    public function testNavigationLinksToEvaluationAndHidesResultsFromNonAdmins(): void
    {
        $body = $this->controller->form(Request::create('GET', '/avaliacao'))->body;

        $this->assertStringContainsString('href="/avaliacao"', $body);
        $this->assertStringNotContainsString('href="/avaliacoes/resumo"', $body);
    }

    public function testAdminSeesTheResultsLink(): void
    {
        $this->session->set('perfil', 'admin');

        $body = $this->controller->form(Request::create('GET', '/avaliacao'))->body;

        $this->assertStringContainsString('href="/avaliacoes/resumo"', $body);
    }

    // ---------- envio ----------

    public function testStoreSavesAnswersWithProfileUserAndTimeFromTheServer(): void
    {
        $response = $this->store($this->valid());

        $this->assertSame(302, $response->status);
        $this->assertSame('/', $response->headers['Location']);
        $this->assertCount(1, $this->repo->rows);
        $row = $this->repo->rows[0];
        $this->assertSame(4, $row['usuario']);
        $this->assertSame('contador', $row['perfil']);
        $this->assertSame('2026-11-16 10:00:00', $row['quando']->format('Y-m-d H:i:s'));
        $this->assertEquals(new AvaliacaoDados(5, 4, 3, 2, 1, 9, 'Muito útil.'), $row['dados']);
        $this->assertStringContainsString('Obrigado pela avaliação', $this->session->pullFlash()[0]['message']);
    }

    public function testStoreIgnoresProfileAndUserSentByTheClient(): void
    {
        $this->store($this->valid(['perfil' => 'admin', 'usuario_id' => '1', 'criado_em' => '2000-01-01']));

        $this->assertSame('contador', $this->repo->rows[0]['perfil']);
        $this->assertSame(4, $this->repo->rows[0]['usuario']);
        $this->assertSame('2026-11-16', $this->repo->rows[0]['quando']->format('Y-m-d'));
    }

    public function testCommentIsOptionalAndBecomesNullWhenEmpty(): void
    {
        $this->store($this->valid(['comentario' => '   ']));

        $this->assertNull($this->repo->rows[0]['dados']->comentario);
    }

    public function testBoundaryValuesAreAccepted(): void
    {
        $this->store($this->valid(['q1' => '1', 'q5' => '5', 'nps' => '0']));
        $this->store($this->valid(['nps' => '10', 'comentario' => str_repeat('a', 1000)]));

        $this->assertCount(2, $this->repo->rows);
        $this->assertSame(0, $this->repo->rows[0]['dados']->nps);
        $this->assertSame(10, $this->repo->rows[1]['dados']->nps);
    }

    /** @return array<string, array{0: array<string, string>, 1: string}> */
    public static function invalidAnswers(): array
    {
        return [
            'q1 zero' => [['q1' => '0'], 'q1'],
            'q2 seis' => [['q2' => '6'], 'q2'],
            'q3 negativa' => [['q3' => '-1'], 'q3'],
            'q4 texto' => [['q4' => 'ótimo'], 'q4'],
            'q5 decimal' => [['q5' => '4.5'], 'q5'],
            'q1 vazia' => [['q1' => ''], 'q1'],
            'nps onze' => [['nps' => '11'], 'nps'],
            'nps negativo' => [['nps' => '-1'], 'nps'],
            'nps vazio' => [['nps' => ''], 'nps'],
            'nps texto' => [['nps' => 'dez'], 'nps'],
            'comentário longo' => [['comentario' => str_repeat('a', 1001)], 'comentario'],
        ];
    }

    /** @param array<string, string> $override */
    #[DataProvider('invalidAnswers')]
    public function testInvalidAnswersReturn422AndSaveNothing(array $override, string $field): void
    {
        $response = $this->store($this->valid($override));

        $this->assertSame(422, $response->status);
        $this->assertStringContainsString('Responda todas as perguntas obrigatórias', $response->body);
        $this->assertSame([], $this->repo->rows);
    }

    public function testMissingQuestionsAreAllReported(): void
    {
        $response = $this->store(['comentario' => 'só comentário']);

        $this->assertSame(422, $response->status);
        $this->assertSame(6, substr_count($response->body, 'Campo obrigatório'));
    }

    public function testFormKeepsTheAnswersAlreadyGivenWhenInvalid(): void
    {
        $response = $this->store($this->valid(['nps' => '', 'comentario' => 'Texto digitado']));

        $this->assertMatchesRegularExpression('/id="q1-5"[^>]*checked/', $response->body);
        $this->assertMatchesRegularExpression('/id="q2-4"[^>]*checked/', $response->body);
        $this->assertStringContainsString('Texto digitado', $response->body);
        $this->assertDoesNotMatchRegularExpression('/id="nps-[0-9]+"[^>]*checked/', $response->body);
    }

    public function testStoreEscapesTheComment(): void
    {
        $response = $this->store($this->valid(['nps' => '', 'comentario' => '</textarea><script>alert(1)</script>']));

        $this->assertStringNotContainsString('<script>alert(1)', $response->body);
        $this->assertStringContainsString('&lt;/textarea&gt;', $response->body);
    }

    public function testAUserCanEvaluateMoreThanOnce(): void
    {
        $this->store($this->valid());
        $this->store($this->valid(['nps' => '3']));

        $this->assertCount(2, $this->repo->rows);
    }

    // ---------- resumo ----------

    public function testSummaryOfEmptyStateInvitesTheTeamToAnswer(): void
    {
        $response = $this->controller->resumo(Request::create('GET', '/avaliacoes/resumo'));

        $this->assertSame(200, $response->status);
        $this->assertStringContainsString('Ainda não há avaliações', $response->body);
    }

    public function testSummaryShowsAveragesNpsPerProfileAndComments(): void
    {
        $this->store($this->valid(['q1' => '5', 'nps' => '10', 'comentario' => 'Adorei o semáforo']));
        $this->session->set('perfil', 'admin');
        $this->session->set('user_id', 1);
        $this->store($this->valid(['q1' => '3', 'nps' => '2', 'comentario' => '']));
        $this->session->pullFlash();

        $body = $this->controller->resumo(Request::create('GET', '/avaliacoes/resumo'))->body;

        $this->assertStringContainsString('Resultado das avaliações', $body);
        $this->assertMatchesRegularExpression('/display-6[^>]*>2</', $body);           // 2 respostas
        $this->assertStringContainsString('Zona de aperfeiçoamento', $body);          // NPS 0 (1 promotor, 1 detrator)
        $this->assertStringContainsString('4,00', $body);                              // média q1 geral
        $this->assertStringContainsString('Administrador', $body);
        $this->assertStringContainsString('Contador', $body);
        $this->assertStringContainsString('Adorei o semáforo', $body);
        $this->assertStringContainsString('1 promotor(es) · 0 neutro(s) · 1 detrator(es)', $body);
        $this->assertStringContainsString('<progress', $body);
    }

    public function testSummaryEscapesComments(): void
    {
        $this->store($this->valid(['comentario' => '<img src=x onerror=alert(1)>']));

        $body = $this->controller->resumo(Request::create('GET', '/avaliacoes/resumo'))->body;

        $this->assertStringNotContainsString('<img src=x', $body);
        $this->assertStringContainsString('&lt;img src=x', $body);
    }
}

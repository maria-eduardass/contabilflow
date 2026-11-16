<?php

declare(strict_types=1);

namespace ContabilFlow\Controllers;

use ContabilFlow\Core\Clock;
use ContabilFlow\Core\Csrf;
use ContabilFlow\Core\Request;
use ContabilFlow\Core\Response;
use ContabilFlow\Core\Session;
use ContabilFlow\Core\View;
use ContabilFlow\Models\Avaliacao;
use ContabilFlow\Models\AvaliacaoDados;
use ContabilFlow\Models\AvaliacaoRepositoryInterface;
use ContabilFlow\Validation\Validator;

/**
 * Avaliação de usabilidade do próprio sistema: formulário para qualquer usuário
 * logado e resumo (médias e NPS) só para administradores.
 *
 * Por quê: o perfil e o autor vêm da SESSÃO, nunca do formulário, então ninguém
 * responde "como" outro perfil nem em nome de outra pessoa. As notas só aceitam
 * os valores da escala (1 a 5 e 0 a 10), reforçado pelos CHECK do banco.
 */
final class AvaliacaoController
{
    public function __construct(
        private AvaliacaoRepositoryInterface $avaliacoes,
        private Clock $clock,
        private View $view,
        private Session $session,
        private Csrf $csrf,
        private Validator $validator,
    ) {
    }

    public function form(Request $request): Response
    {
        return $this->renderForm([], []);
    }

    public function store(Request $request): Response
    {
        $rules = ['nps' => ['required', 'in:' . implode(',', range(0, 10))]];
        foreach (array_keys(Avaliacao::PERGUNTAS) as $key) {
            $rules[$key] = ['required', 'in:' . implode(',', array_keys(Avaliacao::ESCALA))];
        }
        $rules['comentario'] = ['maxLength:' . Avaliacao::MAX_COMENTARIO];

        $result = $this->validator->validate($request->post, $rules);
        if ($result->fails()) {
            $values = [];
            foreach (array_keys($rules) as $field) {
                $values[$field] = is_string($request->post[$field] ?? null) ? trim($request->post[$field]) : '';
            }

            return $this->renderForm($values, $result->errors, 422);
        }

        $d = $result->data;
        $this->avaliacoes->create(
            (int) $this->session->get('user_id', 0),
            (string) $this->session->get('perfil', ''),
            new AvaliacaoDados(
                (int) $d['q1'],
                (int) $d['q2'],
                (int) $d['q3'],
                (int) $d['q4'],
                (int) $d['q5'],
                (int) $d['nps'],
                $d['comentario'] ?? null
            ),
            $this->clock->now()
        );

        $this->session->flash('success', 'Obrigado pela avaliação! Ela ajuda a melhorar o ContábilFlow.');

        return Response::redirect('/');
    }

    public function resumo(Request $request): Response
    {
        return $this->render('avaliacoes/resumo', [
            'title' => 'Resultado das avaliações',
            'resumo' => $this->avaliacoes->resumo(),
            'perguntas' => Avaliacao::PERGUNTAS,
            'perfis' => UsuarioController::PERFIS,
        ]);
    }

    /**
     * @param array<string, string> $values
     * @param array<string, string> $errors
     */
    private function renderForm(array $values, array $errors, int $status = 200): Response
    {
        return $this->render('avaliacoes/form', [
            'title' => 'Avaliar o sistema',
            'values' => $values,
            'errors' => $errors,
            'perguntas' => Avaliacao::PERGUNTAS,
            'escala' => Avaliacao::ESCALA,
            'perguntaNps' => Avaliacao::PERGUNTA_NPS,
            'maxComentario' => Avaliacao::MAX_COMENTARIO,
        ], $status);
    }

    /** @param array<string, mixed> $data */
    private function render(string $template, array $data, int $status = 200): Response
    {
        return Response::html($this->view->render($template, $data + [
            'csrfField' => $this->csrf->field(),
            'userName' => (string) $this->session->get('nome', ''),
            'isAdmin' => $this->session->get('perfil') === 'admin',
            'flash' => $this->session->pullFlash(),
        ]), $status);
    }
}

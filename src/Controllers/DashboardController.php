<?php

declare(strict_types=1);

namespace ContabilFlow\Controllers;

use ContabilFlow\Core\Csrf;
use ContabilFlow\Core\Request;
use ContabilFlow\Core\Response;
use ContabilFlow\Core\Session;
use ContabilFlow\Core\View;
use ContabilFlow\Models\Obrigacao;
use ContabilFlow\Models\Semaforo;
use ContabilFlow\Services\DashboardService;
use ContabilFlow\Services\SemaforoService;

/** Painel inicial (HTML) e os mesmos indicadores em JSON. Controller fino: só chama o serviço. */
final class DashboardController
{
    public function __construct(
        private DashboardService $dashboard,
        private SemaforoService $semaforo,
        private View $view,
        private Session $session,
        private Csrf $csrf,
    ) {
    }

    public function index(Request $request): Response
    {
        return Response::html($this->view->render('dashboard/index', [
            'title' => 'Painel',
            'indicadores' => $this->dashboard->indicadores(),
            'semana' => $this->dashboard->vencendoNaSemana(),
            'cor' => fn(Obrigacao $o): Semaforo => $this->semaforo->classificar($o),
            'csrfField' => $this->csrf->field(),
            'userName' => (string) $this->session->get('nome', ''),
            'isAdmin' => $this->session->get('perfil') === 'admin',
            'flash' => $this->session->pullFlash(),
        ]));
    }

    public function indicadores(Request $request): Response
    {
        return Response::json($this->dashboard->indicadores()->toArray());
    }
}

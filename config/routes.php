<?php

declare(strict_types=1);

use ContabilFlow\Controllers\AuthController;
use ContabilFlow\Controllers\AvaliacaoController;
use ContabilFlow\Controllers\DashboardController;
use ContabilFlow\Controllers\EmpresaController;
use ContabilFlow\Controllers\LembreteController;
use ContabilFlow\Controllers\UsuarioController;
use ContabilFlow\Controllers\ObrigacaoController;
use ContabilFlow\Core\Response;
use ContabilFlow\Core\Router;

// Por quê: todas as rotas ficam num só lugar, fora do index.php. Cada tarefa
// seguinte registra as suas aqui. Vale a primeira rota que casar, então
// /empresas/nova vem antes de /empresas/{id}.
return static function (
    Router $router,
    AuthController $auth,
    EmpresaController $empresas,
    ObrigacaoController $obrigacoes,
    DashboardController $dashboard,
    LembreteController $lembretes,
    UsuarioController $usuarios,
    AvaliacaoController $avaliacoes
): void {
    $router->get('/', $dashboard->index(...));
    $router->get('/dashboard', static fn() => Response::redirect('/'));
    $router->get('/api/dashboard/indicadores', $dashboard->indicadores(...));

    $router->get('/login', $auth->showLogin(...));
    $router->post('/login', $auth->login(...));
    $router->post('/logout', $auth->logout(...));

    $router->get('/empresas', $empresas->index(...));
    $router->get('/empresas/nova', $empresas->create(...));
    $router->post('/empresas', $empresas->store(...));
    $router->get('/empresas/{id:[0-9]+}', $empresas->show(...));
    $router->get('/empresas/{id:[0-9]+}/editar', $empresas->edit(...));
    $router->put('/empresas/{id:[0-9]+}', $empresas->update(...));
    $router->delete('/empresas/{id:[0-9]+}', $empresas->destroy(...));

    $router->get('/obrigacoes', $obrigacoes->index(...));
    $router->get('/obrigacoes/nova', $obrigacoes->create(...));
    $router->post('/obrigacoes', $obrigacoes->store(...));
    $router->get('/obrigacoes/{id:[0-9]+}', $obrigacoes->show(...));
    $router->get('/obrigacoes/{id:[0-9]+}/editar', $obrigacoes->edit(...));
    $router->put('/obrigacoes/{id:[0-9]+}', $obrigacoes->update(...));
    $router->patch('/obrigacoes/{id:[0-9]+}/status', $obrigacoes->changeStatus(...));
    $router->delete('/obrigacoes/{id:[0-9]+}', $obrigacoes->destroy(...));

    $router->get('/usuarios', $usuarios->index(...));
    $router->get('/usuarios/novo', $usuarios->create(...));
    $router->post('/usuarios', $usuarios->store(...));
    $router->patch('/usuarios/{id:[0-9]+}/ativo', $usuarios->toggleActive(...));

    $router->get('/avaliacao', $avaliacoes->form(...));
    $router->post('/avaliacao', $avaliacoes->store(...));
    $router->get('/avaliacoes/resumo', $avaliacoes->resumo(...));

    $router->get('/api/obrigacoes/{id:[0-9]+}/lembrete', $lembretes->preview(...));
    $router->post('/api/obrigacoes/{id:[0-9]+}/lembretes', $lembretes->registrar(...));
};

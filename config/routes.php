<?php

declare(strict_types=1);

use ContabilFlow\Controllers\AuthController;
use ContabilFlow\Controllers\EmpresaController;
use ContabilFlow\Core\Response;
use ContabilFlow\Core\Router;

// Por quê: todas as rotas ficam num só lugar, fora do index.php. Cada tarefa
// seguinte registra as suas aqui. Vale a primeira rota que casar, então
// /empresas/nova vem antes de /empresas/{id}.
return static function (Router $router, AuthController $auth, EmpresaController $empresas): void {
    $router->get('/', static fn() => Response::redirect('/empresas'));

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
};

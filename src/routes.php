<?php

use Slim\Http\Request;
use Slim\Http\Response;

date_default_timezone_set("Asia/Jakarta");

// Routes

$app->get('[/]', function ($req, $res, $next) { return "Hello"; })
    ->add(\App\Middlewares\AuthMiddlewares\UserMiddleware::class);
//$app->redirect('[/]', $_ENV['APP_URL'].'/login')
$app->get('/login', '\App\Controllers\AuthControllers\LoginController:login')->setName('login');
$app->post('/login', '\App\Controllers\AuthControllers\LoginController:handleLogin');
$app->post('/logout', '\App\Controllers\AuthControllers\LoginController:logout')
    ->add(\App\Middlewares\AuthMiddlewares\UserMiddleware::class)
    ->setName('logout');

$app->redirect('/dashboard', $_ENV['APP_URL'].'/tenant')->setName('dashboard');
$app->redirect('/home', $_ENV['APP_URL'].'/tenant')->setName('home');
// $app->get('/dashboard', '\App\Controllers\DashboardController:index')->setName('dashboard');

$app->group('/tenant', function() {

    $this->get('', '\App\Controllers\TenantController:index')->setName('tenant');

    $this->group('/add', function() {

        $this->get('', '\App\Controllers\TenantController:add')->setName('addTenant');
        $this->post('', '\App\Controllers\TenantController:handleAdd');
    })->add(\App\Middlewares\AuthMiddlewares\AdminMiddleware::class);

    $this->group('/{id}', function() {

        $this->get('', '\App\Controllers\TenantController:detail')->setName('detailTenant');

        $this->get('/edit', '\App\Controllers\TenantController:edit')->setName('editTenant');
        $this->post('/edit', '\App\Controllers\TenantController:handleEdit');
    })->add(\App\Middlewares\TenantMiddleware::class);
})->add(\App\Middlewares\AuthMiddlewares\UserMiddleware::class);

$app->group('/user', function() {

    $this->get('', '\App\Controllers\UsersController:index')->setName('users');

    $this->get('/add', '\App\Controllers\UsersController:add')->setName('addUser');
    $this->post('/add', '\App\Controllers\UsersController:handleAdd');

    $this->group('/{id}', function() {

        // $this->get('', '\App\Controllers\UsersController:detail')->setName('detailUser');

        $this->get('/edit', '\App\Controllers\UsersController:edit')->setName('editUser');
        $this->post('/edit', '\App\Controllers\UsersController:handleEdit');

        // $this->post('/unlink', '\App\Controllers\UsersController:handleUnlink')->setName('unlinkUser');

        $this->post('/enable', '\App\Controllers\UsersController:handleEnable')->setName('enableUser');
    })->add(\App\Middlewares\UsersMiddleware::class);
})->add(\App\Middlewares\AuthMiddlewares\AdminMiddleware::class);

$app->group('/logger', function() {

    $this->get('', '\App\Controllers\LoggerController:index')->setName('logger');

    $this->get('/add', '\App\Controllers\LoggerController:add')->setName('addLogger');
    $this->post('/add', '\App\Controllers\LoggerController:handleAdd');

    $this->group('/{id}', function() {

        // $this->get('', '\App\Controllers\LoggerController:detail')->setName('detailLogger');
        $this->get('', function(Request $request, Response $response) {
            return $response->withJson([
                'status' => 200,
                'message' => "OK"
            ]);
        });

        $this->get('/edit', '\App\Controllers\LoggerController:edit')->setName('editLogger');
        $this->post('/edit', '\App\Controllers\LoggerController:handleEdit');

        // $this->post('/unlink', '\App\Controllers\LoggerController:handleUnlink')->setName('unlinkLogger');

        // $this->post('/delete', '\App\Controllers\LoggerController:handleDelete')->setName('deleteLogger');
    })->add(\App\Middlewares\LoggerMiddleware::class);
})->add(\App\Middlewares\AuthMiddlewares\UserMiddleware::class);


// API
$app->group('/api', function() {

    $this->group('/logger', function() {

        $this->group('/{sn}', function() {

            $this->get('/sensor', '\App\Controllers\ApiControllers\LoggerController:getSensor');
        });
    });
});

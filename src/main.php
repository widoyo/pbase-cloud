<?php

use Slim\Http\Request;
use Slim\Http\Response;

$app->redirect('/', '/login');
$app->group('/login', function () {

	$this->get('', function (Request $request, Response $response) {
		if ($this->user) {
	        return $response->withRedirect('/logger');
	    }

	    return $this->view->render($response, 'main/login.html');
	});

	$this->post('', function (Request $request, Response $response) {
		if ($this->user) {
	        return $response->withRedirect('/logger');
	    }

	    $credentials = $request->getParams();
	    if (empty($credentials['username']) || empty($credentials['password'])) {
	        $this->flash->addMessage('errors', 'Masukkan username dan password');
	        return $response->withRedirect('/login');
	    }

	    $stmt = $this->db->prepare("SELECT * FROM users WHERE username=:username");
	    $stmt->execute([':username' => $credentials['username']]);
	    $user = $stmt->fetch();
	    if (!$user || !password_verify($credentials['password'], $user['password'])) {
	        $this->flash->addMessage('errors', 'Username / password salah');
	        return $response->withRedirect('/login');
	    }

	    $this->session->user_id = $user['id'];
	    $this->session->user_refresh_time = strtotime("+1hour");
	    $this->session->user_basic_auth = base64_encode("{$credentials['username']}:{$credentials['password']}");

	    // $this->flash->addMessage('messages', 'Berhasil Login');
	    return $response->withRedirect('/logger');
	});
});

$app->post('/logout', function (Request $request, Response $response, $args) {
	$this->flash->addMessage('messages', 'Berhasil Logout');
    $this->session->destroy();
    return $response->withRedirect('/login');
})->add($loggedinMiddleware);
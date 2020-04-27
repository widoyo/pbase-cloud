<?php

use Slim\Http\Request;
use Slim\Http\Response;

$app->redirect('/', '/login');
$app->group('/login', function () {

	$this->get('', function (Request $request, Response $response) {
		// cek apakah ada login token
		$login_token = \Dflydev\FigCookies\FigRequestCookies::get($request, KEY_LOGIN_TOKEN, null);
        if ($login_token) {
			$login_token = str_replace(KEY_LOGIN_TOKEN . "=", "", $login_token);
			// dump($login_token);

            $stmt = $this->db->prepare("SELECT * FROM users WHERE login_token=:login_token");
            $stmt->execute([':login_token' => $login_token]);
            $user = $stmt->fetch();
            if ($user) {        
                $this->session->user_id = $user['id'];
                $this->session->user_refresh_time = strtotime("+24hour");
                // $this->session->user_basic_auth = base64_encode("{$credentials['username']}:{$credentials['password']}");
            }
		}
		
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

		// cek apakah sudah ada login_token
		if (!$user['login_token']) {
			$now = date('Y-m-d H:i:s');
			$user['login_token'] = base64_encode("{$credentials['username']}:{$credentials['password']}:{$now}");
			$user['login_token'] = md5($user['login_token']);

			// $domain = parse_url(env('APP_URL'));
			// $domain = $domain['host'];

			// dump($response);
			$this->db->query("UPDATE users SET login_token='{$user['login_token']}' WHERE id={$user['id']}");
		}
		
		// set cookie ke browser
		$response = \Dflydev\FigCookies\FigResponseCookies::set(
			$response,
			\Dflydev\FigCookies\SetCookie::create(KEY_LOGIN_TOKEN)
				->withValue($user['login_token'])
				->rememberForever()
				->withPath('/')
				// ->withDomain($domain)
				->withHttpOnly(true)
		);

		$this->session->user_id = $user['id'];
		$this->session->user_refresh_time = strtotime("+24hour");
		$this->session->user_basic_auth = base64_encode("{$credentials['username']}:{$credentials['password']}");

		// $this->flash->addMessage('messages', 'Berhasil Login');
		return $response->withRedirect('/logger');
	});
});

$app->post('/logout', function (Request $request, Response $response, $args) {
	$this->flash->addMessage('messages', 'Berhasil Logout');
	$this->session->destroy();

	// remove login_token
	$response = \Dflydev\FigCookies\FigResponseCookies::expire($response, KEY_LOGIN_TOKEN);

	return $response->withRedirect('/login');
})->add($loggedinMiddleware);

$app->redirect('/instal', '/install');
$app->get('/install', function (Request $request, Response $response, $args) {
	$user = $this->user;

	if ($user['tenant_id'] > 0) {
		$loggers_stmt = $this->db->query("SELECT logger.*, tenant.center_map FROM logger
        	LEFT JOIN tenant ON logger.tenant_id = tenant.id
            WHERE logger.tenant_id = {$user['tenant_id']}
            ORDER BY logger.sn");
		$locations_stmt = $this->db->query("SELECT * FROM location
        	WHERE tenant_id = {$user['tenant_id']}
        	ORDER BY nama");
	} else {
		$loggers_stmt = $this->db->query("SELECT logger.*, tenant.center_map FROM logger
        	LEFT JOIN tenant ON logger.tenant_id = tenant.id
            ORDER BY logger.sn");
		$locations_stmt = $this->db->query("SELECT * FROM location
        	ORDER BY nama");
	}
	$loggers = $loggers_stmt->fetchAll();
	$locations = $locations_stmt->fetchAll();

	return $this->view->render($response, 'main/mobile/install.html', [
		'loggers' => $loggers,
		'locations' => $locations
	]);
})->add($loggedinMiddleware);

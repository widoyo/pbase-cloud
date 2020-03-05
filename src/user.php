<?php

use Slim\Http\Request;
use Slim\Http\Response;

$app->group('/user', function() use ($getUserMiddleware) {

    $this->get('', function (Request $request, Response $response, $args) {
        $users = $this->db->query("SELECT
                users.*,
                COALESCE(tenant.nama, 'Admin') as nama_tenant
            FROM
                users
                LEFT JOIN tenant ON (users.tenant_id = tenant.id)
            ORDER BY
                users.is_active DESC,
                nama_tenant,
                users.username
            ")->fetchAll();

        return $this->view->render($response, 'user/index.html', [
            'users' => $users
        ]);
    });

    $this->get('/add', function (Request $request, Response $response, $args) {
        $user = [
            'username' => '',
            'is_active' => 1,
            'tenant_id' => intval($request->getParam('t', 0)),
            'email' => '',
            'tz' => 'Asia/Jakarta'
        ];

        $tenants = $this->db->query("SELECT * FROM tenant ORDER BY nama")->fetchAll();
        $timezones = DateTimeZone::listIdentifiers(DateTimeZone::PER_COUNTRY, 'ID');

        return $this->view->render($response, 'user/edit.html', [
            'mode' => 'Add',
            'user' => $user,
            'tenants' => $tenants,
            'timezones' => $timezones
        ]);
    });

    $this->post('/add', function (Request $request, Response $response, $args) {
        $user = $request->getParams();

        if (strlen($user['username']) > 12) {
        	$this->flash->addMessage('messages', 'Username terlalu panjang, max. 12 karakter');
            return $response->withRedirect('/user/add');
        }

        $stmt = $this->db->prepare("INSERT INTO users (username, is_active, tenant_id, email, tz) VALUES (:username, :is_active, :tenant_id, :email, :tz)");
        $stmt->bindValue(':username', $user['username']);
        $stmt->bindValue(':is_active', $user['is_active']);
        $stmt->bindValue(':tenant_id', $user['tenant_id'] ? $user['tenant_id'] : null);
        $stmt->bindValue(':email', $user['email'] ? $user['email'] : null);
        $stmt->bindValue(':tz', $user['tz'] ? $user['tz'] : 'Asia/Jakarta');
        $stmt->execute();

        $id = $this->db->lastInsertId();
        if (strlen($user['password']) > 0 && strlen($user['password_repeat']) > 0) {
            $valid = true;

            if (strlen($user['password']) < 6) {
                $this->flash->addMessage('errors', 'Panjang password minimal 6 karakter');
                $valid = false;
            }

            if ($user['password'] != $user['password_repeat']) {
                $this->flash->addMessage('errors', 'Password tidak sesuai');
                $valid = false;
            }

            if (!$valid) {
                return $response->withRedirect("/user/{$user['id']}/edit");
            }

            $stmt = $this->db->prepare("UPDATE users set password=:password WHERE id=:id");
            $stmt->bindValue(':password', password_hash($user['password'], PASSWORD_DEFAULT));
            $stmt->bindValue(':id', $id);
            $stmt->execute();
        }
        
        $this->flash->addMessage('messages', "User {$user['username']} telah ditambahkan");

        return $response->withRedirect('/user');
    });

    $this->group('/{id}', function() {

        $this->get('/edit', function (Request $request, Response $response, $args) {
	        $user = $request->getAttribute('user');
	        $tenants = $this->db->query("SELECT * FROM tenant ORDER BY nama")->fetchAll();
	        $timezones = DateTimeZone::listIdentifiers(DateTimeZone::PER_COUNTRY, 'ID');

	        return $this->view->render($response, 'user/edit.html', [
	            'mode' => 'Edit',
	            'user' => $user,
	            'tenants' => $tenants,
	            'timezones' => $timezones
	        ]);
	    });

        $this->post('/edit', function (Request $request, Response $response, $args) {
	        $user = $request->getAttribute('user');
	        $user['username'] = $request->getParam('username', $user['username']);
	        $user['is_active'] = $request->getParam('is_active', $user['is_active']);
	        $user['tenant_id'] = $request->getParam('tenant_id', $user['tenant_id']);
	        $user['email'] = $request->getParam('email', $user['email']);
	        $user['tz'] = $request->getParam('tz', $user['tz']);
	        $user['password'] = $request->getParam('password', '');
	        $user['password_repeat'] = $request->getParam('password_repeat', '');

	        $now = date('Y-m-d H:i:s');

	        $stmt = $this->db->prepare("UPDATE users set username=:username, is_active=:is_active, tenant_id=:tenant_id, email=:email, tz=:tz, modified='$now' WHERE id=:id");
	        $stmt->bindValue(':username', $user['username']);
	        $stmt->bindValue(':is_active', $user['is_active']);
	        $stmt->bindValue(':tenant_id', $user['tenant_id'] ? $user['tenant_id'] : null);
	        $stmt->bindValue(':email', $user['email'] ? $user['email'] : null);
	        $stmt->bindValue(':tz', $user['tz'] ? $user['tz'] : 'Asia/Jakarta');
	        $stmt->bindValue(':id', $user['id']);
	        $stmt->execute();
	        
	        if (strlen($user['password']) > 0 && strlen($user['password_repeat']) > 0) {
	            $valid = true;

	            if (strlen($user['password']) < 6) {
	                $this->flash->addMessage('errors', 'Panjang password minimal 6 karakter');
	                $valid = false;
	            }

	            if ($user['password'] != $user['password_repeat']) {
	                $this->flash->addMessage('errors', 'Password tidak sesuai');
	                $valid = false;
	            }

	            if (!$valid) {
	                return $response->withRedirect($response, "/user/{$user['id']}/edit");
	            }

	            $stmt = $this->db->prepare("UPDATE users set password=:password WHERE id=:id");
	            $stmt->bindValue(':password', password_hash($user['password'], PASSWORD_DEFAULT));
	            $stmt->bindValue(':id', $user['id']);
	            $stmt->execute();
	        }

	        $this->flash->addMessage('messages', "Perubahan User {$user['username']} telah disimpan");
	        
	        return $response->withRedirect('/user');
	    });

        $this->post('/enable', function (Request $request, Response $response, $args) {
	        $user = $request->getAttribute('user');
	        $user['is_active'] = $request->getParam('is_active', $user['is_active']);

	        $now = date('Y-m-d H:i:s');

	        $stmt = $this->db->prepare("UPDATE users set is_active=:is_active, modified='$now' WHERE id=:id");
	        $stmt->bindValue(':is_active', $user['is_active']);
	        $stmt->bindValue(':id', $user['id']);
	        $stmt->execute();

	        $this->flash->addMessage('messages', "Users {$user['username']} ". ($user['is_active'] == 0 ? 'DISABLED' : 'ENABLED'));
	        
	        return $response->withRedirect('/user');
	    });
    })->add($getUserMiddleware);
})->add($adminRoleMiddleware)
    ->add($loggedinMiddleware);
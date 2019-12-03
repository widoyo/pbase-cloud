<?php

use Slim\Http\Request;
use Slim\Http\Response;

$app->group('/tenant', function () use ($getTenantMiddleware, $adminRoleMiddleware) {

    $this->get('', function (Request $request, Response $response) {
    	$user = $this->user;
        if ($user['tenant_id'] > 0) {
            return $response->withRedirect("/tenant/{$user['tenant_id']}");
        }

        $tenants = $this->db->query("SELECT * FROM tenant ORDER BY nama")->fetchAll();
        foreach ($tenants as &$tenant) {
            $tenant['jml_user'] = $this->db->query("SELECT COUNT(id) FROM users WHERE tenant_id={$tenant['id']}")->fetchColumn();
            $tenant['jml_logger'] = $this->db->query("SELECT COUNT(id) FROM logger WHERE tenant_id={$tenant['id']}")->fetchColumn();
        }
        unset($tenant);

        return $this->view->render($response, 'tenant/index.html', [
            'tenants' => $tenants
        ]);
    });

    $this->group('/add', function () use ($adminRoleMiddleware) {

        $this->get('', function (Request $request, Response $response) {
        	$tenant = [
	            'nama' => '',
	            'slug' => ''
	        ];

	        return $this->view->render($response, 'tenant/edit.html', [
	            'mode' => 'Add',
	            'tenant' => $tenant,
	        ]);
        });

        $this->post('', function (Request $request, Response $response) {
        	$tenant = $request->getParams();

	        $stmt = $this->db->prepare("INSERT INTO tenant (id, nama, slug) VALUES (nextval('tenant_id_seq'), :nama, :slug)");
	        $stmt->execute(["nama" => $tenant['nama'], "slug" => $tenant['slug']]);
	        
	        $this->flash->addMessage('messages', "Tenant {$tenant[nama]} telah ditambahkan");

	        return $response->withRedirect('/tenant');
        });
    });

    $this->group('/{id}', function () {

    	$this->get('', function (Request $request, Response $response, $args) {
    		$tenant = $request->getAttribute('tenant');

	        $stmt_users = $this->db->prepare("SELECT * from users WHERE tenant_id=:id");
	        $stmt_users->execute([':id' => $tenant['id']]);
	        $users = $stmt_users->fetchAll();
	        $tenant['jml_user'] = count($users);
	        
	        $stmt_logger = $this->db->prepare("SELECT * from logger WHERE tenant_id=:id");
	        $stmt_logger->execute([':id' => $tenant['id']]);
	        $loggers = $stmt_logger->fetchAll();
	        $tenant['jml_logger'] = count($loggers);

	        return $this->view->render($response, 'tenant/detail.html', [
	            'tenant' => $tenant,
	            'users' => $users,
	            'loggers' => $loggers,
	        ]);
    	});

        $this->get('/edit', function (Request $request, Response $response, $args) {
        	$tenant = $request->getAttribute('tenant');

	        return $this->view->render($response, 'tenant/edit.html', [
	            'mode' => 'Edit',
	            'tenant' => $tenant
	        ]);
        });

        $this->post('/edit', function (Request $request, Response $response, $args) {
        	$tenant = $request->getAttribute('tenant');
	        $tenant['nama'] = $request->getParam('nama', $tenant['nama']);
	        $tenant['slug'] = $request->getParam('slug', $tenant['slug']);

	        $now = date('Y-m-d H:i:s');

	        $stmt = $this->db->prepare("UPDATE tenant set nama=:nama, slug=:slug, modified_at='$now' WHERE id=:id");
	        $stmt->bindValue(':nama', $tenant['nama']);
	        $stmt->bindValue(':slug', $tenant['slug']);
	        $stmt->bindValue(':id', $tenant['id']);
	        $stmt->execute();
	        
	        $this->flash->addMessage('messages', "Perubahan Tenant {$tenant[nama]} telah disimpan");

	        return $response->withRedirect("/tenant/{$tenant['id']}");
        });
    })->add($getTenantMiddleware);
})->add($adminRoleMiddleware)
	->add($loggedinMiddleware);
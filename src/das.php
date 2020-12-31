<?php

use Slim\Http\Request;
use Slim\Http\Response;

$app->group('/das', function () use ($getDasMiddleware, $adminRoleMiddleware) {

    $this->get('', function (Request $request, Response $response) {
    	$user = $this->user;

		$tenants = null;
		if ($user['tenant_id'] > 0) {
			$das = $this->db->query("SELECT * FROM das WHERE tenant_id={$user['tenant_id']} ORDER BY nama")->fetchAll();
		} else {
			$das = $this->db->query("SELECT
					das.id,
					das.nama,
					tenant.nama AS tenant_nama
				FROM
					das
					LEFT JOIN tenant ON (das.tenant_id = tenant.id)
				ORDER BY nama")->fetchAll();
			$tenants = $this->db->query("SELECT * FROM tenant ORDER BY nama")->fetchAll();
		}

        return $this->view->render($response, 'das/index.html', [
            'das' => $das,
            'tenants' => $tenants,
        ]);
    });

    $this->group('/add', function () use ($adminRoleMiddleware) {

        $this->post('', function (Request $request, Response $response) {
			$das = $request->getParams();
			$user = $this->user;
			if ($user['tenant_id'] > 0) {
				$das['tenant_id'] = $user['tenant_id'];
			}

	        $stmt = $this->db->prepare("INSERT INTO das (
				nama,
				tenant_id
			) VALUES (
				:nama,
				:tenant_id
			)");
	        $stmt->execute([
	        	"nama" => $das['nama'],
	        	"tenant_id" => $das['tenant_id'],
	        ]);
	        
	        $this->flash->addMessage('messages', "DAS {$das['nama']} telah ditambahkan");

	        return $response->withRedirect('/das');
        });
    });

    $this->group('/{id}', function () {

    	$this->get('', function (Request $request, Response $response, $args) {
    		$das = $request->getAttribute('das');

	        return $this->view->render($response, 'das/detail.html', [
	            'das' => $das,
	        ]);
    	});

        $this->post('/edit', function (Request $request, Response $response, $args) {
        	$das = $request->getAttribute('das');
        	$form = $request->getParams();
        	foreach ($form as $key => $value) {
        		$das[$key] = $value;
        	}
        	unset($das['_referer']);

			$now = date('Y-m-d H:i:s');
			
			$user = $this->user;
			if ($user['tenant_id'] > 0) {
				$das['tenant_id'] = $user['tenant_id'];
			}

	        $stmt = $this->db->prepare("UPDATE das set
		        	nama=:nama,
		        	tenant_id=:tenant_id,
		        	modified_at='$now'
	        	WHERE id=:id");
	        $stmt->execute([
				':nama' => $das['nama'],
				':tenant_id' => $das['tenant_id'],
				':id' => $das['id'],
			]);
	        
	        $this->flash->addMessage('messages', "Perubahan DAS {$das['nama']} telah disimpan");

	        return $response->withRedirect("/das");
        });
    })->add($getDasMiddleware);
})->add($loggedinMiddleware);
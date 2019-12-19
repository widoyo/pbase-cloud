<?php

use Slim\Http\Request;
use Slim\Http\Response;

$app->group('/location', function () use ($getLocationMiddleware) {

    $this->get('', function (Request $request, Response $response, $args) {
		$user = $this->user;

        if ($user['tenant_id'] > 0)
        {
            $locations_stmt = $this->db->query("SELECT * FROM location WHERE
                location.tenant_id = {$user['tenant_id']} ORDER BY nama");
        }
        else
        {
            $locations_stmt = $this->db->query("SELECT * FROM location ORDER BY nama");
        }
        $location_data = $locations_stmt->fetchAll();
        // dump($location_data);

        $tenants = $this->db->query("SELECT * FROM tenant ORDER BY nama")->fetchAll();

        return $this->view->render($response, 'location/mobile/index.html', [
            'locations' => $location_data,
            // 'total_data' => $total_data,
            'tenants' => $tenants
        ]);
	});

    $this->group('/add', function () {

        $this->get('', function (Request $request, Response $response, $args) {
            $tenants = $this->db->query("SELECT * FROM tenant ORDER BY nama")->fetchAll();

            return $this->view->render($response, 'location/mobile/add.html', [
                'tenants' => $tenants
            ]);
        });

        $this->post('', function (Request $request, Response $response, $args) {
            $form = $request->getParams();

            $stmt = $this->db->prepare("INSERT INTO location (nama, tenant_id, ll) VALUES (:nama, :tenant_id, :ll)");
            $stmt->execute([
                ":nama" => $form['nama'],
                ":tenant_id" => $form['tenant_id'],
                ":ll" => $form['ll']
            ]);
            
            if ($stmt->rowCount() > 0) {
                $this->flash->addMessage('messages', "Lokasi {$form['nama']} telah ditambahkan");
            } else {
                $this->flash->addMessage('errors', "Gagal menambahkan lokasi {$form['nama']}");
            }

            return $response->withRedirect('/location');
        });
    });

	$this->group('/{id:[0-9]+}', function () {

		$this->get('', function (Request $request, Response $response, $args) {
			$location = $request->getAttribute('location');

            $tenants = $this->db->query("SELECT * FROM tenant ORDER BY nama")->fetchAll();

			return $this->view->render($response, 'location/mobile/show.html', [
				'location' => $location,
				'tenants' => $tenants
			]);
		});

        $this->post('/config', function (Request $request, Response $response, $args) {
            $location = $request->getAttribute('location');

            $form = $request->getParams();
            $referer = $request->getHeader('HTTP_REFERER');
            if ($referer && count($referer) > 0) {
            	$referer = $referer[0];
            } else {
            	$referer = '/location';
            }

            if (count($form) > 0) {
                $query = "UPDATE location SET ";
                foreach ($form as $column => $value) {
                    $query .= "{$column} = '{$value}',";
                }
                $query = rtrim($query, ",");

                $query .= " WHERE id = {$location['id']}";
                $stmt = $this->db->prepare($query);
                $stmt->execute();

                if ($stmt->rowCount() > 0) {
                    $this->flash->addMessage('messages', "Config lokasi {$location['nama']} berhasil diubah");
                } else {
                    $this->flash->addMessage('errors', "Gagal mengubah config lokasi {$location['nama']}");
                }
            }

            return $response->withRedirect($referer);
        });
	})->add($getLocationMiddleware);
})->add($loggedinMiddleware);
<?php

use Slim\Http\Request;
use Slim\Http\Response;

$app->group('/location', function () {

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

        return $this->view->render($response, 'location/mobile/index.html', [
            'locations' => $location_data,
            // 'total_data' => $total_data,
        ]);
	});
})->add($loggedinMiddleware);
<?php

use Slim\Http\Request;
use Slim\Http\Response;

$app->group('/logger', function () use ($getLoggerMiddleware) {

    $this->get('', function (Request $request, Response $response, $args) {
        // override utk cek mobile
        $request  = new Slim\Http\MobileRequest($request);
		$response = new Slim\Http\MobileResponse($response);
        $user = $this->user;

        $timezone_default = timezone_default();

        // cek apakah redis available
        try {
            $pclient = new Predis\Client();
            $pclient->connect();
        } catch (Predis\Connection\ConnectionException $e) {
            $pclient = null;
        }

        $logger_data = [];
        if ($pclient) {
            if ($user['tenant_id'] > 0)
            {
                $keys = $pclient->smembers("tenant:{$user['tenant_id']}:logger");
                foreach ($keys as $key) {
                    $logger_data[] = $pclient->hgetall($key);
                }
            }
            else
            {
                $keys = $pclient->smembers("logger");
                foreach ($keys as $key) {
                    $logger_data[] = $pclient->hgetall($key);
                }
            }
        } else {
            if ($user['tenant_id'] > 0)
            {
                $loggers_stmt = $this->db->query("SELECT
                        logger.sn,
                        logger.tipe,
                        location.nama AS location_nama,
                        tenant.nama AS tenant_nama,
                        COALESCE(tenant.timezone, '{$timezone_default}') AS timezone,
                        periodik.sampling as latest_sampling,
                        periodik.batt,
                        periodik.sq
                    FROM logger
                        LEFT JOIN location ON logger.location_id = location.id
                        LEFT JOIN tenant ON logger.tenant_id = tenant.id
                        LEFT JOIN periodik ON periodik.id = (
                            SELECT id from periodik
                            WHERE logger_sn=logger.sn
                            ORDER BY sampling DESC
                            LIMIT 1
                        )
                    WHERE logger.tenant_id = {$user['tenant_id']}
                    ORDER BY
                        location.nama,
                        logger.sn");
            }
            else
            {
                $loggers_stmt = $this->db->query("SELECT
                        logger.sn,
                        logger.tipe,
                        location.nama AS location_nama,
                        tenant.nama AS tenant_nama,
                        COALESCE(tenant.timezone, '{$timezone_default}') AS timezone,
                        periodik.sampling as latest_sampling,
                        periodik.batt,
                        periodik.sq
                    FROM logger
                        LEFT JOIN location ON logger.location_id = location.id
                        LEFT JOIN tenant ON logger.tenant_id = tenant.id
                        LEFT JOIN periodik ON periodik.id = (
                            SELECT id from periodik
                            WHERE logger_sn=logger.sn
                            ORDER BY sampling DESC
                            LIMIT 1
                        )
                    ORDER BY
                        location.nama,
                        logger.sn");
            }
            $logger_data = $loggers_stmt->fetchAll();
        }

        foreach ($logger_data as &$logger) {
            if (!$logger['latest_sampling']) {
                continue;
            }

            $logger['latest_sampling'] = $logger['latest_sampling'] ? timezone_format($logger['latest_sampling'], $logger['timezone']) : null;
            // $logger['up_s'] = $logger['up_s'] ? timezone_format($logger['up_s'], $logger['timezone']) : null;
            // $logger['ts_a'] = $logger['ts_a'] ? timezone_format($logger['ts_a'], $logger['timezone']) : null;
        }

        $template = $request->isMobile() ?
            'logger/mobile/index.html' :
            'logger/index.html';

        return $this->view->render($response, $template, [
            'loggers' => $logger_data,
            // 'total_data' => $total_data,
        ]);
	});

    $this->get('/add', function (Request $request, Response $response, $args) {
        $user = $this->user;

        $logger = [
            'sn' => '',
            'location_id' => '',
            'tenant_id' => $user['tenant_id'] ?: intval($request->getParam('t', 0)),
        ];

        if ($user['tenant_id'] > 0) {
            $tenants = $this->db->query("SELECT * FROM tenant WHERE id={$user['tenant_id']} ORDER BY nama")->fetchAll();
        } else {
            $tenants = $this->db->query("SELECT * FROM tenant ORDER BY nama")->fetchAll();
        }

        return $this->view->render($response, 'logger/edit.html', [
            'mode' => 'Add',
            'logger' => $logger,
            'tenants' => $tenants
        ]);
    });

    $this->post('/add', function (Request $request, Response $response, $args) {
        $logger = $request->getParams();

        $stmt = $this->db->prepare("INSERT INTO logger (sn, location_id, tenant_id) VALUES (:sn, :location_id, :tenant_id)");
        $stmt->bindValue(':sn', $logger['sn']);
        $stmt->bindValue(':location_id', $logger['location_id'] ? $logger['location_id'] : null);
        $stmt->bindValue(':tenant_id', $logger['tenant_id'] ? $logger['tenant_id'] : null);
        $stmt->execute();

        $this->flash->addMessage('messages', "Logger {$logger['sn']} telah ditambahkan");
        
        return $response->withRedirect('/logger');
    });

    $this->get('/sehat', function (Request $request, Response $response, $args) {
        date_default_timezone_set('UTC');

        $user = $this->user;
        $sampling = $request->getParam('sampling');
        if (empty($sampling)) {
            $sampling = date('Y-m-d');
        }
        $timezone_default = timezone_default();

        if ($user['tenant_id'] > 0)
        {
            $loggers_stmt = $this->db->query("SELECT
                    logger.*,
                    location.nama AS location_nama,
                    COALESCE(tenant.timezone, '{$timezone_default}') AS timezone
                FROM logger
                    LEFT JOIN location ON logger.location_id = location.id
                    LEFT JOIN tenant ON logger.tenant_id = tenant.id
                WHERE
                    logger.tenant_id = {$user['tenant_id']}
                ORDER BY logger.sn");
        }
        else
        {
            $loggers_stmt = $this->db->query("SELECT
                    logger.*,
                    location.nama AS location_nama,
                    COALESCE(tenant.timezone, '{$timezone_default}') AS timezone
                FROM logger
                    LEFT JOIN location ON logger.location_id = location.id
                    LEFT JOIN tenant ON logger.tenant_id = tenant.id
                ORDER BY logger.sn");
        }
        $loggers = $loggers_stmt->fetchAll();
        // dump($loggers);

        try {
            foreach ($loggers as &$logger) {
                // dapatkan selisih dengan UTC
                date_default_timezone_set($logger['timezone']);
                $utc_offset =  date('Z') / 3600;
                if ($utc_offset >= 0) {
                    $sampling_offset = "-{$utc_offset}";
                } else {
                    $sampling_offset = "+". ($utc_offset * -1);
                }
                
                // hitung batas from & to untuk sampling
                $sampling_from = date('Y-m-d H:i:s', strtotime($sampling ." {$sampling_offset}hour"));
                $sampling_to = date('Y-m-d H:i:s', strtotime($sampling ." +23hour +59min {$sampling_offset}hour"));
                // dump($sampling_from, false);
                // dump($sampling_to);

                // $stmt = $this->db->prepare("SELECT (content->>'sampling')::date, date_part('hour', (content->>'sampling')::date) AS hour, COUNT(*)
                //    FROM raw
                //    WHERE (content->>'device')=:sn AND (content->>'sampling')::date=:sampling
                //    GROUP BY (content->>'sampling')::date, date_part('hour', (content->>'sampling')::date)
                //    ORDER BY (content->>'sampling')");
                $stmt = $this->db->prepare("SELECT
                            sampling::date,
                            (date_part('hour', sampling)) AS hour,
                            COUNT(*)
                        FROM periodik
                        WHERE logger_sn=:sn
                            AND sampling BETWEEN :sampling_from AND :sampling_to
                        GROUP BY
                            sampling::date,
                            date_part('hour', sampling)
                        ORDER BY sampling");
                $stmt->execute([
                    ':sn' => $logger['sn'],
                    ':sampling_from' => $sampling_from,
                    ':sampling_to' => $sampling_to,
                ]);
                $logger['periodik'] = $stmt->fetchAll();
                
                // normalize untuk jam-jam yang kosong
                $periodik = [
                    0,0,0,0,0,0,
                    0,0,0,0,0,0,
                    0,0,0,0,0,0,
                    0,0,0,0,0,0,
                ];

                foreach ($logger['periodik'] as $p) {
                    $hour = ($p['hour'] + $utc_offset) % 24;
                    $periodik[$hour] = $p['count'];
                }

                $logger['periodik'] = $periodik;
            }
        } catch (\Exception $e) {
            // $this->flash->addMessage('errors', 'Tabel periodik belum tersedia');
        }
        unset($logger);
        // dump($loggers);

        $sampling_prev = date('Y-m-d', strtotime($sampling .' -1day'));
        $sampling_next = date('Y-m-d', strtotime($sampling .' +1day'));

        return $this->view->render($response, '/logger/sehat.html', [
            'loggers' => $loggers,
            'sampling' => $sampling,
            'sampling_prev' => $sampling_prev,
            'sampling_next' => $sampling_next,
        ]);
    });

    $this->group('/{id:[0-9]+}', function () {

        $this->get('', function (Request $request, Response $response) {
            return $response->withJson([
                'status' => 200,
                'message' => "OK"
            ]);
        });

        $this->get('/edit', function (Request $request, Response $response, $args) {
	        $logger = $request->getAttribute('logger');
	        $tenants = $this->db->query("SELECT * FROM tenant ORDER BY nama")->fetchAll();

	        return $this->view->render($response, 'logger/edit.html', [
	            'mode' => 'Edit',
	            'logger' => $logger,
	            'tenants' => $tenants
	        ]);
	    });

        $this->post('/edit', function (Request $request, Response $response, $args) {
	        $logger = $request->getAttribute('logger');
	        $logger['sn'] = $request->getParam('sn', $logger['sn']);
	        $logger['location_id'] = $request->getParam('location_id', $logger['location_id']);
	        $logger['tenant_id'] = $request->getParam('tenant_id', $logger['tenant_id']);

	        $now = date('Y-m-d H:i:s');

	        $stmt = $this->db->prepare("UPDATE logger set sn=:sn, location_id=:location_id, tenant_id=:tenant_id, modified_at='$now' WHERE id=:id");
	        $stmt->bindValue(':sn', $logger['sn']);
	        $stmt->bindValue(':location_id', $logger['location_id'] ? $logger['location_id'] : null);
	        $stmt->bindValue(':tenant_id', $logger['tenant_id'] ? $logger['tenant_id'] : null);
	        $stmt->bindValue(':id', $logger['id']);
	        $stmt->execute();

	        $this->flash->addMessage('messages', "Perubahan Logger {$logger['sn']} telah disimpan");
	        
	        return $response->withRedirect('/logger');
	    });
    })->add($getLoggerMiddleware);

    $this->group('/{sn}', function () {

        $this->get('', function (Request $request, Response $response, $args) {
            $logger = $request->getAttribute('logger');
            $loggers = $this->db->query("SELECT * FROM periodik
                WHERE logger_sn='{$logger['sn']}'
                ORDER BY id DESC
                LIMIT 200")->fetchAll();

            $timezone = timezone_default();
            if (!empty($logger['tenant_id'])) {
                $tenant = $this->db->query("SELECT * FROM tenant
                    WHERE id={$logger['tenant_id']}")->fetch();
                if ($tenant && !empty($tenant['timezone'])) {
                    $timezone = $tenant['timezone'];
                }
            }
            foreach ($loggers as &$l) {
                if (!$l['sampling']) {
                    continue;
                }

                $l['sampling'] = $l['sampling'] ? timezone_format($l['sampling'], $timezone) : null;
                $l['up_s'] = $l['up_s'] ? timezone_format($l['up_s'], $timezone) : null;
                $l['ts_a'] = $l['ts_a'] ? timezone_format($l['ts_a'], $timezone) : null;
            }

            $locations = [];
            if ($logger['tenant_id']) {
                $locations = $this->db->query("SELECT * FROM location
                    WHERE tenant_id = {$logger['tenant_id']}
                    ORDER BY nama")
                ->fetchAll();
            }
            $logger['location_nama'] = '';
            if ($logger['location_id']) {
                $location = $this->db->query("SELECT * FROM location
                    WHERE id = {$logger['location_id']}")->fetch();
                if ($location) {
                    $logger['location_nama'] = $location['nama'];
                }
            }

            return $this->view->render($response, 'logger/show.html', [
                'loggers' => $loggers,
                'logger' => $logger,
                'locations' => $locations
            ]);
        });

        $this->post('/config', function (Request $request, Response $response, $args) {
            $logger = $request->getAttribute('logger');

            $form = $request->getParams();

            // location
            if (empty($form['location_id']) || $form['location_id'] == 'null') {
                unset($form['location_id']);
            } else {
                unset($form['location_nama']);
            }

            if (!empty($form['location_nama'])) {
                $location = $this->db->query("SELECT * FROM location WHERE nama = '{$form['location_nama']}'")->fetch();
                if ($location) {
                    $form['location_id'] = $location['id'];
                } else {
                    $stmt = $this->db->prepare("INSERT INTO location (nama, tenant_id) VALUES (:nama, :tenant_id)");
                    $stmt->execute([
                        ':nama' => $form['location_nama'],
                        ':tenant_id' => $logger['tenant_id'],
                    ]);

                    $form['location_id'] = $this->db->lastInsertId();
                }
            }
            unset($form['location_nama']);

            // tipe
            if (empty($form['tipe'])) {
                unset($form['tipe']);
            }

            // tipp_fac
            if (empty($form['tipp_fac']) && $form['tipp_fac'] != '0') {
                unset($form['tipp_fac']);
            }

            // ting_son
            if (empty($form['ting_son']) && $form['ting_son'] != '0') {
                unset($form['ting_son']);
            }

            // temp_cor
            if (empty($form['temp_cor']) && $form['temp_cor'] != '0') {
                unset($form['temp_cor']);
            }

            // humi_cor
            if (empty($form['humi_cor']) && $form['humi_cor'] != '0') {
                unset($form['humi_cor']);
            }

            // batt_cor
            if (empty($form['batt_cor']) && $form['batt_cor'] != '0') {
                unset($form['batt_cor']);
            }

            if (count($form) > 0) {
                $query = "UPDATE logger SET ";
                foreach ($form as $column => $value) {
                    $query .= "{$column} = '{$value}',";
                }
                $query = rtrim($query, ",");

                $query .= " WHERE id = {$logger['id']}";
                $stmt = $this->db->prepare($query);
                $stmt->execute();

                if ($stmt->rowCount() > 0) {
                    $this->flash->addMessage('messages', "Config logger {$logger['sn']} berhasil diubah");
                } else {
                    $this->flash->addMessage('errors', "Gagal mengubah config logger {$logger['sn']}");
                }
            }

            return $response->withRedirect("/logger/{$logger['sn']}");
        });
    })->add($getLoggerMiddleware);
})->add($loggedinMiddleware);
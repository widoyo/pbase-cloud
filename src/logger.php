<?php

use Slim\Http\Request;
use Slim\Http\Response;

$app->group('/logger', function () use ($getLoggerMiddleware) {

    $this->get('', function (Request $request, Response $response, $args) {
		$user = $this->user;

        if ($user['tenant_id'] > 0)
        {
            $loggers_stmt = $this->db->query("SELECT * FROM logger WHERE
                logger.tenant_id = {$user['tenant_id']}");
        }
        else
        {
            $loggers_stmt = $this->db->query("SELECT * FROM logger");
        }
        $logger_data = $loggers_stmt->fetchAll();

        $loggers = [];
        foreach ($logger_data as $logger) {
            // $today = date('Y-m-d 00:00:00');
            $raw = $this->db->query("SELECT
                    *
                FROM
                    raw
                WHERE
                    content->>'device' like '%{$logger['sn']}%'
                ORDER BY
                    id DESC
                LIMIT 1")
            ->fetch();
            if ($raw) {
                $raw['content'] = json_decode($raw['content']);

                if (isset($raw['content']->temperature) && !empty($logger['temp_cor'])) {
                    $raw['content']->temperature += $logger['temp_cor'];
                }

                if (isset($raw['content']->humidity) && !empty($logger['humi_cor'])) {
                    $raw['content']->humidity += $logger['humi_cor'];
                }

                if (isset($raw['content']->battery) && !empty($logger['batt_cor'])) {
                    $raw['content']->battery += $logger['batt_cor'];
                }

                if (isset($raw['content']->tick) && !empty($logger['tipp_fac'])) {
                    $raw['content']->tick += $logger['tipp_fac'];
                }

                // if (isset($raw['content']->distance) && !empty($logger['ting_son'])) {
                //     $raw['content']->distance += $logger['ting_son'];
                // }
            }

            $loggers[] = [
                'sn' => $logger['sn'],
                'raw_id' => $raw['id'],
                'content' => $raw['content'] ?: '',
                'received' => $raw['received']
            ];
        }

        usort($loggers, function ($a, $b) {
            if (!$a['content'] || !$b['content']) {
                if ($a['content']) {
                    return -1;
                } else if ($b['content']) {
                    return 1;
                }

                return $a['sn'] > $b['sn'] ? 1 :
                    ($a['sn'] < $b['sn'] ? -1 : 0);
            }

            return $a['content']->sampling > $b['content']->sampling ? -1 :
                ($a['content']->sampling < $b['content']->sampling ? 1 : 0);
        });

        // $total_data = date('H') * 12 + floor(date('i') / 5);

        return $this->view->render($response, 'logger/mobile/index.html', [
            'loggers' => $loggers,
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

        $this->flash->addMessage('messages', "Logger {$logger[sn]} telah ditambahkan");
        
        return $response->withRedirect('/logger');
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

	        $this->flash->addMessage('messages', "Perubahan Logger {$logger[sn]} telah disimpan");
	        
	        return $response->withRedirect('/logger');
	    });
    })->add($getLoggerMiddleware);

    $this->group('/{sn}', function () {

        $this->get('', function (Request $request, Response $response, $args) {
            $logger = $request->getAttribute('logger');
            $raws = $this->db->query("SELECT * FROM raw
                WHERE
                    content->>'device' like '%{$logger['sn']}%'
                ORDER BY
                    id DESC
                LIMIT 10")
            ->fetchAll();

            $loggers = [];
            foreach ($raws as $raw) {
                $raw['content'] = json_decode($raw['content']);

                if (isset($raw['content']->temperature) && !empty($logger['temp_cor'])) {
                    $raw['content']->temperature += $logger['temp_cor'];
                }

                if (isset($raw['content']->humidity) && !empty($logger['humi_cor'])) {
                    $raw['content']->humidity += $logger['humi_cor'];
                }

                if (isset($raw['content']->battery) && !empty($logger['batt_cor'])) {
                    $raw['content']->battery += $logger['batt_cor'];
                }

                if (isset($raw['content']->tick) && !empty($logger['tipp_fac'])) {
                    $raw['content']->tick += $logger['tipp_fac'];
                }

                // if (isset($raw['content']->distance) && !empty($logger['ting_son'])) {
                //     $raw['content']->distance += $logger['ting_son'];
                // }

                $loggers[] = [
                    'sn' => $logger['sn'],
                    'raw_id' => $raw['id'],
                    'content' => $raw['content'] ?: '',
                    'received' => $raw['received']
                ];
            }

            if (count($loggers) == 0) {
                $logger['content'] = '';
                $loggers[] = $logger;
            } else if (!$logger['tipe']) {
                // set tipe logger jika masih null
                if (isset($loggers[0]['content']->tick)) {
                    $logger['tipe'] = 'arr';
                } else if (isset($loggers[0]['content']->distance)) {
                    $logger['tipe'] = 'awlr';
                }
            }

            $locations = $this->db->query("SELECT * FROM location ORDER BY nama")->fetchAll();

            return $this->view->render($response, 'logger/mobile/show.html', [
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
<?php

use Slim\Http\Request;
use Slim\Http\Response;

$app->group('/logger', function () use ($getLoggerMiddleware) {

    $this->group('/{sn}', function() {

        $this->get('/sensor', function (Request $request, Response $response, $args) {
        	$sn = $args['sn'];
	        $stmt = $this->db->prepare("SELECT * FROM logger WHERE sn=:sn");
	        $stmt->execute([':sn' => $sn]);
	        $logger = $stmt->fetch();

	        if (!$logger) {
	            return $response->withJson([
	                'status' => 404,
	                'message' => 'Not Found'
	            ]);
	        }

	        $url = "https://prinus.net/api/sensor/{$sn}";
	        $method = "GET";
	        $user_token = $this->session->user_basic_auth;
	        $headers = [
	            "Authorization: Basic {$user_token}"
	        ];

	        // $from = date('Y-m-d', strtotime('-1 day')) .' 23:00:00';
	        // $to   = date('Y-m-d') .' 00:00:00';
	        $from = date('Y-m-d') .' 00:00:00';
	        $to   = date('Y-m-d') .' 01:00:00';
	        $cursor = 0;

	        $target_num = 12;

	        $result = json_decode(curl($url, $method, $headers));
	        $labels = [0];
	        $data = [0 => 0];
	        $targets = [0 => $target_num];
	        $raw = [0 => []];
	        $total_data = 0;
	        $invalids = [];
	        foreach ($result as $res) {
	            $sampling = strtotime($res->sampling);
	            $time_set_at = strtotime($res->time_set_at);

	            // check if valid sampling
	            if ($sampling - $time_set_at < 1 * 60) {
	                $invalids[] = $res;
	                continue;
	            }

	            $sampling = date('Y-m-d H:i:s', $sampling);
	            if ($sampling >= $to) {
	                do {
	                    $cursor++;
	                    $labels[] = $cursor;
	                    $data[$cursor] = 0;
	                    $targets[$cursor] = $target_num;
	                    $raw[$cursor] = [];

	                    $from = date('Y-m-d H:i:s', strtotime("$from +1hour"));
	                    $to   = date('Y-m-d H:i:s', strtotime("$to +1hour"));
	                } while ($sampling > $to);
	            }

	            $data[$cursor]++;
	            // if ($data[$cursor] > $target_num) {
	            //     $data[$cursor] = $target_num;
	            // }
	            $raw[$cursor][] = $res;

	            $total_data++;

	            // var_dump($sampling);
	            // die();

	            if ($cursor > 23) { break; }
	        }

	        if ($cursor < 23)
	        {
	            do {
	                $cursor++;
	                $labels[] = $cursor;
	                $data[$cursor] = 0;
	                $targets[$cursor] = $target_num;
	                $raw[$cursor] = [];
	            } while ($cursor < 23);
	        }

	        return $response->withJson([
	            'labels' => $labels,
	            'data' => $data,
	            // 'targets' => $targets,
	            'total_data' => $total_data,
	            'raw' => $raw,
	            'invalids' => $invalids,
	        ]);
        });

        $this->get('/raw', function (Request $request, Response $response, $args) {
        	$limit = intval($request->getParam('limit', 10));
        	$logger = $request->getAttribute('logger');

        	$today_unix = strtotime(date('Y-m-d'));
        	$raws = $this->db->query("SELECT * FROM raw
                WHERE
                    content->>'device' like '%/{$logger['sn']}/%'
                    AND content->>'sampling' > '{$today_unix}'
                ORDER BY id DESC LIMIT {$limit}")
            ->fetchAll();

            $loggers = [];
            foreach ($raws as $raw) {
                $raw['content'] = json_decode($raw['content']);

                // if (isset($raw['content']->temperature) && !empty($logger['temp_cor'])) {
                //     $raw['content']->temperature += $logger['temp_cor'];
                // }

                // if (isset($raw['content']->humidity) && !empty($logger['humi_cor'])) {
                //     $raw['content']->humidity += $logger['humi_cor'];
                // }

                // if (isset($raw['content']->battery) && !empty($logger['batt_cor'])) {
                //     $raw['content']->battery += $logger['batt_cor'];
                // }

                // if (isset($raw['content']->tick) && !empty($logger['tipp_fac'])) {
                //     $raw['content']->tick += $logger['tipp_fac'];
                // }

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

        	return $response->withJson([
        		'status' => 200,
        		'message' => 'OK',
        		'data' => [
        			'loggers' => $loggers
        		]
        	]);
        });

        $this->post('/config', function (Request $request, Response $response, $args) {
        	$logger = $request->getAttribute('logger');

        	$form = $request->getParams();

            // location
            if (empty($form['location_id']) || $form['location_id'] == '-1') {
                unset($form['location_id']);
            } else {
                unset($form['location_nama']);
                unset($form['location_ll']);
            }

            $newLocation = false;
            if (!empty($form['location_nama'])) {
                $location = $this->db->query("SELECT * FROM location WHERE nama = '{$form['location_nama']}'")->fetch();
                if ($location) {
                    $form['location_id'] = $location['id'];
                } else {
                    $stmt = $this->db->prepare("INSERT INTO location (nama, tenant_id, ll) VALUES (:nama, :tenant_id, :ll)");
                    $stmt->execute([
                        ':nama' => $form['location_nama'],
                        ':tenant_id' => $logger['tenant_id'],
                        ':ll' => $form['location_ll'],
                    ]);

                    $form['location_id'] = $this->db->lastInsertId();
                    $newLocation = true;
                }
            }
            unset($form['location_nama']);
            unset($form['location_ll']);

            // tipe
            if (isset($form['tipe']) && empty($form['tipe'])) {
                unset($form['tipe']);
            }

            // tipp_fac
            if (isset($form['tipp_fac']) && empty($form['tipp_fac']) && $form['tipp_fac'] != '0') {
                unset($form['tipp_fac']);
            }

            // ting_son
            if (isset($form['ting_son']) && empty($form['ting_son']) && $form['ting_son'] != '0') {
                unset($form['ting_son']);
            }

            // temp_cor
            if (isset($form['temp_cor']) && empty($form['temp_cor']) && $form['temp_cor'] != '0') {
                unset($form['temp_cor']);
            }

            // humi_cor
            if (isset($form['humi_cor']) && empty($form['humi_cor']) && $form['humi_cor'] != '0') {
                unset($form['humi_cor']);
            }

            // batt_cor
            if (isset($form['batt_cor']) && empty($form['batt_cor']) && $form['batt_cor'] != '0') {
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
                    if ($newLocation) {
                        return $response->withJson([
                            'status' => 200,
                            'message' => 'success',
                            'data' => [
                                'location' => [
                                    'id' => $form['location_id'],
                                    'nama' => $request->getParam('location_nama'),
                                    'll' => $request->getParam('location_ll'),
                                ]
                            ]
                        ]);
                    } else {
                        return $response->withJson([
                            'status' => 200,
                            'message' => 'success'
                        ]);
                    }
                } else {
                	return $response->withJson([
                		'status' => 500,
                		'message' => 'failed'
                	]);
                }
            }

            return $response->withJson([
        		'status' => 400,
        		'message' => 'empty parameter'
        	]);
        });
    })->add($getLoggerMiddleware);
});
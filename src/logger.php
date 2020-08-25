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
                        logger.location_id,
                        logger.tenant_id,
                        location.nama AS location_nama,
                        tenant.nama AS tenant_nama
                    FROM logger
                        LEFT JOIN location ON logger.location_id = location.id
                        LEFT JOIN tenant ON logger.tenant_id = tenant.id
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
                        logger.location_id,
                        logger.tenant_id,
                        location.nama AS location_nama,
                        tenant.nama AS tenant_nama
                    FROM logger
                        LEFT JOIN location ON logger.location_id = location.id
                        LEFT JOIN tenant ON logger.tenant_id = tenant.id
                    ORDER BY
                        location.nama,
                        logger.sn");
            }
            $logger_data = $loggers_stmt->fetchAll();
        }

        // foreach ($logger_data as &$logger) {
        //     if (!$logger['latest_sampling']) {
        //         continue;
        //     }

        //     $logger['latest_sampling'] = $logger['latest_sampling'] ? timezone_format($logger['latest_sampling'], $logger['timezone']) : null;
        //     $logger['up_s'] = $logger['up_s'] ? timezone_format($logger['up_s'], $logger['timezone']) : null;
        //     $logger['ts_a'] = $logger['ts_a'] ? timezone_format($logger['ts_a'], $logger['timezone']) : null;
        // }

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

        $stmt = $this->db->prepare("INSERT INTO logger (sn, location_id, tenant_id, tipe) VALUES (:sn, :location_id, :tenant_id, :tipe)");
        $stmt->bindValue(':sn', $logger['sn']);
        $stmt->bindValue(':tipe', $logger['tipe']);
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

        // try {
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
                $sampling_from_int = strtotime($sampling_from);
                $sampling_to_int = strtotime($sampling_to);
                // dump($sampling_from, false);
                // dump($sampling_to);

                $sql = "SELECT
                        (to_timestamp((content->>'sampling')::bigint))::date,
                        date_part('hour', to_timestamp((content->>'sampling')::bigint)) AS hour,
                        COUNT(content->>'sampling')
                    FROM raw
                    WHERE (content->>'device') LIKE '%/{$logger['sn']}/%'
                        AND (content->>'sampling')::bigint BETWEEN {$sampling_from_int} AND {$sampling_to_int}
                    GROUP BY
                        (to_timestamp((content->>'sampling')::bigint))::date,
                        date_part('hour', to_timestamp((content->>'sampling')::bigint))
                    ORDER BY (to_timestamp((content->>'sampling')::bigint))::date";
                // dump($sql, false);
                $stmt = $this->db->query($sql);
                // $stmt = $this->db->prepare("SELECT
                //             sampling::date,
                //             (date_part('hour', sampling)) AS hour,
                //             COUNT(*)
                //         FROM periodik
                //         WHERE logger_sn=:sn
                //             AND sampling BETWEEN :sampling_from AND :sampling_to
                //         GROUP BY
                //             sampling::date,
                //             date_part('hour', sampling)
                //         ORDER BY sampling");
                // $stmt->execute([
                //     ':sn' => $logger['sn'],
                //     ':sampling_from' => $sampling_from,
                //     ':sampling_to' => $sampling_to,
                // ]);
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
        // } catch (\Exception $e) {
        //     $this->flash->addMessage('errors', 'Tabel periodik belum tersedia');
        // }
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
    })->add($getLoggerMiddleware);

    $this->group('/{sn}', function () {

        $this->get('', function (Request $request, Response $response, $args) {
            $logger = $request->getAttribute('logger');
            $loggers = $this->db->query("SELECT * FROM raw
                WHERE content->>'device' LIKE '%/{$logger['sn']}/%'
                ORDER BY (content->>'sampling')::bigint DESC
                LIMIT 200")->fetchAll();

            $timezone = timezone_default();
            if (!empty($logger['tenant_id'])) {
                $tenant = $this->db->query("SELECT * FROM tenant
                    WHERE id={$logger['tenant_id']}")->fetch();
                if ($tenant && !empty($tenant['timezone'])) {
                    $timezone = $tenant['timezone'];
                }
            }
            $axes_raw = [];
            $result = [
                'datasets' => [
                    'sq' => [],
                    'tick' => []
                ],
                'labels' => [],
                'colors' => [],
                'title' => ['SQ', 'TICK']
            ];
            $result['colors'] = [
                "0,0,255",
                // "0,255,0",
                // "255,0,0",
                "255,0,255",
                "0,255,255",
                "255,255,0"
            ];
            $prev_d = '';
            foreach ($loggers as &$l) {
                // if (!$l['sampling']) {
                //     continue;
                // }

                // if use raw
                $content = json_decode($l['content'], true);
                if (!$content['sampling']) {
                    continue;
                }
                $l['sampling'] = $content['sampling'];
                $l['up_s'] = $content['up_since'];
                $l['ts_a'] = $content['time_set_at'];
                $l['sq'] = isset($content['signal_quality']) ? $content['signal_quality'] : null;
                $l['batt'] = isset($content['battery']) ? $content['battery'] : null;
                $l['tick'] = isset($content['tick']) ? $content['tick'] : null;
                $l['distance'] = isset($content['distance']) ? $content['distance'] : null;
                // if use raw

                // insert to line chart
                $axes_raw[$l['sampling']] = [
                    'distance' => $l['distance'],
                    'batt' => $l['batt'],
                ];
                // insert to bar chart
                $result['datasets']['sq'][] = $l['sq'];
                $result['datasets']['tick'][] = $l['tick'];

                // $l['sampling'] = $l['sampling'] ? strtotime(timezone_format($l['sampling'], $timezone)) : null;
                $l['sampling_str'] = $l['sampling'] ? timezone_format($l['sampling'], $timezone) : null;
                $l['up_s'] = $l['up_s'] ? timezone_format($l['up_s'], $timezone) : null;
                $l['ts_a'] = $l['ts_a'] ? timezone_format($l['ts_a'], $timezone) : null;

                // label bar
                $ss = explode(' ', $l['sampling_str']);
                $d = explode('-', $ss[0]);
                $m = $d[1];
                $d = $d[2];
                $md = "{$m}/{$d}";
                $H = explode(':', $ss[1]);
                $i = $H[1];
                $H = $H[0];
                if ($prev_d != $md) {
                    $prev_d = $md;
                    $result['labels'][] = "{$m}/{$d}, {$H}:{$i}";
                } else {
                    $result['labels'][] = "{$H}:{$i}";
                }
            }
            
            $plot_timelines = [];
            $plot_tick = [];
            $plot_distance = [];
            $plot_sq = [];
            $plot_batt = [];
            // $plot_limit = strtotime('02:00:00') - strtotime('00:00:00'); // 1 hour
            // // get to kelipatan 5 menit terdekat
            // $jam = strtotime(date('H:00:00'));
            // $now = strtotime(date('H:i:s'));
            // $plot_to = $jam;
            // while ($plot_to + 300 <= $now) {
            //     $plot_to += 300;
            // }
            // // tambahkan hari, krn sebelum ini baru jam saja
            // $plot_to = strtotime(date('Y-m-d') .' '. date('H:i:s', $plot_to));
            // $plot_to = strtotime('2020-08-15 08:00:00');
            // // foreach ($axes_raw as $sampling => $data) {
            // //     $plot_to = $sampling;
            // //     break;
            // // }
            // // get from
            // $plot_from = $plot_to - $plot_limit;
            // $default_raw = [
            //     'tick' => null,
            //     'distance' => null,
            //     'sq' => null,
            //     'batt' => null,
            // ];
            // // dump(date('Y-m-d H:i:s', $plot_to), false);
            // // dump(date('Y-m-d H:i:s', $plot_from), true);
            // while ($plot_from <= $plot_to) {
            //     $plot_timelines[] = $plot_from;
            //     if (isset($axes_raw[$plot_from])) {
            //         $raw = $axes_raw[$plot_from];
            //     } else {
            //         $raw = $default_raw;
            //     }
            //     $plot_tick[] = $raw['tick'];
            //     $plot_distance[] = $raw['distance'];
            //     $plot_sq[] = $raw['sq'];
            //     $plot_batt[] = $raw['batt'];
            //     $plot_from += 300; // 5 min
            // }
            for ($i=count($loggers)-1; $i>=0; $i--) {
                $raw = $loggers[$i];
                $plot_timelines[] = $raw['sampling'];
                $plot_tick[] = $raw['tick'];
                $plot_distance[] = $raw['distance'];
                $plot_sq[] = $raw['sq'];
                $plot_batt[] = $raw['batt'];
            }
            // dump($plot_tick);
            $plot = [
                'timelines' => $plot_timelines,
                'tick' => $plot_tick,
                'distance' => $plot_distance,
                'sq' => $plot_sq,
                'batt' => $plot_batt,
            ];

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

            // dump($result['datasets']);

            return $this->view->render($response, 'logger/show.html', [
                'loggers' => $loggers,
                'logger' => $logger,
                'locations' => $locations,
                'plot' => $plot,
                'result' => $result,
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

        $this->get('/edit', function (Request $request, Response $response, $args) {
            $logger = $request->getAttribute('logger');
	        $tenants = $this->db->query("SELECT * FROM tenant ORDER BY nama")->fetchAll();

            if ($this->user['tenant_id'] > 0) {
                $this->flash->addMessage('errors', "Anda tidak dapat mengakses halaman ini");
                return $response->withRedirect('/logger/'. $logger['sn']);
            }

	        return $this->view->render($response, 'logger/edit.html', [
	            'mode' => 'Edit',
	            'logger' => $logger,
	            'tenants' => $tenants
	        ]);
	    });

        $this->post('/edit', function (Request $request, Response $response, $args) {
	        $logger = $request->getAttribute('logger');

            if ($this->user['tenant_id'] > 0) {
                $this->flash->addMessage('errors', "Anda tidak dapat mengakses halaman ini");
                return $response->withRedirect('/logger/'. $logger['sn']);
            }

	        $logger['sn'] = $request->getParam('sn', $logger['sn']);
	        $logger['location_id'] = $request->getParam('location_id', $logger['location_id']);
	        $logger['tenant_id'] = $request->getParam('tenant_id', $logger['tenant_id']);
	        $logger['tipe'] = $request->getParam('tipe', $logger['tipe']);

	        $now = date('Y-m-d H:i:s');

	        $stmt = $this->db->prepare("UPDATE logger set sn=:sn, location_id=:location_id, tenant_id=:tenant_id, tipe=:tipe, modified_at='$now' WHERE id=:id");
	        $stmt->bindValue(':sn', $logger['sn']);
	        $stmt->bindValue(':tipe', $logger['tipe']);
	        $stmt->bindValue(':location_id', $logger['location_id'] ? $logger['location_id'] : null);
	        $stmt->bindValue(':tenant_id', $logger['tenant_id'] ? $logger['tenant_id'] : null);
	        $stmt->bindValue(':id', $logger['id']);
	        $stmt->execute();

	        $this->flash->addMessage('messages', "Perubahan Logger {$logger['sn']} telah disimpan");
	        
	        return $response->withRedirect('/logger/'. $logger['sn']);
	    });
    })->add($getLoggerMiddleware);
})->add($loggedinMiddleware);
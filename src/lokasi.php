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
            // $location = $request->getAttribute('location');
            $tenants = $this->db->query("SELECT * FROM tenant ORDER BY nama")->fetchAll();

            $pclient = new Predis\Client();
            $location = $pclient->hgetall("location:{$args['id']}");
            if (count($location) == 0) {
                $location = $request->getAttribute('location');
            }
            // dump($location);

            $end_date = $request->getParam('end_date', '');
            if (empty($end_date)) {
                $end_date = date("Y-m-d");
            }
            $start_date = $request->getParam('start_date', '');
            if (empty($start_date)) {
                $start_date = date("Y-m-d", strtotime("{$end_date} -3 months"));
            }
            // $periodik_min = $this->db->query("SELECT sampling::date, rain FROM periodik
            //     WHERE location_id={$location['id']}
            //         AND sampling::date BETWEEN '{$from}' AND '{$to}'
            //     ORDER BY sampling, rain DESC")->fetchAll(\PDO::FETCH_KEY_PAIR);
            // $periodik_max = $this->db->query("SELECT sampling::date, rain FROM periodik
            //     WHERE location_id={$location['id']}
            //         AND sampling::date BETWEEN '{$from}' AND '{$to}'
            //     ORDER BY sampling, rain")->fetchAll(\PDO::FETCH_KEY_PAIR);
            // dump($periodik_max);
            
            // preparing initial datasets (0s) and labels (day)
            $result = [
                'datasets' => [
                    'min' => [],
                    'max' => []
                ],
                'labels' => [],
                'colors' => [],
                'title' => ['min', 'max']
            ];
            $result['colors'] = [
                "0,0,255",
                "0,255,0",
                "255,0,0",
                "255,0,255",
                "0,255,255",
                "255,255,0"
            ];

            $from = $start_date;
            $to = $end_date;
            while ($from != $to) {
                $min = 0;
                $max = 0;
                $result['labels'][] = tanggal_format(strtotime($from));

                $res = $pclient->hgetall("location:{$location['id']}:periodik:harian:{$from}");
                if (count($res) > 0) {
                    if ($location['tipe'] == 2) {
                        $min = doubleval($res['wlev_min']);
                        $max = doubleval($res['wlev_max']);
                    } else {
                        $min = doubleval($res['rain_min']);
                        $max = doubleval($res['rain_max']);
                    }
                } else {
                    $res = $this->db->query("SELECT * FROM periodik WHERE location_id={$location['id']} AND sampling::date='{$from}' ORDER BY rain, wlev")->fetchAll();
                    if ($res && count($res) > 0) {
                        if ($location['tipe'] == 2) {
                            $min = doubleval($res[0]['wlev']);
                            $max = doubleval($res[count($res)-1]['wlev']);
                        } else {
                            $min = doubleval($res[0]['rain']);
                            $max = doubleval($res[count($res)-1]['rain']);
                        }
                    }

                    if ($location['tipe'] == 2) {
                        $rdc_data['wlev_min'] = $min;
                        $rdc_data['wlev_max'] = $max;
                    } else {
                        $rdc_data['rain_min'] = $min;
                        $rdc_data['rain_max'] = $max;
                    }
                    $rdc_data['tanggal'] = date('d', strtotime($from));
                    $pclient->hmset("location:{$location['id']}:periodik:harian:{$from}", $rdc_data);
                }
                // if (isset($periodik_max[$from])) {
                //     $p['max'] = $periodik_max[$from];
                // }
                // if (isset($periodik_min[$from])) {
                //     $p['min'] = $periodik_min[$from];
                // }

                $result['datasets']['min'][] = $min;
                $result['datasets']['max'][] = $max;
                $from = date("Y-m-d", strtotime("{$from} +1day"));
            }
            // dump($result);

            // get ringkasan data
            if (
                !isset($location['latest_sampling'])
                || !isset($location['total_data_diterima'])
                || !isset($location['total_data_seharusnya'])
                || !isset($location['persen_data_diterima'])
            ) {
                $first_periodik = $this->db->query("SELECT * FROM periodik WHERE location_id={$location['id']} ORDER BY id")->fetch();
                $latest_periodik = $this->db->query("SELECT * FROM periodik WHERE location_id={$location['id']} ORDER BY id DESC")->fetch();
                $total_data_diterima = $this->db->query("SELECT COUNT(*) FROM periodik WHERE location_id={$location['id']}")->fetch();
                if ($total_data_diterima) {
                    $total_data_diterima = $total_data_diterima['count'];
                }

                $first_sampling = null;
                $latest_sampling = null;
                $total_data_seharusnya = 0;
                $persen_data_diterima = 0;
                if ($first_periodik && $latest_periodik) {
                    $latest_sampling = $latest_periodik['sampling'];

                    $first = strtotime($first_periodik['sampling']);
                    $last = strtotime($latest_periodik['sampling']);
                    $total_data_seharusnya = ($last - $first) / (60 * 5);
                    if ($total_data_seharusnya > 0) {
                        $persen_data_diterima = $total_data_diterima * 100 / $total_data_seharusnya;
                    }

                    $rdc_data = [];
                    $rdc_data['first_sampling'] = $first_sampling;
                    $rdc_data['latest_sampling'] = $latest_sampling;
                    $rdc_data['total_data_diterima'] = $total_data_diterima;
                    $rdc_data['total_data_seharusnya'] = $total_data_seharusnya;
                    $rdc_data['persen_data_diterima'] = $persen_data_diterima;
                    $pclient->hmset("location:{$location['id']}", $rdc_data);
                }
            } else {
                $latest_sampling = $location['latest_sampling'];
                $total_data_diterima = $location['total_data_diterima'];
                $total_data_seharusnya = $location['total_data_seharusnya'];
                $persen_data_diterima = $location['persen_data_diterima'];
            }

            // get total data logger
            $loggers = $this->db->query("SELECT logger_sn, COUNT(*) FROM periodik
                WHERE location_id={$location['id']}
                GROUP BY logger_sn
                ORDER BY logger_sn")->fetchAll();
            // dump($loggers);

			return $this->view->render($response, 'location/mobile/show.html', [
				'location' => $location,
				'tenants' => $tenants,
				'result' => $result,
				'start_date' => $start_date,
				'end_date' => $end_date,
				'latest_sampling' => $latest_sampling,
				'total_data_diterima' => $total_data_diterima,
				'total_data_seharusnya' => $total_data_seharusnya,
				'persen_data_diterima' => $persen_data_diterima,
				'loggers' => $loggers,
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

            $form['elevasi'] = $form['elevasi'] ?: null;
            // dump($form);

            if (count($form) > 0) {
                $query = "UPDATE location SET ";
                foreach ($form as $column => $value) {
                    if ($value === null) {
                        $query .= "{$column} = NULL,";
                    } else {
                        $query .= "{$column} = '{$value}',";
                    }
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
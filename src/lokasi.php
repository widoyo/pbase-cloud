<?php

use Slim\Http\Request;
use Slim\Http\Response;

$app->group('/location', function () use ($getLocationMiddleware) {

    $this->get('', function (Request $request, Response $response, $args) {
        // override utk cek mobile
        $request  = new Slim\Http\MobileRequest($request);
		$response = new Slim\Http\MobileResponse($response);
        $user = $this->user;

        // cek apakah redis available
        try {
            $pclient = new Predis\Client();
            $pclient->connect();
        } catch (Predis\Connection\ConnectionException $e) {
            $pclient = null;
        }

        $location_data = [];
        if ($pclient) {
            if ($user['tenant_id'] > 0) {
                $keys = $pclient->smembers("tenant:{$user['tenant_id']}:location");
                foreach ($keys as $key) {
                    $location_data[] = $pclient->hgetall($key);
                }
                // $location_data = $this->db->query("SELECT * FROM location
                //     WHERE
                //         location.tenant_id = {$user['tenant_id']}
                //     ORDER BY nama"
                //     )->fetchAll();
            } else {
                $keys = $pclient->smembers("location");
                foreach ($keys as $key) {
                    $location_data[] = $pclient->hgetall($key);
                }
                // $location_data = $this->db->query("SELECT * FROM location
                //     ORDER BY nama"
                //     )->fetchAll();
            }
        } else {
            if ($user['tenant_id'] > 0) {
                $location_data = $this->db->query("SELECT
                        location.*,
                        logger.tipe AS logger_tipe
                    FROM
                        location
                        LEFT JOIN logger ON (location.id = logger.location_id)
                    WHERE
                        location.tenant_id = {$user['tenant_id']}
                    ORDER BY nama"
                    )->fetchAll();
            } else {
                $location_data = $this->db->query("SELECT
                        location.*,
                        logger.tipe AS logger_tipe
                    FROM
                        location
                        LEFT JOIN logger ON (location.id = logger.location_id)
                    ORDER BY nama"
                    )->fetchAll();
            }
            foreach ($location_data as &$l) {
                $latest_periodik = $this->db->query("SELECT * FROM periodik WHERE location_id={$l['id']} ORDER BY id DESC")->fetch();
                if ($latest_periodik) {
                    $l['latest_sampling'] = $latest_periodik['sampling'];
                }
                if (empty($l['tipe'])) {
                    $logger_tipe = strtolower($l['logger_tipe']);
                    switch ($logger_tipe) {
                        case 'arr':
                            $l['tipe'] = '1';
                            break;
            
                        case 'awlr':
                            $l['tipe'] = '2';
                            break;
            
                        case 'klimat':
                            $l['tipe'] = '4';
                            break;
            
                        default:
                            $l['tipe'] = '0';
                            break;
                    }
                }
            }
        }
        // dump($location_data);

        $tenants = $this->db->query("SELECT * FROM tenant ORDER BY nama")->fetchAll();

        $template = $request->isMobile() ?
            'location/mobile/index.html' :
            'location/index.html';
        return $this->view->render($response, $template, [
            'locations' => $location_data,
            // 'total_data' => $total_data,
            'tenants' => $tenants
        ]);
    });

    $this->group('/add', function () {

        $this->get('', function (Request $request, Response $response, $args) {
            $tenants = $this->db->query("SELECT * FROM tenant ORDER BY nama")->fetchAll();

            return $this->view->render($response, 'location/add.html', [
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
            // override utk cek mobile
            $request  = new Slim\Http\MobileRequest($request);
            $response = new Slim\Http\MobileResponse($response);

            // $location = $request->getAttribute('location');
            $tenants = $this->db->query("SELECT * FROM tenant ORDER BY nama")->fetchAll();

            // cek apakah redis available
            try {
                $pclient = new Predis\Client();
                $pclient->connect();
            } catch (Predis\Connection\ConnectionException $e) {
                $pclient = null;
            }

            if ($pclient) {
                $location = $pclient->hgetall("location:{$args['id']}");
            }
            if (!isset($location)) {
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

                $res = NULL;
                if ($pclient) {
                    $res = $pclient->hgetall("location:{$location['id']}:periodik:harian:{$from}");
                }

                if ($res && count($res) > 0) {
                    if ($location['tipe'] == 2) {
                        $min = isset($res['wlev_min']) ? doubleval($res['wlev_min']) : 0;
                        $max = isset($res['wlev_max']) ?  doubleval($res['wlev_max']) : 0;
                    } else {
                        $min = isset($res['rain_min']) ? doubleval($res['rain_min']) : 0;
                        $max = isset($res['rain_max']) ? doubleval($res['rain_max']) : 0;
                    }
                } else {
                    $res = $this->db->query("SELECT * FROM periodik WHERE location_id={$location['id']} AND sampling::date='{$from}' ORDER BY rain, wlev")->fetchAll();
                    if ($res && count($res) > 0) {
                        if ($location['tipe'] == 2) {
                            $min = doubleval($res[0]['wlev']);
                            $max = doubleval($res[count($res) - 1]['wlev']);
                        } else {
                            $min = doubleval($res[0]['rain']);
                            $max = doubleval($res[count($res) - 1]['rain']);
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
                    if ($pclient) {
                        $pclient->hmset("location:{$location['id']}:periodik:harian:{$from}", $rdc_data);
                    }
                }

                $result['datasets']['min'][] = $min;
                $result['datasets']['max'][] = $max;
                $from = date("Y-m-d", strtotime("{$from} +1day"));
            }
            // dump($location);

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
                    if ($first == $last) {
                        $total_data_seharusnya = 1;
                    } else {
                        $total_data_seharusnya = ($last - $first) / (60 * 5);
                    }
                    if ($total_data_seharusnya > 0) {
                        $persen_data_diterima = $total_data_diterima * 100 / $total_data_seharusnya;
                    }

                    $rdc_data = [];
                    $rdc_data['first_sampling'] = $first_sampling;
                    $rdc_data['latest_sampling'] = $latest_sampling;
                    $rdc_data['total_data_diterima'] = $total_data_diterima;
                    $rdc_data['total_data_seharusnya'] = $total_data_seharusnya;
                    $rdc_data['persen_data_diterima'] = $persen_data_diterima;
                    if ($pclient) {
                        $pclient->hmset("location:{$location['id']}", $rdc_data);
                    }
                }
            } else {
                $latest_sampling = $location['latest_sampling'];
                $total_data_diterima = $location['total_data_diterima'];
                $total_data_seharusnya = $location['total_data_seharusnya'];
                $persen_data_diterima = $location['persen_data_diterima'];
            }

            // get total data logger
            $logger_keys = NULL;
            if ($pclient) {
                $logger_keys = $pclient->smembers("location:{$args['id']}:logger");
            }
            if ($logger_keys && count($logger_keys) > 0) {
                $loggers = [];
                foreach ($logger_keys as $key) {
                    $loggers[] = $pclient->hgetall($key);
                }
            } else {
                $loggers = $this->db->query("SELECT logger_sn as sn, COUNT(*) FROM periodik
                    WHERE location_id={$location['id']}
                    GROUP BY logger_sn
                    ORDER BY logger_sn")->fetchAll();
            }
            // dump($location);

            $template = $request->isMobile() ?
                'location/mobile/show.html' :
                'location/show.html';

            return $this->view->render($response, $template, [
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

        $this->get('/download', function (Request $request, Response $response, $args) {
            $location = $request->getAttribute('location');
            $date_format = "Y-m-d\TH:i";
            $delimiter = ";";

            $month = $request->getParam('month', '');
            if (empty($month)) {
                $month = date('Y-m');
            }

            $option = $request->getParam('option', '');
            if (empty($option)) {
                $option = '5menit';
            }

            // cek apakah date valid
            $d = DateTime::createFromFormat('Y-m', $month);
            if (!$d || $d->format('Y-m') !== $month) {
                $this->flash->addMessage('errors', "Invalid format for month");
                return $response->withRedirect("/location/{$location['id']}");
            }

            switch ($option) {
                case '1jam':
                    list($header, $data) = download1jam(
                        $this->db,
                        $location,
                        date('Y-m-01', strtotime($month)),
                        date('Y-m-t', strtotime($month)),
                        $date_format,
                        $delimiter
                    );
                    break;

                case '24jam':
                    list($header, $data) = download24jam(
                        $this->db,
                        $location,
                        date('Y-m-01', strtotime($month)),
                        date('Y-m-t', strtotime($month)),
                        $date_format,
                        $delimiter
                    );
                    break;

                case '5menit':
                default:
                    list($header, $data) = download5menit(
                        $this->db,
                        $location,
                        date('Y-m-01', strtotime($month)),
                        date('Y-m-t', strtotime($month)),
                        $date_format,
                        $delimiter
                    );
                    break;
            }

            // to csv
            $csv = implode($delimiter, $header) . "\n";
            $csv .= implode("\n", $data);

            // stream
            $stream = fopen('php://memory', 'r+');
            fwrite($stream, $csv);
            rewind($stream);

            $month = date('mY', strtotime($month));
            $filename = "pos_{$location['id']}_{$month}.csv";
            return $response
                ->withHeader('Content-Type', 'application/octet-stream')
                ->withHeader('Content-Disposition', 'attachment;filename="' . $filename . '"')
                ->withBody(new \Slim\Http\Stream($stream));
        });
    })->add($getLocationMiddleware);
})->add($loggedinMiddleware);

function download5menit($db, $location, $from, $to, $date_format = "Y-m-d\TH:i", $delimiter = ";")
{
    // set no value
    $data = [];
    // cek $date_format
    $start = "{$from}T00:00";
    $finish = "{$to}T23:55";
    $counter = $start;
    while ($counter <= $finish) {
        $data[$counter] = "{$counter}{$delimiter}{$delimiter}{$delimiter}";
        $counter = date($date_format, strtotime("{$counter} +5minute"));
    }

    // insert periodik to data
    $periodik = $db->query("SELECT * FROM periodik
        WHERE
            location_id={$location['id']} AND
            sampling BETWEEN '{$from}' AND '{$to}'
        ORDER BY sampling")->fetchAll();
    foreach ($periodik as $p) {
        $current = date($date_format, strtotime($p['sampling']));
        $dt = [$current];
        if ($location['tipe'] == 2) {
            // 2 = awlr / PDA
            $dt[] = $p['wlev'];
        } else {
            // 1,4 = arr / PCH, Klimat
            $dt[] = $p['rain'];
        }
        $dt[] = $p['sq'];
        $dt[] = $p['batt'];

        // format to csv
        $data[$current] = implode(";", $dt);
    }

    $header = [
        'sampling',
        $location['tipe'] == 2 ? 'wlevel(m)' : 'rain(mm)',
        'sq',
        'batt'
    ];

    return [$header, $data];
}

function download1jam($db, $location, $from, $to, $date_format = "Y-m-d\TH:i", $delimiter = ";")
{
    // set no value
    $data = [];
    // cek $date_format
    $start = "{$from}T00:00";
    $finish = "{$to}T23:00";
    $counter = $start;
    while ($counter <= $finish) {
        // $data[$counter] = "{$counter}{$delimiter}{$delimiter}{$delimiter}";
        if ($location['tipe'] == 2) {
            // 2 = awlr / PDA
            $data[$counter] = [
                'sampling' => $counter,
                'wlev_min' => '',
                'wlev_max' => '',
                'wlev_avg' => '',
                'sq' => '',
                'batt' => '',
            ];
        } else {
            // 1,4 = arr / PCH, Klimat
            $data[$counter] = [
                'sampling' => $counter,
                'rain' => '',
                'sq' => '',
                'batt' => '',
            ];
        }
        $counter = date($date_format, strtotime("{$counter} +1hour"));
    }

    // dikurang 1 jam
    // 00:00 = hari sebelumnya 23:00 - 23:55
    $from = date('Y-m-d 23:00:00', strtotime($from . ' -1day'));
    $to = date('Y-m-d 22:55:00', strtotime($to));

    // insert periodik to data
    $periodik = $db->query("SELECT * FROM periodik
        WHERE
            location_id={$location['id']} AND
            sampling BETWEEN '{$from}' AND '{$to}'
        ORDER BY sampling")->fetchAll();
    $counter = count($periodik) > 0 ? date('Y-m-d\TH:00', strtotime($periodik[0]['sampling'] . ' +1hour')) : '';
    $wlev_sum = 0;
    $wlev_total = 0;
    foreach ($periodik as $p) {
        $current = date('Y-m-d\TH:00', strtotime($p['sampling'] . ' +1hour'));
        if ($current > $counter) {
            $counter = $current;
            $wlev_sum = 0;
            $wlev_total = 0;
        }

        if ($location['tipe'] == 2) {
            // 2 = awlr / PDA
            if (empty($data[$counter]['wlev_min'])) {
                $data[$counter]['wlev_min'] = PHP_INT_MAX;
            }
            if (empty($data[$counter]['wlev_max'])) {
                $data[$counter]['wlev_max'] = 0;
            }

            if ($p['wlev'] < $data[$counter]['wlev_min']) {
                $data[$counter]['wlev_min'] = $p['wlev'];
            }

            if ($p['wlev'] > $data[$counter]['wlev_max']) {
                $data[$counter]['wlev_max'] = $p['wlev'];
            }

            $wlev_sum += $p['wlev'];
            $wlev_total++;
            $data[$counter]['wlev_avg'] = round($wlev_sum / $wlev_total, 2);
        } else {
            // 1,4 = arr / PCH, Klimat
            if (empty($data[$counter]['rain'])) {
                $data[$counter]['rain'] = 0;
            }
            $data[$counter]['rain'] += $p['rain'];
        }
        $data[$counter]['sq'] = $p['sq'];
        $data[$counter]['batt'] = $p['batt'];
    }

    array_walk($data, function (&$value) use ($delimiter) {
        $value = implode($delimiter, $value);
    });

    // dump($data);

    if ($location['tipe'] == 2) {
        // 2 = awlr / PDA
        $header = [
            'sampling',
            'wlevel min(m)',
            'wlevel max(m)',
            'wlevel rerata(m)',
            'sq',
            'batt'
        ];
    } else {
        // 1,4 = arr / PCH, Klimat
        $header = [
            'sampling',
            'rain(mm)',
            'sq',
            'batt'
        ];
    }

    return [$header, $data];
}

function download24jam($db, $location, $from, $to, $date_format = "Y-m-d\TH:i", $delimiter = ";")
{
    // set no value
    $data = [];
    // cek $date_format
    $start = "{$from}T00:00";
    $finish = "{$to}T00:00";
    $counter = $start;
    while ($counter <= $finish) {
        // $data[$counter] = "{$counter}{$delimiter}{$delimiter}{$delimiter}";
        if ($location['tipe'] == 2) {
            // 2 = awlr / PDA
            $data[$counter] = [
                'sampling' => $counter,
                'wlev_min' => '',
                'wlev_max' => '',
                'wlev_avg' => '',
                'sq' => '',
                'batt' => '',
            ];
        } else {
            // 1,4 = arr / PCH, Klimat
            $data[$counter] = [
                'sampling' => $counter,
                'rain' => '',
                'sq' => '',
                'batt' => '',
            ];
        }
        $counter = date($date_format, strtotime("{$counter} +1day"));
    }

    // dikurang 1 hari
    // 00:00 = hari sebelumnya 23:00 - 23:55
    if ($location['tipe'] == 2) {
        // 2 = awlr / PDA
        $from = date('Y-m-d 00:00:00', strtotime($from . ' -1day'));
        $to = date('Y-m-d 23:55:00', strtotime($to . ' -1day'));
    } else {
        // 1,4 = arr / PCH, Klimat
        $from = date('Y-m-d 07:00:00', strtotime($from . ' -1day'));
        $to = date('Y-m-d 06:55:00', strtotime($to));
    }

    // insert periodik to data
    $periodik = $db->query("SELECT * FROM periodik
        WHERE
            location_id={$location['id']} AND
            sampling BETWEEN '{$from}' AND '{$to}'
        ORDER BY sampling")->fetchAll();
    if (count($periodik) > 0) {
        if ($location['tipe'] == 2) {
            $counter = date('Y-m-d\T00:00', strtotime($periodik[0]['sampling'] . ' +1day'));
        } else {
            $time = date('H:i', strtotime($periodik[0]['sampling']));
            // jika > 7 berarti masuk next day
            $counter = date('Y-m-d\T00:00', strtotime($periodik[0]['sampling'] . ($time > '07:00' ? ' +1day' : '')));
        }
    }

    $wlev_sum = 0;
    $wlev_total = 0;
    foreach ($periodik as $p) {
        if ($location['tipe'] == 2) {
            $current = date('Y-m-d\T00:00', strtotime($p['sampling'] . ' +1day'));
        } else {
            $time = date('H:i', strtotime($p['sampling']));
            // jika > 7 berarti masuk next day
            $current = date('Y-m-d\T00:00', strtotime($p['sampling'] . ($time > '07:00' ? ' +1day' : '')));
        }
        if ($current > $counter) {
            $counter = $current;
            $wlev_sum = 0;
            $wlev_total = 0;
        }

        if ($location['tipe'] == 2) {
            // 2 = awlr / PDA
            if (empty($data[$counter]['wlev_min'])) {
                $data[$counter]['wlev_min'] = PHP_INT_MAX;
            }
            if (empty($data[$counter]['wlev_max'])) {
                $data[$counter]['wlev_max'] = 0;
            }

            if ($p['wlev'] < $data[$counter]['wlev_min']) {
                $data[$counter]['wlev_min'] = $p['wlev'];
            }

            if ($p['wlev'] > $data[$counter]['wlev_max']) {
                $data[$counter]['wlev_max'] = $p['wlev'];
            }

            $wlev_sum += $p['wlev'];
            $wlev_total++;
            $data[$counter]['wlev_avg'] = round($wlev_sum / $wlev_total, 2);
        } else {
            // 1,4 = arr / PCH, Klimat
            if (empty($data[$counter]['rain'])) {
                $data[$counter]['rain'] = 0;
            }
            $data[$counter]['rain'] += $p['rain'];
        }
        $data[$counter]['sq'] = $p['sq'];
        $data[$counter]['batt'] = $p['batt'];
    }

    array_walk($data, function (&$value) use ($delimiter) {
        $value = implode($delimiter, $value);
    });

    // dump($data);

    if ($location['tipe'] == 2) {
        // 2 = awlr / PDA
        $header = [
            'sampling',
            'wlevel min(m)',
            'wlevel max(m)',
            'wlevel rerata(m)',
            'sq',
            'batt'
        ];
    } else {
        // 1,4 = arr / PCH, Klimat
        $header = [
            'sampling',
            'rain(mm)',
            'sq',
            'batt'
        ];
    }

    return [$header, $data];
}

<?php

use Slim\Http\Request;
use Slim\Http\Response;

$app->group('/das', function () use ($getDasMiddleware, $adminRoleMiddleware) {

    $this->get('', function (Request $request, Response $response) {
        $user = $this->user;

        $tenants = null;
        $locations = null;
        if ($user['tenant_id'] > 0) {
            $das = $this->db->query("SELECT
                    das.id,
                    das.nama,
                    ST_AsGeoJSON(das.alur) AS alur
                FROM das
                WHERE tenant_id={$user['tenant_id']}
                ORDER BY nama")->fetchAll();
            $locations = $this->db->query("SELECT * FROM location WHERE tenant_id={$user['tenant_id']}")->fetchAll();
            // get latest value
            foreach ($locations as &$l) {
                $l['rain'] = '-';
                $l['distance'] = '-';
                $l['sampling'] = '-';

                $logger_ids = $this->db->query("SELECT sn FROM logger WHERE location_id={$l['id']}")->fetchAll(PDO::FETCH_COLUMN);
                if ($logger_ids && count($logger_ids) > 0) {
                    array_walk($logger_ids, function (&$val) {
                        $val = "content->>'device' LIKE '%{$val}%'";
                    });
                    $logger_ids = join(' OR ', $logger_ids);
                    $raw = $this->db->query("SELECT * FROM raw WHERE {$logger_ids} ORDER BY content->>'sampling' DESC LIMIT 1")->fetch();
                    if ($raw) {
                        $content = json_decode($raw['content'], true);
                        $l['rain'] = isset($content['tick']) ? $content['tick'] : '-';
                        $l['distance'] = isset($content['distance']) ? $content['distance'] : '-';
                        $l['sampling'] = isset($content['sampling']) ? date('Y-m-d H:i', $content['sampling']) : '-';
                    }
                }
            }
            $template = 'das/index_tenant.html';
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
            $template = 'das/index.html';
        }

        // dump(json_decode($das[0]['alur'], JSON_OBJECT_AS_ARRAY));

        return $this->view->render($response, $template, [
            'das' => $das,
            'tenants' => $tenants,
            'locations' => $locations,
        ]);
    });

    // return distance dalam meter
    function getDistance($latitudeFrom, $longitudeFrom, $latitudeTo, $longitudeTo, $earthRadius = 6371000)
    {
        // convert from degrees to radians
        $latFrom = deg2rad($latitudeFrom);
        $lonFrom = deg2rad($longitudeFrom);
        $latTo = deg2rad($latitudeTo);
        $lonTo = deg2rad($longitudeTo);

        $latDelta = $latTo - $latFrom;
        $lonDelta = $lonTo - $lonFrom;

        $angle = 2 * asin(sqrt(pow(sin($latDelta / 2), 2) +
            cos($latFrom) * cos($latTo) * pow(sin($lonDelta / 2), 2)));
        return $angle * $earthRadius;
    }
    $this->get('/monitoring', function (Request $request, Response $response) {
        $user = $this->user;

        if ($user['tenant_id'] > 0) {
            $locations = $this->db->query("SELECT * FROM location WHERE tenant_id={$user['tenant_id']} ORDER BY elevasi DESC")->fetchAll();
            $geojson = [
                'type' => 'FeatureCollection',
                'features' => []
            ];
            // get latest value
            $tma_max = 0;
            $tma_min = 999999999;
            $prev_ll = [];
            foreach ($locations as &$l) {
                $l['rain'] = '-';
                $l['distance'] = '-';
                $l['sampling'] = '-';

                $logger_ids = $this->db->query("SELECT sn FROM logger WHERE location_id={$l['id']}")->fetchAll(PDO::FETCH_COLUMN);
                if ($logger_ids && count($logger_ids) > 0) {
                    array_walk($logger_ids, function (&$val) {
                        $val = "content->>'device' LIKE '%{$val}%'";
                    });
                    $logger_ids = join(' OR ', $logger_ids);
                    $raw = $this->db->query("SELECT * FROM raw WHERE {$logger_ids} ORDER BY content->>'sampling' DESC LIMIT 1")->fetch();
                    if ($raw) {
                        $content = json_decode($raw['content'], true);
                        $l['rain'] = isset($content['tick']) ? $content['tick'] : '-';
                        $l['distance'] = isset($content['distance']) ? $content['distance'] : '-';
                        $l['sampling'] = isset($content['sampling']) ? date('Y-m-d H:i', $content['sampling']) : '-';
                    }
                }

                $l['tma'] = intval($l['distance']);
                if ($l['tma'] > $tma_max) { $tma_max = $l['tma']; }
                if ($l['tma'] < $tma_min) { $tma_min = $l['tma']; }

                $ll = explode(',', $l['ll']);
                if (empty($prev_ll)) {
                    $l['d'] = 0;
                } else {
                    $l['d'] = getDistance($prev_ll[0], $prev_ll[1], $ll[0], $ll[1]);
                }
                $prev_ll = $ll;
            }

            return $this->view->render($response, 'das/monitoring.html', [
                'locations' => $locations,
                'geojson' => $geojson,
            ]);
        }

        return $this->response->withRedirect('/das');
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

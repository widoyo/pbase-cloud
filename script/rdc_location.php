<?php

include_once 'db.php';

// ignore_user_abort(true);
// set_time_limit(0);

// ob_start();

$locations = $db->query("SELECT
        location.*,
        logger.tipe AS logger_tipe,
        logger.sn AS logger_sn,
        logger.tenant_id,
        tenant.nama AS tenant_nama
    FROM location
        LEFT JOIN logger ON (location.id = logger.location_id)
        LEFT JOIN tenant ON (logger.tenant_id = tenant.id)
    WHERE
        logger.tipe IS NOT NULL")->fetchAll();
            
$today = date('Y-m-d');
$location_to_cache_periodics = [];
foreach ($locations as $location) {
    $rdc_data = [
        'id' => $location['id'],
        'nama' => $location['nama'],
        'lonlat' => $location['ll'],
        'tenant_id' => $location['tenant_id'],
        'tenant_nama' => $location['tenant_nama'],
        'elevasi' => ''
    ];

    $logger_tipe = strtolower($location['logger_tipe']);
    if ($logger_tipe == 'awlr') {
        $rdc_data['tipe'] = '2';
    } else {
        $rdc_data['tipe'] = '1';
    }
    
    $periodik = $db->query("SELECT * FROM periodik
        WHERE location_id = '{$location['id']}'
            AND mdpl IS NOT NULL
        ORDER BY sampling DESC
        LIMIT 1")->fetch();
    if ($periodik) {
        $rdc_data['elevasi'] = $periodik['mdpl'];
    }

    $pclient->hmset("location:{$location['id']}", $rdc_data);

    // cek kapan terakhir periodik
    if ($pclient->hget("location:{$location['id']}", "last_cache") < $today) {
        $location_to_cache_periodics[] = $location['id'];
    }
}

// // direturn, lalu biar nggak timeout, lalu lanjut cache periodik
// echo "OK"; // send the response
// header('Connection: close');
// header('Content-Length: '.ob_get_length());
// ob_end_flush();
// ob_flush();
// flush();

// cache periodik
foreach ($location_to_cache_periodics as $location_id) {
    $from = $pclient->hget("location:{$location_id}", "last_cache");
    if (empty($from)) {
        // get oldest
        $old_periodik = $db->query("SELECT * FROM periodik
            WHERE location_id = '{$location_id}'
            ORDER BY sampling
            LIMIT 1")->fetch();
        if (!$old_periodik) {
            // periokdik tidak ditemukan
            continue;
        }

        $from = $old_periodik['sampling'];
    }
    $to = $today;

    while ($from != $to) {
        $min = 0;
        $max = 0;
        $rdc_data = [
            'tanggal' => date('d', strtotime($from))
        ];

        $res = $db->query("SELECT * FROM periodik WHERE location_id={$location_id} AND sampling::date='{$from}' ORDER BY rain, wlev")->fetchAll();
        if ($res && count($res) > 0) {
            if ($location['tipe'] == 2) {
                $rdc_data['wlev_min'] = doubleval($res[0]['wlev']);
                $rdc_data['wlev_max'] = doubleval($res[count($res)-1]['wlev']);
            } else {
                $rdc_data['rain_min'] = doubleval($res[0]['rain']);
                $rdc_data['rain_max'] = doubleval($res[count($res)-1]['rain']);
            }
        } else {
            if ($location['tipe'] == 2) {
                $rdc_data['wlev_min'] = 0;
                $rdc_data['wlev_max'] = 0;
            } else {
                $rdc_data['rain_min'] = 0;
                $rdc_data['rain_max'] = 0;
            }
        }

        $bulan = date('Y-m', strtotime($from));
        $rdc_data = json_encode($rdc_data);
        $pclient->rpush("location:{$location_id}:periodik:harian:{$bulan}", $rdc_data);
        // var_dump($rdc_data);
        // die();

        $from = date("Y-m-d", strtotime("{$from} +1day"));
    }

    $pclient->hset("location:{$location_id}", "last_cache", $today);
}
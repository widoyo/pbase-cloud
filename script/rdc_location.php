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
    $location_id = $location['id'];

    $rdc_data = [
        'id' => $location['id'],
        'nama' => $location['nama'],
        'll' => $location['ll'],
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

    

    // get all logger
    $loggers = $db->query("SELECT * FROM logger
        WHERE location_id = '{$location_id}'");
    $logger_sn = [];
    foreach ($loggers as $logger) {
        $logger_sn[] = "'{$logger['sn']}'";

        $logger_data = $db->query("SELECT COUNT(*) FROM periodik
            WHERE logger_sn='{$logger['sn']}'
            GROUP BY logger_sn")->fetch();

        // cache logger count
        $rdc_logger_data = [
            'sn' => $logger['sn'],
            'count' => $logger_data ? $logger_data['count'] : 0
        ];
        $pclient->hmset("location:{$location['id']}:logger:{$logger['sn']}", $rdc_logger_data);
    }
    $logger_sn = implode(",", $logger_sn);

    $periodik_mdpl = $db->query("SELECT * FROM periodik
        WHERE (location_id={$location_id} OR logger_sn IN ({$logger_sn}))
            AND mdpl IS NOT NULL
        ORDER BY sampling DESC
        LIMIT 1")->fetch();
    if ($periodik_mdpl) {
        $rdc_data['elevasi'] = $periodik_mdpl['mdpl'];
    }

    $first_periodik = $db->query("SELECT * FROM periodik
        WHERE (location_id={$location_id} OR logger_sn IN ({$logger_sn}))
        ORDER BY sampling ASC
        LIMIT 1")->fetch();
    if ($first_periodik) {
        $rdc_data['first_sampling'] = $first_periodik['sampling'];
    }

    $latest_periodik = $db->query("SELECT * FROM periodik
        WHERE (location_id={$location_id} OR logger_sn IN ({$logger_sn}))
        ORDER BY sampling DESC
        LIMIT 1")->fetch();
    if ($latest_periodik) {
        $rdc_data['latest_sampling'] = $latest_periodik['sampling'];
    }

    $total_data_diterima = $db->query("SELECT COUNT(*) FROM periodik
        WHERE (location_id={$location_id} OR logger_sn IN ({$logger_sn}))")->fetch();
    if ($total_data_diterima) {
        $total_data_diterima = $total_data_diterima['count'];

        $total_data_seharusnya = 0;
        $persen_data_diterima = 0;
        if ($first_periodik && $latest_periodik) {
            $first = strtotime($first_periodik['sampling']);
            $last = strtotime($latest_periodik['sampling']);
            $total_data_seharusnya = ($last - $first) / (60 * 5);
            if ($total_data_seharusnya > 0) {
                $persen_data_diterima = $total_data_diterima * 100 / $total_data_seharusnya;
            }
        }

        $rdc_data['total_data_diterima'] = $total_data_diterima;
        $rdc_data['total_data_seharusnya'] = $total_data_seharusnya;
        $rdc_data['persen_data_diterima'] = $persen_data_diterima;
    }

    $pclient->hmset("location:{$location['id']}", $rdc_data);

    // cek kapan terakhir periodik
    if ($pclient->hget("location:{$location['id']}", "last_cache") <= $today) {
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
    $location = $pclient->hgetall("location:{$location_id}");

    $loggers = $db->query("SELECT * FROM logger WHERE location_id={$location_id}");
    $logger_sn = [];
    foreach ($loggers as $logger) {
        $logger_sn[] = "'{$logger['sn']}'";
    }
    $logger_sn = implode(",", $logger_sn);


    $from = $pclient->hget("location:{$location_id}", "last_cache");
    if (empty($from)) {
        // get oldest
        $old_periodik = $db->query("SELECT * FROM periodik
            WHERE (location_id={$location_id} OR logger_sn IN ({$logger_sn}))
            ORDER BY sampling
            LIMIT 1")->fetch();
        if (!$old_periodik) {
            // periokdik tidak ditemukan
            continue;
        }

        $from = date('Y-m-d', strtotime($old_periodik['sampling']));
    }
    $to = $today;

    while ($from <= $to) {
        echo "{$location_id}:{$from}:{$to}\n";
        $min = 0;
        $max = 0;
        $rdc_data = [
            'tanggal' => date('d', strtotime($from))
        ];

        $res = $db->query("SELECT * FROM periodik WHERE (location_id={$location_id} OR logger_sn IN ({$logger_sn})) AND sampling::date='{$from}' ORDER BY rain, wlev")->fetchAll();
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

        // $bulan = date('Y-m', strtotime($from));
        // $rdc_data = json_encode($rdc_data);
        $pclient->hmset("location:{$location_id}:periodik:harian:{$from}", $rdc_data);
        // var_dump($rdc_data);
        // die();

        $from = date("Y-m-d", strtotime("{$from} +1day"));
    }

    $pclient->hset("location:{$location_id}", "last_cache", $today);
}

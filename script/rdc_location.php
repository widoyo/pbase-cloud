<?php

include_once 'db.php';

// ignore_user_abort(true);
// set_time_limit(0);

// ob_start();

function get_total_data_logger($db, $location_id, $logger_sn, $from = '', $to = '')
{
    $rdc_data = [];

    if (empty($from) || empty($to)) {
        $total_data_diterima = $db->query("SELECT COUNT(*) FROM periodik
            WHERE (location_id={$location_id} OR logger_sn IN ({$logger_sn}))")->fetch();
    } else {
        $total_data_diterima = $db->query("SELECT COUNT(*) FROM periodik
            WHERE (location_id={$location_id} OR logger_sn IN ({$logger_sn}))
                AND sampling BETWEEN '{$from}' AND '{$to}'")->fetch();
    }

    if ($total_data_diterima) {
        $total_data_diterima = $total_data_diterima['count'];

        $total_data_seharusnya = 0;
        $persen_data_diterima = 0;
        if ($from && $to) {
            $first = strtotime($from);
            $last = strtotime($to);
            $total_data_seharusnya = ($last - $first) / (60 * 5);
            if ($total_data_seharusnya > 0) {
                $persen_data_diterima = $total_data_diterima * 100 / $total_data_seharusnya;
            }
        }

        $rdc_data['diterima'] = $total_data_diterima;
        $rdc_data['seharusnya'] = floor($total_data_seharusnya);
        $rdc_data['persen'] = number_format($persen_data_diterima, 1);
    }

    return $rdc_data;
}

$locations = $db->query("SELECT
        location.*,
        logger.tipe AS logger_tipe,
        tenant.id AS tenant_id,
        tenant.nama AS tenant_nama
    FROM location
        LEFT JOIN logger ON (location.id = logger.location_id)
        LEFT JOIN tenant ON (location.tenant_id = tenant.id)
    ")->fetchAll();

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
        'elevasi' => '',
        'rain' => null,
        'wlev' => null,
        'tipe' => $location['tipe'],
        'wilayah' => $location['wilayah'],
    ];

    if (empty($location['tipe'])) {
        $logger_tipe = strtolower($location['logger_tipe']);
        switch ($logger_tipe) {
            case 'arr':
                $rdc_data['tipe'] = '1';
                break;

            case 'awlr':
                $rdc_data['tipe'] = '2';
                break;

            case 'klimat':
                $rdc_data['tipe'] = '4';
                break;

            default:
                $rdc_data['tipe'] = '0';
                break;
        }
    }



    // get all logger
    $loggers = $db->query("SELECT * FROM logger
        WHERE location_id = '{$location_id}'")->fetchAll();
    $logger_sn = [];
    foreach ($loggers as $logger) {
        $logger_sn[] = "'{$logger['sn']}'";

        // $logger_data = $db->query("SELECT COUNT(*) FROM periodik
        //     WHERE logger_sn='{$logger['sn']}'
        //     GROUP BY logger_sn")->fetch();

        // $latest_periodik = $db->query("SELECT * FROM periodik
        //     WHERE logger_sn='{$logger['sn']}'
        //     ORDER BY sampling DESC
        //     LIMIT 1")->fetch();

        // // cache logger count
        // $rdc_logger_data = [
        //     'sn' => $logger['sn'],
        //     'count' => $logger_data ? $logger_data['count'] : 0,
        //     'latest_sampling' => $latest_periodik ? $latest_periodik['sampling'] : '',
        // ];
        // $pclient->hmset("location:{$location['id']}:logger:{$logger['sn']}", $rdc_logger_data);
        $pclient->sadd("location:{$location['id']}:logger", "logger:{$logger['sn']}");
    }
    $logger_sn = implode(",", $logger_sn);

    if (!empty($logger_sn)) {
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
            $rdc_data['rain'] = $latest_periodik['rain'];
            $rdc_data['wlev'] = $latest_periodik['wlev'];
        }

        // total all time
        $total_all = get_total_data_logger(
            $db,
            $location_id,
            $logger_sn,
            $first_periodik ? $first_periodik['sampling'] : '',
            $latest_periodik ? $latest_periodik['sampling'] : ''
        );
        if (count($total_all) > 0) {
            $rdc_data['total_data_diterima']    = $total_all['diterima'];
            $rdc_data['total_data_seharusnya']  = $total_all['seharusnya'];
            $rdc_data['persen_data_diterima']   = $total_all['persen'];
        }

        // total today
        $total_today = get_total_data_logger(
            $db,
            $location_id,
            $logger_sn,
            date('Y-m-d 00:00:00'),
            date('Y-m-d H:i:s')
        );
        if (count($total_today) > 0) {
            $rdc_data['total_data_diterima_today']    = $total_today['diterima'];
            $rdc_data['total_data_seharusnya_today']  = $total_today['seharusnya'];
            $rdc_data['persen_data_diterima_today']   = $total_today['persen'];
        }

        // total month
        $total_month = get_total_data_logger(
            $db,
            $location_id,
            $logger_sn,
            date('Y-m-d 00:00:00', strtotime('first day of this month')),
            date('Y-m-d H:i:s')
        );
        if (count($total_month) > 0) {
            $rdc_data['total_data_diterima_month']    = $total_month['diterima'];
            $rdc_data['total_data_seharusnya_month']  = $total_month['seharusnya'];
            $rdc_data['persen_data_diterima_month']   = $total_month['persen'];
        }

        // total year
        $total_year = get_total_data_logger(
            $db,
            $location_id,
            $logger_sn,
            date('Y-01-01 00:00:00'),
            date('Y-m-d H:i:s')
        );
        if (count($total_year) > 0) {
            $rdc_data['total_data_diterima_year']    = $total_year['diterima'];
            $rdc_data['total_data_seharusnya_year']  = $total_year['seharusnya'];
            $rdc_data['persen_data_diterima_year']   = $total_year['persen'];
        }
    }


    // add to hash
    $pclient->hmset("location:{$location['id']}", $rdc_data);
    // add to set
    $pclient->sadd("location", "location:{$location['id']}");

    // add to hash
    // $pclient->hmset("tenant:{$location['tenant_id']}:location:{$location['id']}", $rdc_data);
    // add to set
    $pclient->sadd("tenant:{$location['tenant_id']}:location", "location:{$location['id']}");

    // cek kapan terakhir periodik
    if (!empty($logger_sn) && $pclient->hget("location:{$location['id']}", "last_cache") <= $today) {
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

    $loggers = $db->query("SELECT * FROM logger WHERE location_id={$location_id}")->fetchAll();
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

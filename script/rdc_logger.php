<?php

include_once 'db.php';

$timezone_default = "Asia/Jakarta";

function get_total_data_logger($db, $logger_sn, $from = '', $to = '')
{
    $rdc_data = [];

    if (empty($from) || empty($to)) {
        $total_data_diterima = $db->query("SELECT COUNT(*) FROM periodik
            WHERE (logger_sn = '{$logger_sn}')")->fetch();
    } else {
        $total_data_diterima = $db->query("SELECT COUNT(*) FROM periodik
            WHERE (logger_sn = '{$logger_sn}')
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

$loggers = $db->query("SELECT
                    logger.id AS logger_id,
                    logger.sn,
                    logger.tipe,
                    location.nama AS location_nama,
                    tenant.nama AS tenant_nama,
                    COALESCE(tenant.timezone, '{$timezone_default}') AS timezone,
                    periodik.*
                FROM logger
                    LEFT JOIN location ON logger.location_id = location.id
                    LEFT JOIN tenant ON logger.tenant_id = tenant.id
                    LEFT JOIN periodik ON periodik.id = (
                        SELECT id from periodik
                        WHERE periodik.logger_sn = logger.sn
                            AND periodik.sampling >= '2018-01-01'
                        ORDER BY periodik.sampling DESC
                        LIMIT 1
                    )
                ORDER BY 
                    periodik.mdpl DESC,
                    periodik.sampling DESC,
                    location.nama,
                    logger.sn")->fetchAll();

foreach ($loggers as $logger) {
    // echo "{$logger['sn']}\n";
    $logger_data = $db->query("SELECT COUNT(*) FROM periodik
        WHERE logger_sn='{$logger['sn']}'
            AND sampling >= '2018-01-01'
        GROUP BY logger_sn")->fetch();

    $rdc_data = [
        'id' => $logger['logger_id'],
        'sn' => $logger['sn'],
        'tipe' => $logger['tipe'],
        'location_nama' => $logger['location_nama'],
        'tenant_nama' => $logger['tenant_nama'],
        'timezone' => $logger['timezone'],
        'latest_sampling' => $logger['sampling'],
        'up_s' => $logger['up_s'],
        'ts_a' => $logger['ts_a'],
        'received' => $logger['received'],
        'mdpl' => $logger['mdpl'],
        'apre' => $logger['apre'],
        'sq' => $logger['sq'],
        'temp' => $logger['temp'],
        'humi' => $logger['humi'],
        'batt' => $logger['batt'],
        'rain' => $logger['rain'],
        'wlev' => $logger['wlev'],
        'location_id' => $logger['location_id'],
        'tenant_id' => $logger['tenant_id'],
        'count' => $logger_data ? $logger_data['count'] : 0,
    ];

    $first_periodik = $db->query("SELECT * FROM periodik
        WHERE (logger_sn = '{$logger['sn']}')
            AND sampling >= '2018-01-01'
        ORDER BY sampling ASC
        LIMIT 1")->fetch();
    if ($first_periodik) {
        $rdc_data['first_sampling'] = $first_periodik['sampling'];
    }

    // total all time
    $total_all = get_total_data_logger(
        $db,
        $logger['sn'],
        $first_periodik ? $first_periodik['sampling'] : '',
        $logger['sampling'] ? $logger['sampling'] : ''
    );
    if (count($total_all) > 0) {
        $rdc_data['total_data_diterima']    = $total_all['diterima'];
        $rdc_data['total_data_seharusnya']  = $total_all['seharusnya'];
        $rdc_data['persen_data_diterima']   = $total_all['persen'];
    }

    // total today
    $total_today = get_total_data_logger(
        $db,
        $logger['sn'],
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
        $logger['sn'],
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
        $logger['sn'],
        date('Y-01-01 00:00:00'),
        date('Y-m-d H:i:s')
    );
    if (count($total_year) > 0) {
        $rdc_data['total_data_diterima_year']    = $total_year['diterima'];
        $rdc_data['total_data_seharusnya_year']  = $total_year['seharusnya'];
        $rdc_data['persen_data_diterima_year']   = $total_year['persen'];
    }

    // add to hash
    $pclient->hmset("logger:{$logger['sn']}", $rdc_data);
    // add to set
    $pclient->sadd("logger", "logger:{$logger['sn']}");
    if ($logger['tenant_id']) {
        $pclient->sadd("tenant:{$logger['tenant_id']}:logger", "logger:{$logger['sn']}");
    }
}

<?php

require __DIR__ . '/../vendor/autoload.php';
include 'db.php';

$server = "mqtt.bbws-bsolo.net";     // change if necessary
$port = 14983;                     // change if necessary
$client_id = "pbase-mqtt-redis"; // make sure this is unique for connecting to sever - you could use uniqid()
$mqtt = new Bluerhinos\phpMQTT($server, $port, $client_id);
if (!$mqtt->connect()) {
    echo "Failed connect mqtt\n";
    exit(1);
}
$topics['sensors'] = array("qos" => 0, "function" => "procmsg");
$mqtt->subscribe($topics, 0);
while ($mqtt->proc()) {
}
$mqtt->close();

// dipanggil oleh mqtt 
function procmsg($topic, $msg)
{
    echo "Msg Recieved: " . date("r") . "\n";
    echo "Topic: {$topic}\n\n";
    echo "\t$msg\n\n";

    callme($msg);
}

// dipanggil procmsg
function callme($msg)
{
    global $db;
    global $pclient;

    $raw = json_decode($msg);
    if ($raw && isset($raw->device)) {
        $timezone_default = "Asia/Jakarta";

        $sn = explode("/", $raw->device)[1];
        $stmt = $db->prepare("SELECT
                logger.*,
                location.nama AS location_nama,
                tenant.nama AS tenant_nama,
                COALESCE(tenant.timezone, '{$timezone_default}') AS timezone
            FROM logger
                LEFT JOIN location ON logger.location_id = location.id
                LEFT JOIN tenant ON logger.tenant_id = tenant.id
            WHERE
                logger.sn=:sn");
        $stmt->execute([
            ':sn' => $sn
        ]);
        $logger = $stmt->fetch();
        if (!$logger) {
            return;
        }

        $logger_key = "logger:{$logger['sn']}";
        $location_key = "";
        $pclient->sadd("logger", $logger_key);
        if ($logger['location_id']) {
            $location_key = "location:{$logger['location_id']}";
            $pclient->sadd("location:{$logger['location_id']}:logger", $logger_key);
        }
        if ($logger['tenant_id']) {
            $pclient->sadd("tenant:{$logger['tenant_id']}:logger", $logger_key);
        }

        $rdc_data = $pclient->hgetall($logger_key);
        $rdc_data_location = [];
        if (empty($rdc_data)) {
            $rdc_data = [
                'latest_sampling' => '1970-01-01 00:00:00',
                'count' => 0,
                'total_data_diterima' => 0,
                'total_data_seharusnya' => 0,
                'persen_data_diterima' => 0,
                'total_data_diterima_today' => 0,
                'total_data_seharusnya_today' => 0,
                'persen_data_diterima_today' => 0,
                'total_data_diterima_month' => 0,
                'total_data_seharusnya_month' => 0,
                'persen_data_diterima_month' => 0,
            ];

            $first_periodik = $db->query("SELECT * FROM periodik
                WHERE (logger_sn = '{$logger['sn']}')
                    AND sampling >= '2018-01-01'
                ORDER BY sampling ASC
                LIMIT 1")->fetch();
            if ($first_periodik) {
                $rdc_data['first_sampling'] = $first_periodik['sampling'];
            } else {
                $rdc_data['first_sampling'] = date('Y-m-d H:i:s', $raw->sampling);
            }
        }

        // simpan prev_sampling utk reset counter
        $prev_sampling = new DateTime($rdc_data['latest_sampling']);

        // masukkan data baru logger
        foreach ($logger as $k => $v) {
            $rdc_data[$k] = $v;
        }

        // masukkan data periodik dari raw
        $rdc_data = array_merge(
            $rdc_data,
            raw2periodic($raw, $logger)
        );
        $rdc_data_location['rain'] = $rdc_data['rain'];
        $rdc_data_location['wlev'] = $rdc_data['wlev'];
        $rdc_data_location['elevasi'] = $rdc_data['mdpl'];
        $rdc_data_location['first_sampling'] = $rdc_data['first_sampling'];
        $rdc_data_location['latest_sampling'] = $rdc_data['latest_sampling'];

        // simpan dulu sebelum hitung total data
        $pclient->hmset($logger_key, $rdc_data);
        if ($location_key) {
            $pclient->hmset($location_key, $rdc_data_location);
        }
        echo "SAVED\n";

        $curr_sampling = new DateTime($rdc_data['latest_sampling']);
        $now = date('Y-m-d H:i:s');

        // total
        $count = $pclient->hincrby($logger_key, 'count', 1);
        $total_data_diterima = $pclient->hincrby($logger_key, 'total_data_diterima', 1);

        $total_data_seharusnya = countTotalRecord(
            $rdc_data['first_sampling'],
            $now
        );
        $pclient->hset($logger_key, 'total_data_seharusnya', $total_data_seharusnya);

        $persen_data_diterima = $total_data_seharusnya > 0 ?
            $total_data_diterima * 100 / $total_data_seharusnya :
            100;
        $pclient->hset($logger_key, 'persen_data_diterima', number_format($persen_data_diterima, 1));

        // today
        if ($curr_sampling->format('d') != $prev_sampling->format('d')) {
            $pclient->hset($logger_key, 'total_data_diterima_today', 0);
        }
        $total_data_diterima_today = $pclient->hincrby($logger_key, 'total_data_diterima_today', 1);

        $total_data_seharusnya_today = countTotalRecord(
            $curr_sampling->format('Y-m-d 00:00:00'),
            $now
        );
        $pclient->hset($logger_key, 'total_data_seharusnya_today', $total_data_seharusnya_today);

        $persen_data_diterima_today = $total_data_seharusnya_today > 0 ?
            $total_data_diterima_today * 100 / $total_data_seharusnya_today :
            100;
        $pclient->hset($logger_key, 'persen_data_diterima_today', number_format($persen_data_diterima_today, 1));

        // month
        if ($curr_sampling->format('m') != $prev_sampling->format('m')) {
            $pclient->hset($logger_key, 'total_data_diterima_month', 0);
        }
        $total_data_diterima_month = $pclient->hincrby($logger_key, 'total_data_diterima_month', 1);

        $total_data_seharusnya_month = countTotalRecord(
            $curr_sampling->format('Y-m-01 00:00:00'),
            $now
        );
        $pclient->hset($logger_key, 'total_data_seharusnya_month', $total_data_seharusnya_month);
        
        $persen_data_diterima_month = $total_data_seharusnya_month > 0 ?
            $total_data_diterima_month * 100 / $total_data_seharusnya_month :
            100;
        $pclient->hset($logger_key, 'persen_data_diterima_month', number_format($persen_data_diterima_month, 1));

        // year
        if ($curr_sampling->format('Y') != $prev_sampling->format('Y')) {
            $pclient->hset($logger_key, 'total_data_diterima_year', 0);
        }
        $total_data_diterima_year = $pclient->hincrby($logger_key, 'total_data_diterima_year', 1);

        $total_data_seharusnya_year = countTotalRecord(
            $curr_sampling->format('Y-01-01 00:00:00'),
            $now
        );
        $pclient->hset($logger_key, 'total_data_seharusnya_year', $total_data_seharusnya_year);
        
        $persen_data_diterima_year = $total_data_seharusnya_year > 0 ?
            $total_data_diterima_year * 100 / $total_data_seharusnya_year :
            100;
        $pclient->hset($logger_key, 'persen_data_diterima_year', number_format($persen_data_diterima_year, 1));

        if ($location_key) {
            $pclient->hmset($location_key, [
                'total_data_diterima' => $total_data_diterima,
                'total_data_seharusnya' => $total_data_seharusnya,
                'persen_data_diterima' => number_format($persen_data_diterima, 1),
                'total_data_diterima_today' => $total_data_diterima_today,
                'total_data_seharusnya_today' => $total_data_seharusnya_today,
                'persen_data_diterima_today' => number_format($persen_data_diterima_today, 1),
                'total_data_diterima_month' => $total_data_diterima_month,
                'total_data_seharusnya_month' => $total_data_seharusnya_month,
                'persen_data_diterima_month' => number_format($persen_data_diterima_month, 1),
                'total_data_diterima_year' => $total_data_diterima_year,
                'total_data_seharusnya_year' => $total_data_seharusnya_year,
                'persen_data_diterima_year' => number_format($persen_data_diterima_year, 1),
            ]);
        }
    }
}

function countTotalRecord($from, $to)
{
    $diff = (new DateTime($to))->diff(new DateTime($from));
    $minutes = $diff->days * 24 * 60;
    $minutes += $diff->h * 60;
    $minutes += $diff->i;

    return floor($minutes / 5);
}

function raw2periodic($raw, $logger)
{
    $periodic = [];

    if (isset($raw->tick)) {
        $periodic['rain'] = ($logger['tipp_fac'] ?: 0.2) * $raw->tick;
    }

    if (isset($raw->distance)) {
        $periodic['wlev'] = ($logger['ting_son'] ?: 100) - $raw->distance * 0.1;
    }

    $time_to = [
        'sampling' => 'latest_sampling',
        'up_since' => 'up_s',
        'time_set_at' => 'ts_a',
    ];

    $direct_to = [
        'altitude' => 'mdpl',
        'signal_quality' => 'sq',
        'pressure' => 'apre',
    ];

    $apply_to = [
        'humidity' => 'humi',
        'temperature' => 'temp',
        'battery' => 'batt',
    ];

    foreach ($time_to as $k => $v) {
        if (!isset($raw->$k)) {
            continue;
        }

        $periodic[$v] = date('Y-m-d H:i:s', $raw->$k);
    }
    $periodic['received'] = date('Y-m-d H:i:s');

    foreach ($direct_to as $k => $v) {
        if (!isset($raw->$k)) {
            continue;
        }

        $periodic[$v] = $raw->$k;
    }

    foreach ($apply_to as $k => $v) {
        if (!isset($raw->$k)) {
            continue;
        }

        $corr = !empty($logger["{$v}_cor"]) ? $logger["{$v}_cor"] : 0;
        $periodic[$v] = $raw->$k + $corr;
    }

    return $periodic;
}

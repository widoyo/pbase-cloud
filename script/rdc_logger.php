<?php

include_once 'db.php';

$timezone_default = "Asia/Jakarta";

$loggers = $db->query("SELECT
                    logger.id AS logger_id,
                    logger.sn,
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
                        ORDER BY periodik.sampling DESC
                        LIMIT 1
                    )
                ORDER BY 
                    periodik.mdpl DESC,
                    periodik.sampling DESC,
                    location.nama,
                    logger.sn")->fetchAll();

foreach ($loggers as $logger) {
    echo "{$logger['sn']}\n";
    $logger_data = $db->query("SELECT COUNT(*) FROM periodik
        WHERE logger_sn='{$logger['sn']}'
        GROUP BY logger_sn")->fetch();

    $rdc_data = [
        'id' => $logger['logger_id'],
        'sn' => $logger['sn'],
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

    $pclient->hmset("logger:{$logger['sn']}", $rdc_data);
}

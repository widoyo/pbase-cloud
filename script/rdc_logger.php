<?php

include_once 'db.php';

$loggers = $db->query("SELECT * FROM logger")->fetchAll();
foreach ($loggers as $logger) {
    echo "{$logger['sn']}\n";
    $rdc_data = [
        'id' => $logger['id'],
        'sn' => $logger['sn'],
    ];

    $periodik_mdpl = $db->query("SELECT * FROM periodik
        WHERE (logger_sn='{$logger['sn']}')
            AND mdpl IS NOT NULL
        ORDER BY sampling DESC
        LIMIT 1")->fetch();
    if ($periodik_mdpl) {
        $rdc_data['elevasi'] = $periodik_mdpl['mdpl'];
    }

    $first_periodik = $db->query("SELECT * FROM periodik
        WHERE (logger_sn='{$logger['sn']}')
        ORDER BY sampling ASC
        LIMIT 1")->fetch();
    if ($first_periodik) {
        $rdc_data['first_sampling'] = $first_periodik['sampling'];
    }

    $latest_periodik = $db->query("SELECT * FROM periodik
        WHERE (logger_sn='{$logger['sn']}')
        ORDER BY sampling DESC
        LIMIT 1")->fetch();
    if ($latest_periodik) {
        $rdc_data['latest_sampling'] = $latest_periodik['sampling'];
    }

    $total_data_diterima = $db->query("SELECT COUNT(*) FROM periodik
        WHERE (logger_sn='{$logger['sn']}')")->fetch();
    if ($total_data_diterima) {
        $rdc_data['total_data_diterima'] = $total_data_diterima['count'];
    }
    
    $pclient->hmset("logger:{$logger['sn']}", $rdc_data);
}
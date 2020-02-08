<?php

include_once 'db.php';

$locations = $db->query("SELECT
            location.id,
            logger.tipe AS logger_tipe
        FROM location
            LEFT JOIN logger ON (location.id = logger.location_id)
        WHERE
            logger.tipe IS NOT NULL")->fetchAll();
$count_awlr = 0;
$count_arr  = 0;
foreach ($locations as $location) {
    $logger_tipe = strtolower($location['logger_tipe']);
    if ($logger_tipe == 'awlr') {
        $location_tipe = '2';
        $count_awlr++;
    } else {
        $location_tipe = '1';
        $count_arr++;
    }
    $db->query("UPDATE location SET tipe='{$location_tipe}' WHERE id={$location['id']}");
}

$count_all = $count_arr + $count_awlr;
echo "TOTAL ARR  : {$count_arr}\n";
echo "TOTAL AWLR : {$count_awlr}\n";
echo "----------------------\n";
echo "             {$count_all}\n";
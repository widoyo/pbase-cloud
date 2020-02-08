<?php

include_once 'db.php';

$locations = $db->query("SELECT
            location.id,
            logger.sn AS logger_sn
        FROM location
            LEFT JOIN logger ON (location.id = logger.location_id)
        WHERE
            logger.tipe IS NOT NULL")->fetchAll();
$count_found = 0;
$count_nf  = 0;
foreach ($locations as $location) {
    $periodik = $db->query("SELECT * FROM periodik
        WHERE
            periodik.logger_sn = '{$location['logger_sn']}'
            AND periodik.mdpl IS NOT NULL
        ORDER BY periodik.sampling
        LIMIT 1")->fetch();
    if ($periodik) {
        $count_found++;
        $db->query("UPDATE location SET elevasi='{$periodik['mdpl']}' WHERE id={$location['id']}");
    } else {
        $count_nf++;
    }
}

$count_all = $count_found + $count_nf;
echo "TOTAL EXIST     : {$count_found}\n";
echo "TOTAL NOT FOUND : {$count_nf}\n";
echo "----------------------\n";
echo "             {$count_all}\n";
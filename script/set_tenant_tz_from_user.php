<?php

include 'db.php';

$user_with_tz = $db->query("SELECT * FROM users WHERE tenant_id is not null AND tz is not null")->fetchAll();
foreach ($user_with_tz as $user) {
    $tz = $user['tz'];
    $tenant_id = $user['tenant_id'];
    $db->query("UPDATE tenant SET timezone='{$tz}' WHERE id='{$tenant_id}'");
}

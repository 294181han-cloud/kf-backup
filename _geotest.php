<?php
require __DIR__ . '/db.php';
require __DIR__ . '/helpers.php';
foreach (['182.118.239.128','14.24.226.213','111.55.114.104','203.188.226.144','103.41.131.215','198.40.51.11'] as $ip) {
    $g = lookup_geo($ip);
    echo str_pad($ip, 18), ' => ', $g, '   显示：', geo_region($g), ' / ', geo_city($g), "\n";
}

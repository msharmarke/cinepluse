<?php
require_once dirname(__DIR__) . '/src/Autoloader.php';
use Cinepulse\VelocityService;

$res = VelocityService::getTopVelocityShowtimes(10);
echo "Fetched count: " . count($res) . "\n\n";
foreach ($res as $i => $s) {
    echo "#" . ($i + 1) . " [{$s['occupancy_pct']}% FULL] {$s['movie_title']} @ {$s['theatre_name']}\n";
    echo "   🪑 {$s['available_seats']} / {$s['total_seats']} left | ⚡ +{$s['fill_rate_seats_per_hour']} seats/hr\n\n";
}

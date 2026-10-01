<?php
require_once __DIR__ . '/../src/Autoloader.php';

use Cinepulse\CineplexAPI;

$api = new CineplexAPI();
$data = $api->fetchShowtimes(7411, '09+25+2026');

echo "MOVIES COUNT: " . count($data[0]['dates'][0]['movies'] ?? []) . "\n";
if (!empty($data[0]['dates'][0]['movies'])) {
    foreach (array_slice($data[0]['dates'][0]['movies'], 0, 5) as $movie) {
        echo " 🎬 " . ($movie['name'] ?? 'N/A') . "\n";
    }
}

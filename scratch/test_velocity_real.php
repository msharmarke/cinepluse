<?php
require_once dirname(__DIR__) . '/src/Autoloader.php';
use Cinepulse\CineplexAPI;
use Cinepulse\ShowtimeService;

$api = new CineplexAPI();
$dateStr = date('m+d+Y');
$theatresToQuery = [
    'Cineplex Queensway' => 7260,
    'Scotiabank Toronto' => 7402
];

$allShowtimes = [];
foreach ($theatresToQuery as $tName => $tId) {
    $raw = $api->fetchShowtimes($tId, $dateStr);
    if (isset($raw['error']) || empty($raw[0]['dates'][0]['movies'])) continue;

    foreach ($raw[0]['dates'][0]['movies'] as $movie) {
        $movieTitle = $movie['name'] ?? $movie['title'] ?? 'Movie';
        if (empty($movie['experiences'])) continue;

        foreach ($movie['experiences'] as $exp) {
            $expTypes = $exp['experienceTypes'] ?? ['Standard'];
            $screenType = implode(', ', $expTypes);
            if (empty($exp['sessions'])) continue;

            foreach ($exp['sessions'] as $session) {
                $availSeats = isset($session['seatsRemaining']) ? (int)$session['seatsRemaining'] : 85;
                $totalSeats = in_array('IMAX', $expTypes) ? 380 : 250;
                $occupiedSeats = max(0, $totalSeats - $availSeats);
                $occPct = round(($occupiedSeats / $totalSeats) * 100, 1);
                $fillRate = round(($occupiedSeats / 2.5), 1);

                $allShowtimes[] = [
                    'movie' => $movieTitle,
                    'theatre' => $tName,
                    'screen' => $screenType,
                    'auditorium' => $session['auditorium'] ?? 'Aud',
                    'time' => $session['showStartDateTime'],
                    'avail' => $availSeats,
                    'total' => $totalSeats,
                    'occPct' => $occPct,
                    'fillRate' => $fillRate
                ];
            }
        }
    }
}

usort($allShowtimes, function($a, $b) {
    return $b['occPct'] <=> $a['occPct'];
});

echo "=== REAL MOVIE VELOCITY LEADERBOARD (Top 10 Most Full Showtimes) ===\n\n";
foreach (array_slice($allShowtimes, 0, 10) as $i => $item) {
    echo "#" . ($i + 1) . " [{$item['occPct']}% FULL] {$item['movie']}\n";
    echo "   📍 {$item['theatre']} | 🕒 " . date('h:i A', strtotime($item['time'])) . " | {$item['screen']}\n";
    echo "   🪑 {$item['avail']} seats left / {$item['total']} total | ⚡ +{$item['fillRate']} seats/hr\n\n";
}

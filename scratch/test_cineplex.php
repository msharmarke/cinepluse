<?php
require_once dirname(__DIR__) . '/src/Autoloader.php';
use Cinepulse\CineplexAPI;
use Cinepulse\ShowtimeService;

try {
    $api = new CineplexAPI();
    $theatres = ShowtimeService::getTrackerTheatres(false);
    echo "Total locations in locations.json: " . count($theatres) . "\n";
    
    // Pick top 5 locations
    $sampleTheatres = array_slice($theatres, 0, 5, true);
    $dateStr = date('m+d+Y');
    echo "Date queried: " . $dateStr . "\n\n";

    foreach ($sampleTheatres as $name => $id) {
        echo "=== Theatre: {$name} (ID: {$id}) ===\n";
        $res = $api->fetchShowtimes($id, $dateStr);
        if (!isset($res['error']) && !empty($res[0]['dates'][0]['movies'])) {
            $movies = $res[0]['dates'][0]['movies'];
            echo "Found " . count($movies) . " real movies playing today:\n";
            foreach ($movies as $m) {
                $mName = $m['name'] ?? $m['title'] ?? 'Unknown';
                $sessionsCount = 0;
                if (!empty($m['experiences'])) {
                    foreach ($m['experiences'] as $exp) {
                        $sessionsCount += count($exp['sessions'] ?? []);
                    }
                }
                echo "  • {$mName} ({$sessionsCount} showtimes)\n";
            }
        } else {
            echo "No live showtimes returned or API note: " . json_encode($res) . "\n";
        }
        echo "\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

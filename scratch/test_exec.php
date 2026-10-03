<?php
$apiKey = "dcdac5601d864addbc2675a2e96cb1f8";
$url = "https://apis.cineplex.com/prod/cpx/theatrical/api/v1/showtimes?language=en&locationId=7260&date=10+03+2026";

$cmd = 'curl.exe -s -H "Ocp-Apim-Subscription-Key: ' . $apiKey . '" "' . $url . '"';
$response = shell_exec($cmd);

echo "Length: " . strlen($response) . "\n";
if ($response) {
    $json = json_decode($response, true);
    if (!empty($json[0]['dates'][0]['movies'])) {
        echo "Movies count: " . count($json[0]['dates'][0]['movies']) . "\n";
        foreach ($json[0]['dates'][0]['movies'] as $m) {
            echo " - " . $m['name'] . "\n";
        }
    }
}

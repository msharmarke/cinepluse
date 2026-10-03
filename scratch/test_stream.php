<?php
$apiKey = "dcdac5601d864addbc2675a2e96cb1f8";
$url = "https://apis.cineplex.com/prod/cpx/theatrical/api/v1/showtimes?language=en&locationId=7260&date=10+03+2026";

$opts = [
    "http" => [
        "method" => "GET",
        "header" => "Ocp-Apim-Subscription-Key: {$apiKey}\r\n" .
                    "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64)\r\n",
        "timeout" => 15,
        "ignore_errors" => true
    ],
    "ssl" => [
        "verify_peer" => false,
        "verify_peer_name" => false
    ]
];
$context = stream_context_create($opts);
$response = @file_get_contents($url, false, $context);

echo "Response status: " . ($http_response_header[0] ?? 'No header') . "\n";
echo "Response length: " . strlen($response) . "\n";
if ($response) {
    $json = json_decode($response, true);
    if (!empty($json[0]['dates'][0]['movies'])) {
        echo "Movies count: " . count($json[0]['dates'][0]['movies']) . "\n";
        foreach ($json[0]['dates'][0]['movies'] as $m) {
            echo " - " . $m['name'] . "\n";
        }
    }
}

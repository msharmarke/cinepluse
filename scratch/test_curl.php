<?php
$ch = curl_init('https://apis.cineplex.com/prod/cpx/theatrical/api/v1/showtimes?language=en&locationId=7260&date=10+03+2026');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Ocp-Apim-Subscription-Key: dcdac5601d864addbc2675a2e96cb1f8',
    'User-Agent: Mozilla/5.0 (compatible; CinepulseAPI/1.0)'
]);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
$res = curl_exec($ch);
echo 'Err: ' . curl_error($ch) . "\n";
echo 'HTTP: ' . curl_getinfo($ch, CURLINFO_HTTP_CODE) . "\n";
echo 'Len: ' . strlen($res) . "\n";

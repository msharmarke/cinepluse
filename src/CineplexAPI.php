<?php
namespace Cinepulse;

use Exception;

/**
 * Cineplex API Handler Class
 * Manages queries to the external Cineplex services with robust caching.
 */
class CineplexAPI {
    private $apiKey;
    private $cacheDir;

    public function __construct() {
        $config_file = dirname(__DIR__) . '/config/config.ini';
        $api_key = getenv('CINEPLEX_API_KEY');

        // Check file config
        if (file_exists($config_file)) {
            $config = parse_ini_file($config_file, true);
            if (isset($config['api']['key'])) {
                $api_key = $config['api']['key'];
            }
        }

        if (empty($api_key)) {
            throw new Exception("Cineplex subscription API key is not configured.");
        }

        $this->apiKey = $api_key;
        $this->cacheDir = dirname(__DIR__) . '/cache';
        
        // Ensure cache directory exists
        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0755, true);
        }
    }

    /**
     * Call the generic Cineplex API endpoint
     * 
     * @param string $apiUrl
     * @param int $retry
     * @return array
     */
    public function fetchCineplexAPI($apiUrl, $retry = 0) {
        // Enforce SSRF protection
        $parsedUrl = parse_url($apiUrl);
        if (!isset($parsedUrl['host']) || !in_array($parsedUrl['host'], ['apis.cineplex.com'])) {
            return ['error' => 'SSRF Warning: Invalid external request target host.'];
        }

        // Check if cURL extension is installed; fallback to stream_context_create if absent
        if (!function_exists('curl_init')) {
            $opts = [
                "http" => [
                    "method" => "GET",
                    "header" => "Ocp-Apim-Subscription-Key: " . $this->apiKey . "\r\n" .
                                "User-Agent: Mozilla/5.0 (compatible; CinepulseAPI/1.0)\r\n",
                    "timeout" => 30,
                    "ignore_errors" => true
                ],
                "ssl" => [
                    "verify_peer" => false,
                    "verify_peer_name" => false
                ]
            ];
            $context = stream_context_create($opts);
            $response = @file_get_contents($apiUrl, false, $context);
            if ($response === false) {
                return ['error' => 'API HTTP stream request failed.'];
            }
            $data = json_decode($response, true);
            return is_array($data) ? $data : ['error' => 'Failed parsing JSON return content from Cineplex API.'];
        }

        $ch = \curl_init();
        curl_setopt($ch, CURLOPT_URL, $apiUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Ocp-Apim-Subscription-Key: ' . $this->apiKey,
            'User-Agent: Mozilla/5.0 (compatible; CinepulseAPI/1.0)'
        ]);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        // In local development or CLI environment, bypass SSL verification if native CA bundles are missing
        $isLocal = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1']) || (php_sapi_name() === 'cli');
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, !$isLocal);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $isLocal ? 0 : 2);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        // Exponential backoff retries on request failure
        if ($curlError) {
            if ($retry < 2) {
                sleep(pow(2, $retry));
                return $this->fetchCineplexAPI($apiUrl, $retry + 1);
            }
            return ['error' => 'API Connection Timeout Error: ' . $curlError];
        }

        // HTTP 204 means no schedule content available
        if ($httpCode === 204) {
            return [];
        }

        if ($httpCode !== 200) {
            return ['error' => "Cineplex API request failed with HTTP code $httpCode"];
        }

        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return ['error' => 'Failed parsing JSON return content from Cineplex API.'];
        }

        return $data;
    }

    /**
     * Get a global list of movies currently playing (cached for 12 hours)
     * 
     * @return array
     */
    public function fetchMovies() {
        $cacheFile = $this->cacheDir . '/movies_cache.json';
        
        if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < 43200)) {
            $data = json_decode(file_get_contents($cacheFile), true);
            if ($data && is_array($data)) return $data;
        }

        $apiUrl = "https://apis.cineplex.com/prod/cpx/theatrical/api/v1/movies?language=en";
        $data = $this->fetchCineplexAPI($apiUrl);
        
        if (!isset($data['error']) && is_array($data)) {
            file_put_contents($cacheFile, json_encode($data));
        }

        return $data;
    }

    /**
     * Get showtimes by location and date (cached for 30 minutes)
     * 
     * @param int $locationId
     * @param string $dateStr (Format: 'm+d+Y')
     * @param bool $forceRefresh
     * @return array
     */
    public function fetchShowtimes($locationId, $dateStr, $forceRefresh = false) {
        $locationId = (int)$locationId;
        $dateClean = preg_replace('/[^0-9+]/', '', $dateStr); // Sanitize
        
        // Use standard alphanumeric filename isolation to block traversal tricks natively
        $filename = 'showtimes_' . $locationId . '_' . str_replace('+', '-', $dateClean) . '.json';
        $cacheFile = $this->cacheDir . '/' . $filename;
        $ttl = 1800; // 30 minutes in seconds

        if (!$forceRefresh && file_exists($cacheFile) && (time() - filemtime($cacheFile) < $ttl)) {
            $cachedContent = @file_get_contents($cacheFile);
            if ($cachedContent !== false) {
                $decoded = json_decode($cachedContent, true);
                if (json_last_error() === JSON_ERROR_NONE && !empty($decoded)) {
                    return $decoded;
                }
            }
        }

        $apiUrl = "https://apis.cineplex.com/prod/cpx/theatrical/api/v1/showtimes?language=en&locationId={$locationId}&date={$dateClean}";
        $data = $this->fetchCineplexAPI($apiUrl);

        if (!isset($data['error']) && !empty($data)) {
            @file_put_contents($cacheFile, json_encode($data), LOCK_EX);
            return $data;
        }

        // Stale cache file fallback if live API fails or is empty
        if (file_exists($cacheFile)) {
            $cachedContent = @file_get_contents($cacheFile);
            if ($cachedContent !== false) {
                $decoded = json_decode($cachedContent, true);
                if (json_last_error() === JSON_ERROR_NONE && !empty($decoded)) {
                    return $decoded;
                }
            }
        }

        // Database fallback if file cache is not available
        $dbData = $this->fetchShowtimesFromDatabase($locationId, $dateStr);
        if (!empty($dbData)) {
            return $dbData;
        }

        return $data;
    }

    /**
     * Reconstruct API showtimes structure directly from MySQL `showtimes` table
     * 
     * @param int $locationId
     * @param string $dateStr
     * @return array
     */
    public function fetchShowtimesFromDatabase($locationId, $dateStr) {
        try {
            $db = Database::getInstance()->getConnection();
            $dateClean = str_replace('+', '-', $dateStr);
            $dateFormatted = date('Y-m-d', strtotime($dateClean));
            
            $stmt = $db->prepare("SELECT * FROM showtimes WHERE theatre_id = ? AND (show_date = ? OR DATE(show_start_time) = ?) ORDER BY movie_name, show_start_time");
            $stmt->execute([$locationId, $dateFormatted, $dateFormatted]);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            if (empty($rows)) {
                return [];
            }

            $moviesMap = [];
            foreach ($rows as $row) {
                $movieName = $row['movie_name'];
                if (!isset($moviesMap[$movieName])) {
                    $moviesMap[$movieName] = [
                        'name' => $movieName,
                        'runtime' => (int)($row['movie_runtime_minutes'] ?: 120),
                        'runtimeInMinutes' => (int)($row['movie_runtime_minutes'] ?: 120),
                        'experiences' => []
                    ];
                }

                $expTypes = json_decode($row['experience_types'] ?? '[]', true);
                if (!is_array($expTypes) || empty($expTypes)) {
                    $expTypes = ['Standard'];
                }
                $expKey = implode(', ', $expTypes);

                if (!isset($moviesMap[$movieName]['experiences'][$expKey])) {
                    $moviesMap[$movieName]['experiences'][$expKey] = [
                        'experienceTypes' => $expTypes,
                        'sessions' => []
                    ];
                }

                $moviesMap[$movieName]['experiences'][$expKey]['sessions'][] = [
                    'vistaSessionId' => $row['showtime_id'],
                    'auditorium' => $row['screen_name'] ?: 'Auditorium',
                    'showStartDateTime' => date('c', strtotime($row['show_start_time'])),
                    'price' => (float)($row['ticket_price'] ?: 14.99)
                ];
            }

            $movies = [];
            foreach ($moviesMap as $m) {
                $m['experiences'] = array_values($m['experiences']);
                $movies[] = $m;
            }

            return [
                [
                    'locationId' => (int)$locationId,
                    'dates' => [
                        [
                            'date' => $dateFormatted,
                            'movies' => $movies
                        ]
                    ]
                ]
            ];
        } catch (\Exception $e) {
            error_log("fetchShowtimesFromDatabase error: " . $e->getMessage());
            return [];
        }
    }
}
<?php
namespace Cinepulse;

/**
 * Showtime Management & Double Feature compatibility Service
 */
class ShowtimeService {
    
    /**
     * Get theatre map [name => id]
     * 
     * @param bool $activeOnly Whether to filter enabled theatres only
     * @return array
     */
    public static function getTrackerTheatres($activeOnly = false) {
        $locFile = dirname(__DIR__) . '/config/locations.json';
        if (!file_exists($locFile)) {
            return [];
        }
        $raw = json_decode(file_get_contents($locFile), true) ?: [];
        
        // Sort raw entries: enabled first, then name
        uksort($raw, function($a, $b) use ($raw) {
            $eA = is_array($raw[$a]) ? (bool)($raw[$a]['enabled'] ?? true) : true;
            $eB = is_array($raw[$b]) ? (bool)($raw[$b]['enabled'] ?? true) : true;
            if ($eA !== $eB) {
                return $eB <=> $eA; // Enabled first
            }
            return strnatcasecmp($a, $b);
        });

        $map = [];
        foreach ($raw as $name => $data) {
            if (is_array($data)) {
                $id = $data['id'] ?? null;
                $enabled = $data['enabled'] ?? true;
                if ($activeOnly && !$enabled) continue;
                if ($id) $map[$name] = (int)$id;
            } else {
                $map[$name] = (int)$data;
            }
        }
        return $map;
    }

    /**
     * Get rich theatre catalog list
     * 
     * @return array
     */
    public static function getDetailedTheatres() {
        $locFile = dirname(__DIR__) . '/config/locations.json';
        if (!file_exists($locFile)) {
            return [];
        }
        $raw = json_decode(file_get_contents($locFile), true) ?: [];
        $list = [];
        foreach ($raw as $name => $data) {
            if (is_array($data)) {
                $list[] = [
                    'name' => $name,
                    'id' => (int)($data['id'] ?? 0),
                    'city' => $data['city'] ?? 'Unknown',
                    'province' => $data['province'] ?? 'ON',
                    'region' => $data['region'] ?? 'Canada',
                    'screens' => $data['screens'] ?? ['Standard'],
                    'enabled' => (bool)($data['enabled'] ?? true)
                ];
            } else {
                $list[] = [
                    'name' => $name,
                    'id' => (int)$data,
                    'city' => 'Unknown',
                    'province' => 'ON',
                    'region' => 'Canada',
                    'screens' => ['Standard'],
                    'enabled' => true
                ];
            }
        }

        // Default order: enabled first, then alphabetical by name
        usort($list, function($a, $b) {
            if ($a['enabled'] !== $b['enabled']) {
                return $b['enabled'] <=> $a['enabled'];
            }
            return strnatcasecmp($a['name'], $b['name']);
        });

        return $list;
    }

    /**
     * Map location IDs to slugs
     * 
     * @return array
     */
    public static function getTheatreSlugMap() {
        $locations = self::getTrackerTheatres(false);
        $map = [];
        foreach ($locations as $name => $id) {
            $slug = strtolower($name);
            $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
            $slug = trim($slug, '-');
            
            $map[$slug] = ['id' => $id, 'name' => $name];
            $map[$id] = ['id' => $id, 'name' => $name, 'slug' => $slug];
        }
        return $map;
    }

    /**
     * Find Theatre ID from name or slug
     * 
     * @param string|int $slugOrId
     * @return int|null
     */
    public static function getTheatreId($slugOrId) {
        $map = self::getTheatreSlugMap();
        if (is_numeric($slugOrId) && isset($map[$slugOrId])) {
            return $map[$slugOrId]['id'];
        }
        if (isset($map[$slugOrId])) {
            return $map[$slugOrId]['id'];
        }
        
        // Fuzzy matching
        $inputLower = strtolower(trim($slugOrId));
        foreach ($map as $key => $info) {
            if (isset($info['slug'])) {
                if (strpos($info['slug'], $inputLower) !== false || strpos($inputLower, $info['slug']) !== false) {
                    return $info['id'];
                }
            }
        }
        return null;
    }

    /**
     * Group sessions by experience types and ensure uniqueness
     * 
     * @param array $experiences
     * @param int $movieIndex
     * @return array
     */
    public static function groupSessionsByExperience($experiences, $movieIndex) {
        $groups = [];
        $seenSessionIds = [];

        foreach ($experiences as $experience) {
            if (empty($experience['sessions'])) continue;

            $types = $experience['experienceTypes'];
            sort($types);
            $typeKey = implode(', ', $types);

            if (!isset($groups[$typeKey])) {
                $groups[$typeKey] = [];
            }

            foreach ($experience['sessions'] as $session) {
                $sessionId = $session['vistaSessionId'];
                
                if (!isset($seenSessionIds[$sessionId])) {
                    $session['grouped_type_key'] = $typeKey;
                    $groups[$typeKey][$sessionId] = $session;
                    $seenSessionIds[$sessionId] = true;
                } else {
                    // If session is already added, place it in the most descriptive/specific group
                    foreach ($groups as $existingKey => &$existingSessions) {
                        if (isset($existingSessions[$sessionId])) {
                            $existingCount = count(explode(', ', $existingKey));
                            $currentCount = count($types);
                            if ($currentCount > $existingCount) {
                                unset($existingSessions[$sessionId]);
                                $session['grouped_type_key'] = $typeKey;
                                $groups[$typeKey][$sessionId] = $session;
                            }
                            break;
                        }
                    }
                    unset($existingSessions);
                }
            }
        }
        
        // Sort keys for UI consistency
        ksort($groups);
        return array_filter($groups);
    }

    /**
     * Validate compatibility between two double-feature showtimes
     * 
     * @param array $firstSession [start_time, runtime, auditorium, name]
     * @param array $secondSession [start_time, runtime, auditorium, name]
     * @return array [compatible => bool, message => string, gap => int, warning => string]
     */
    public static function checkDoubleFeatureCompatibility($firstSession, $secondSession) {
        $start1 = (int)$firstSession['start_time'];
        $runtime1 = (int)$firstSession['runtime'];
        $end1 = $start1 + ($runtime1 * 60);
        
        $start2 = (int)$secondSession['start_time'];
        
        if ($start2 < $end1) {
            return [
                'compatible' => false,
                'message' => 'The movies overlap! The second movie starts before the first one ends.',
                'gap' => 0,
                'warning' => ''
            ];
        }

        $gapSeconds = $start2 - $end1;
        $gapMinutes = round($gapSeconds / 60);
        
        if ($gapMinutes < 10) {
            return [
                'compatible' => true,
                'message' => "Warning: Tight scheduling. You only have a $gapMinutes-minute gap between movies.",
                'gap' => $gapMinutes,
                'warning' => 'tight_gap'
            ];
        }

        if ($gapMinutes > 120) {
            return [
                'compatible' => true,
                'message' => "Warning: Long wait. There is a $gapMinutes-minute gap between these movies.",
                'gap' => $gapMinutes,
                'warning' => 'long_gap'
            ];
        }

        // Aud proximity warning
        $aud1 = $firstSession['auditorium'];
        $aud2 = $secondSession['auditorium'];
        $warning = '';
        
        if ($aud1 !== $aud2) {
            preg_match('/(\d+)/', $aud1, $m1);
            preg_match('/(\d+)/', $aud2, $m2);
            
            if (isset($m1[1]) && isset($m2[1])) {
                $num1 = (int)$m1[1];
                $num2 = (int)$m2[1];
                if (abs($num1 - $num2) > 4) {
                    $warning = "Note: Movies are in different auditoriums ($aud1 vs $aud2) which might require moving across the theater.";
                }
            } else {
                $warning = "Note: Movies are in different auditoriums ($aud1 vs $aud2).";
            }
        }

        return [
            'compatible' => true,
            'message' => "Perfect scheduling! You have a $gapMinutes-minute break between movies.",
            'gap' => $gapMinutes,
            'warning' => $warning
        ];
    }

    /**
     * Save/upsert API showtime listings into MySQL database table
     * 
     * @param int $theatreId
     * @param string $theatreName
     * @param array $apiData
     * @return int Count of showtimes inserted/updated
     */
    public static function saveShowtimesToDatabase($theatreId, $theatreName, $apiData) {
        if (empty($apiData[0]['dates'][0]['movies'])) {
            return 0;
        }

        try {
            $db = Database::getInstance()->getConnection();
            $movies = $apiData[0]['dates'][0]['movies'];
            $count = 0;

            foreach ($movies as $movie) {
                $movieName = $movie['name'] ?? $movie['title'] ?? '';
                if (empty($movieName)) continue;
                
                $runtime = (int)($movie['runtime'] ?? $movie['runtimeMinutes'] ?? 120);

                if (!empty($movie['experiences'])) {
                    foreach ($movie['experiences'] as $exp) {
                        $expTypes = $exp['experienceTypes'] ?? [];
                        $expJson = json_encode($expTypes);

                        $is3d = in_array('3D', $expTypes) ? 1 : 0;
                        $isImax = in_array('IMAX', $expTypes) ? 1 : 0;
                        $isVip = in_array('VIP', $expTypes) ? 1 : 0;
                        $isDbox = in_array('D-BOX', $expTypes) ? 1 : 0;
                        $isUltra = in_array('UltraAVX', $expTypes) ? 1 : 0;

                        if (!empty($exp['sessions'])) {
                            foreach ($exp['sessions'] as $session) {
                                $showtimeId = $session['vistaSessionId'] ?? null;
                                if (!$showtimeId) continue;

                                $screenName = $session['auditorium'] ?? $session['screenName'] ?? 'Auditorium';
                                $startTimeIso = $session['showStartDateTime'] ?? null;
                                if (!$startTimeIso) continue;

                                $startSec = strtotime($startTimeIso);
                                if (!$startSec) continue;

                                $startTimeSql = date('Y-m-d H:i:s', $startSec);
                                $endTimeSql = date('Y-m-d H:i:s', $startSec + ($runtime * 60));
                                $price = isset($session['price']) ? (float)$session['price'] : 14.99;

                                $stmt = $db->prepare("INSERT INTO showtimes 
                                    (theatre_id, theatre_name, showtime_id, movie_name, movie_runtime_minutes, screen_name, show_start_time, show_end_time, experience_types, ticket_price, is_3d, is_imax, is_vip, is_dbox, is_ultraavx)
                                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                                    ON DUPLICATE KEY UPDATE 
                                    movie_runtime_minutes = VALUES(movie_runtime_minutes),
                                    screen_name = VALUES(screen_name),
                                    experience_types = VALUES(experience_types),
                                    ticket_price = VALUES(ticket_price)");
                                
                                $stmt->execute([
                                    $theatreId, $theatreName, $showtimeId, $movieName, $runtime, $screenName,
                                    $startTimeSql, $endTimeSql, $expJson, $price, $is3d, $isImax, $isVip, $isDbox, $isUltra
                                ]);
                                $count++;
                            }
                        }
                    }
                }
            }
            return $count;
        } catch (\Exception $e) {
            error_log("Failed saving showtimes to DB: " . $e->getMessage());
            return 0;
        }
    }
}

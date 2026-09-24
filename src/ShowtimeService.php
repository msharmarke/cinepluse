<?php
namespace Cinepulse;

/**
 * Showtime Management & Double Feature compatibility Service
 */
class ShowtimeService {
    
    /**
     * Map location IDs to slugs
     * 
     * @return array
     */
    public static function getTheatreSlugMap() {
        $locations = get_tracker_theatres();
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
}

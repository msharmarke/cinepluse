<?php
namespace Cinepulse;

use PDO;
use Exception;

/**
 * Cinepulse Passport Service
 * Manages user passports, movie check-in stamps, achievement badges, and movie stats.
 */
class PassportService {

    /**
     * Get User Passport Details & Badges
     */
    public static function getUserPassport($user_id) {
        try {
            $db = Database::getInstance()->getConnection();
            $stmt = $db->prepare("
                SELECT p.*, u.display_name, u.avatar_url, u.email, u.created_at as joined_date
                FROM user_passports p
                JOIN users u ON p.user_id = u.id
                WHERE p.user_id = ?
            ");
            $stmt->execute([$user_id]);
            $passport = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($passport) {
                if (is_string($passport['badges'])) {
                    $passport['badges'] = json_decode($passport['badges'], true) ?: [];
                }
                $passport['stamps'] = self::getUserStamps($user_id);
                return $passport;
            }
        } catch (Exception $e) {
            error_log("Passport Fetch Error: " . $e->getMessage());
        }

        // Return sample passport if DB record doesn't exist yet
        return self::getSamplePassportData($user_id);
    }

    /**
     * Fetch Passport Stamps for User
     */
    public static function getUserStamps($user_id, $limit = 20) {
        try {
            $db = Database::getInstance()->getConnection();
            $stmt = $db->prepare("
                SELECT id, movie_title, theatre_name, screening_date, screening_time, 
                       format_type, seat_label, rating, notes, created_at
                FROM passport_stamps
                WHERE user_id = ?
                ORDER BY screening_date DESC, id DESC
                LIMIT " . (int)$limit);
            $stmt->execute([$user_id]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Stamps Fetch Error: " . $e->getMessage());
            return self::getSampleStampsData();
        }
    }

    /**
     * Add a Movie Ticket Check-In Stamp
     */
    public static function addStamp($user_id, $data) {
        $movie_title = trim($data['movie_title'] ?? '');
        $theatre_name = trim($data['theatre_name'] ?? 'Scotiabank Theatre Toronto');
        $screening_date = $data['screening_date'] ?? date('Y-m-d');
        $screening_time = $data['screening_time'] ?? '19:00';
        $format_type = $data['format_type'] ?? 'IMAX 70mm';
        $seat_label = $data['seat_label'] ?? 'Row G, Seat 14';
        $rating = floatval($data['rating'] ?? 5.0);
        $notes = trim($data['notes'] ?? '');

        if (empty($movie_title)) {
            throw new Exception("Movie title is required for passport stamp.");
        }

        try {
            $db = Database::getInstance()->getConnection();
            $stmt = $db->prepare("
                INSERT INTO passport_stamps (user_id, movie_title, theatre_name, screening_date, screening_time, format_type, seat_label, rating, notes)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$user_id, $movie_title, $theatre_name, $screening_date, $screening_time, $format_type, $seat_label, $rating, $notes]);

            // Increment User Stats
            $update = $db->prepare("UPDATE user_passports SET movies_watched_count = movies_watched_count + 1 WHERE user_id = ?");
            $update->execute([$user_id]);

            return true;
        } catch (Exception $e) {
            error_log("Add Stamp Error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Sample Passport Data for rich demonstration
     */
    public static function getSamplePassportData($user_id) {
        return [
            'user_id' => $user_id ?: 1,
            'display_name' => 'Moe Sharmarke',
            'avatar_url' => 'https://lh3.googleusercontent.com/a/ACg8ocI...=s96-c',
            'email' => 'moe@cinepulse.local',
            'joined_date' => '2026-01-15',
            'favorite_theatre_id' => 7402,
            'favorite_theatre_name' => 'Scotiabank Theatre Toronto',
            'bio' => 'Cinephile & IMAX 70mm enthusiast.',
            'movies_watched_count' => 14,
            'total_showtimes_tracked' => 48,
            'badges' => [
                ['id' => 'imax_pioneer', 'title' => 'IMAX 70mm Pioneer', 'icon' => '🌌', 'desc' => 'Attended 3+ 70mm IMAX Screenings'],
                ['id' => 'opening_night', 'title' => 'Opening Night Club', 'icon' => '🍿', 'desc' => 'Checked into Thursday premiere showtimes'],
                ['id' => 'vip_connoisseur', 'title' => 'VIP Connoisseur', 'icon' => '🛋️', 'desc' => 'Attended Cineplex VIP Cinemas'],
                ['id' => 'multiplex_explorer', 'title' => 'Multiplex Explorer', 'icon' => '📍', 'desc' => 'Visited 5+ different theater locations']
            ],
            'stamps' => self::getSampleStampsData()
        ];
    }

    /**
     * Sample Passport Stamps
     */
    public static function getSampleStampsData() {
        return [
            [
                'id' => 101,
                'movie_title' => 'Interstellar (70mm IMAX Rerelease)',
                'theatre_name' => 'Scotiabank Theatre Toronto',
                'screening_date' => '2026-10-02',
                'screening_time' => '19:30',
                'format_type' => 'IMAX 70mm',
                'seat_label' => 'Row G, Seat 14',
                'rating' => 5.0,
                'notes' => 'Absolute masterpiece on the 70mm dual laser projector!'
            ],
            [
                'id' => 102,
                'movie_title' => 'Dune: Part Two',
                'theatre_name' => 'Vaughan Colossus IMAX',
                'screening_date' => '2026-09-18',
                'screening_time' => '20:15',
                'format_type' => 'IMAX 1.43:1',
                'seat_label' => 'Row F, Seat 22',
                'rating' => 5.0,
                'notes' => 'Unbelievable sound design during the worm-riding scene.'
            ],
            [
                'id' => 103,
                'movie_title' => 'Oppenheimer',
                'theatre_name' => 'Cineplex Courtney Park',
                'screening_date' => '2026-08-29',
                'screening_time' => '18:00',
                'format_type' => 'IMAX 70mm Film',
                'seat_label' => 'Row H, Seat 18',
                'rating' => 4.8,
                'notes' => 'Trinity test sequence gave me goosebumps.'
            ]
        ];
    }
}

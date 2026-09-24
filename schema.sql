-- Cinepulse Consolidated Database Schema Setup
-- Run this script to initialize all required tables for showtimes browsing, occupancy logging, alerts, and tracking.

-- 1. Main schedule of showtimes
CREATE TABLE IF NOT EXISTS `showtimes` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `theatre_id` INT NOT NULL,
    `theatre_name` VARCHAR(255) NOT NULL,
    `showtime_id` VARCHAR(100) NOT NULL,
    `movie_name` VARCHAR(255) NOT NULL,
    `movie_runtime_minutes` INT NULL,
    `screen_name` VARCHAR(100) NULL,
    `show_start_time` DATETIME NOT NULL,
    `show_end_time` DATETIME NULL,
    `experience_types` TEXT NULL, -- JSON array of experiences
    `ticket_price` DECIMAL(10,2) NULL,
    `is_3d` TINYINT(1) DEFAULT 0,
    `is_imax` TINYINT(1) DEFAULT 0,
    `is_vip` TINYINT(1) DEFAULT 0,
    `is_dbox` TINYINT(1) DEFAULT 0,
    `is_ultraavx` TINYINT(1) DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `show_date` DATE AS (DATE(`show_start_time`)) STORED,
    INDEX `idx_show_start_time` (`show_start_time`),
    INDEX `idx_movie_name` (`movie_name`),
    INDEX `idx_theatre_date` (`theatre_id`, `show_date`),
    UNIQUE KEY `unique_showtime` (`theatre_id`, `showtime_id`, `show_start_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. General occupancy check logs (single snapshot 5-14 mins after start)
CREATE TABLE IF NOT EXISTS `showtime_occupancy_log` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `theatre_id` INT NOT NULL,
    `showtime_id` VARCHAR(100) NOT NULL,
    `movie_name` VARCHAR(255) NOT NULL,
    `screen_name` VARCHAR(100) NULL,
    `show_start_time` DATETIME NOT NULL,
    `runtime_minutes` INT NULL,
    `check_time` DATETIME NOT NULL,
    `seats_occupied` INT DEFAULT 0,
    `seats_available` INT DEFAULT 0,
    `seats_broken` INT DEFAULT 0,
    `seats_total_layout` INT DEFAULT 0,
    `calculated_capacity` INT DEFAULT 0,
    `occupancy_percentage` DECIMAL(5,2) DEFAULT 0.00,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `show_date` DATE AS (DATE(`show_start_time`)) STORED,
    INDEX `idx_show_start_time` (`show_start_time`),
    INDEX `idx_check_time` (`check_time`),
    INDEX `idx_movie_name` (`movie_name`),
    UNIQUE KEY `unique_showtime_check` (`theatre_id`, `showtime_id`, `show_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Email notifications tracking table (to prevent spam)
CREATE TABLE IF NOT EXISTS `email_notifications_sent` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `theatre_id` INT NOT NULL,
    `showtime_id` VARCHAR(100) NOT NULL,
    `show_start_time` DATETIME NOT NULL,
    `show_date` DATE AS (DATE(`show_start_time`)) STORED,
    `threshold` INT NOT NULL,
    `occupancy_percentage` DECIMAL(5,2) NOT NULL,
    `email_sent_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_email_sent_at` (`email_sent_at`),
    UNIQUE KEY `unique_email_notification` (`theatre_id`, `showtime_id`, `show_date`, `threshold`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Social media notifications tracking table (Reddit etc.)
CREATE TABLE IF NOT EXISTS `social_media_notifications_sent` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `theatre_id` INT NOT NULL,
    `showtime_id` VARCHAR(100) NOT NULL,
    `show_start_time` DATETIME NOT NULL,
    `show_date` DATE AS (DATE(`show_start_time`)) STORED,
    `platform` VARCHAR(50) NOT NULL,
    `threshold` INT NOT NULL,
    `post_id` VARCHAR(100) NULL,
    `post_url` VARCHAR(255) NULL,
    `posted_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_posted_at` (`posted_at`),
    UNIQUE KEY `unique_social_notification` (`theatre_id`, `showtime_id`, `show_date`, `platform`, `threshold`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Tracked showtimes for monitoring over time
CREATE TABLE IF NOT EXISTS `tracked_showtimes` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `theatre_id` INT NOT NULL,
    `theatre_name` VARCHAR(255) NOT NULL,
    `showtime_id` VARCHAR(100) NOT NULL,
    `movie_name` VARCHAR(255) NOT NULL,
    `show_start_time` DATETIME NOT NULL,
    `added_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `status` ENUM('active', 'completed', 'failed') DEFAULT 'active',
    UNIQUE KEY `unique_showtime_tracker` (`theatre_id`, `showtime_id`, `show_start_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Historical snapshots log for monitored showtimes
CREATE TABLE IF NOT EXISTS `showtime_snapshots_history` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `tracked_showtime_id` INT NOT NULL,
    `snapshot_time` DATETIME NOT NULL,
    `seats_occupied` INT NOT NULL,
    `seats_available` INT NOT NULL,
    `seats_broken` INT NOT NULL,
    `seats_total_layout` INT NOT NULL,
    `calculated_capacity` INT NOT NULL,
    `occupancy_percentage` DECIMAL(5,2) NOT NULL,
    `seatmap_file_path` VARCHAR(255) NOT NULL,
    FOREIGN KEY (`tracked_showtime_id`) REFERENCES `tracked_showtimes` (`id`) ON DELETE CASCADE,
    INDEX `idx_snapshot_time` (`snapshot_time`),
    INDEX `idx_tracked_showtime` (`tracked_showtime_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Automatic movie release trackers
CREATE TABLE IF NOT EXISTS `movie_release_trackers` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `movie_name` VARCHAR(255) NOT NULL,
    `experience_filter` VARCHAR(255) DEFAULT NULL,
    `theatre_id` INT NOT NULL,
    `theatre_name` VARCHAR(255) NOT NULL,
    `start_date` DATE DEFAULT NULL,
    `end_date` DATE DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `status` ENUM('active', 'paused') DEFAULT 'active',
    UNIQUE KEY `unique_movie_theatre_filter` (`movie_name`, `theatre_id`, `experience_filter`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. Showtime release notifications log
CREATE TABLE IF NOT EXISTS `showtime_release_alerts` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `movie_name` VARCHAR(255) NOT NULL,
    `theatre_name` VARCHAR(255) NOT NULL,
    `show_start_time` DATETIME NOT NULL,
    `experience_type` VARCHAR(100) NOT NULL,
    `notified_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. Scraper execution logs
CREATE TABLE IF NOT EXISTS `movie_tracker_scan_logs` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `movie_tracker_id` INT NOT NULL,
    `movie_name` VARCHAR(255) NOT NULL,
    `theatre_name` VARCHAR(255) NOT NULL,
    `date_scanned` DATE NOT NULL,
    `scanned_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `status` ENUM('success', 'error') DEFAULT 'success',
    `results_found` INT NOT NULL DEFAULT 0,
    `new_registered` INT NOT NULL DEFAULT 0,
    `error_message` TEXT DEFAULT NULL,
    INDEX `idx_tracker` (`movie_tracker_id`),
    INDEX `idx_scanned_at` (`scanned_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;




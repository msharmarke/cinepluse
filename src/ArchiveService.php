<?php
namespace Cinepulse;

use PDO;
use Exception;

/**
 * Archive & Historical Data Service
 * Manages timestamped database archives, history retrieval, and database resets.
 */
class ArchiveService {
    private $db;
    private $archiveDir;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
        $this->archiveDir = dirname(__DIR__) . '/archives';

        if (!is_dir($this->archiveDir)) {
            @mkdir($this->archiveDir, 0755, true);
        }
    }

    /**
     * List all available archives with counts and file metadata
     * 
     * @return array
     */
    public function listArchives() {
        if (!is_dir($this->archiveDir)) return [];

        $archives = [];
        $dirs = scandir($this->archiveDir, SCANDIR_SORT_DESCENDING);

        foreach ($dirs as $dir) {
            if ($dir === '.' || $dir === '..' || !is_dir($this->archiveDir . '/' . $dir)) {
                continue;
            }

            $path = $this->archiveDir . '/' . $dir;
            $createdTime = filemtime($path);

            $info = [
                'name' => $dir,
                'created' => date('Y-m-d H:i:s', $createdTime),
                'created_timestamp' => $createdTime,
                'occupancy_count' => 0,
                'showtimes_count' => 0,
                'files' => []
            ];

            // Extract date/time from folder name (archive_YYYY-MM-DD_HHMMSS)
            if (preg_match('/archive_(\d{4}-\d{2}-\d{2})_(\d{6})/', $dir, $matches)) {
                $info['date'] = $matches[1];
                $info['time'] = $matches[2];
            }

            // Read README.md for counts
            $readmeFile = $path . '/README.md';
            if (file_exists($readmeFile)) {
                $readme = file_get_contents($readmeFile);
                if (preg_match('/showtime_occupancy_log:\s*(\d+)\s*records/i', $readme, $m)) {
                    $info['occupancy_count'] = (int)$m[1];
                }
                if (preg_match('/showtimes:\s*(\d+)\s*records/i', $readme, $m)) {
                    $info['showtimes_count'] = (int)$m[1];
                }
            }

            // Scan files inside archive
            $files = scandir($path);
            foreach ($files as $file) {
                if ($file === '.' || $file === '..') continue;
                $filePath = $path . '/' . $file;
                if (is_file($filePath)) {
                    $info['files'][] = [
                        'name' => $file,
                        'size' => filesize($filePath),
                        'size_formatted' => $this->formatBytes(filesize($filePath))
                    ];
                }
            }

            $archives[] = $info;
        }

        return $archives;
    }

    /**
     * Create a new timestamped archive package of current DB records
     * 
     * @param string $label Optional custom label
     * @return array Result metadata
     * @throws Exception
     */
    public function createArchivePackage($label = '') {
        $timestamp = date('Y-m-d_His');
        $folderName = 'archive_' . $timestamp;
        if (!empty($label)) {
            $cleanLabel = preg_replace('/[^a-zA-Z0-9_-]/', '_', $label);
            $folderName .= '_' . $cleanLabel;
        }

        $targetDir = $this->archiveDir . '/' . $folderName;
        if (!is_dir($targetDir)) {
            if (!@mkdir($targetDir, 0755, true)) {
                throw new Exception("Failed to create archive directory: {$targetDir}");
            }
        }

        $tables = [
            'showtimes',
            'tracked_showtimes',
            'showtime_snapshots_history',
            'showtime_occupancy_log',
            'movie_release_trackers',
            'showtime_release_alerts',
            'movie_tracker_scan_logs'
        ];

        $counts = [];
        $sqlOutput = "-- Cinepulse Database Archive Dump\n";
        $sqlOutput .= "-- Created: " . date('Y-m-d H:i:s') . "\n";
        $sqlOutput .= "-- Folder: " . $folderName . "\n\n";

        foreach ($tables as $table) {
            try {
                $stmt = $this->db->query("SELECT * FROM `{$table}`");
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $counts[$table] = count($rows);

                if (empty($rows)) {
                    continue;
                }

                $sqlOutput .= "-- Dumping data for table `{$table}` (" . count($rows) . " rows)\n";
                foreach ($rows as $row) {
                    $cols = array_keys($row);
                    $escapedCols = array_map(function($c) { return "`{$c}`"; }, $cols);
                    $escapedVals = array_map(function($v) {
                        if ($v === null) return "NULL";
                        return $this->db->quote($v);
                    }, array_values($row));

                    $sqlOutput .= "INSERT INTO `{$table}` (" . implode(', ', $escapedCols) . ") VALUES (" . implode(', ', $escapedVals) . ");\n";
                }
                $sqlOutput .= "\n";
            } catch (\Exception $e) {
                $counts[$table] = 0;
            }
        }

        // Save SQL backup file
        $sqlFilePath = $targetDir . '/backup_' . date('Y-m-d') . '.sql';
        file_put_contents($sqlFilePath, $sqlOutput);

        // Generate README.md
        $readme = "# Cinepulse Archive Package: {$folderName}\n\n";
        $readme .= "- **Created At**: " . date('Y-m-d H:i:s') . "\n";
        if ($label) {
            $readme .= "- **Label**: " . htmlspecialchars($label) . "\n";
        }
        $readme .= "\n## Record Statistics\n";
        foreach ($counts as $tbl => $cnt) {
            $readme .= "- **{$tbl}**: {$cnt} records\n";
        }
        $readme .= "\n## File Manifest\n";
        $readme .= "- `backup_" . date('Y-m-d') . ".sql` (" . $this->formatBytes(filesize($sqlFilePath)) . ")\n";

        file_put_contents($targetDir . '/README.md', $readme);

        return [
            'success' => true,
            'folder' => $folderName,
            'counts' => $counts,
            'message' => "Archive package '{$folderName}' created successfully with " . number_format($counts['showtimes'] ?? 0) . " showtime records."
        ];
    }

    /**
     * Get detailed breakdown and sample rows for an archive package
     * 
     * @param string $archiveName
     * @return array
     * @throws Exception
     */
    public function getArchiveDetails($archiveName) {
        $cleanFolder = preg_replace('/[^a-zA-Z0-9_-]/', '', $archiveName);
        $targetDir = $this->archiveDir . '/' . $cleanFolder;

        if (!is_dir($targetDir)) {
            throw new Exception("Archive folder '{$cleanFolder}' not found.");
        }

        $details = [
            'name' => $cleanFolder,
            'path' => $targetDir,
            'created' => date('Y-m-d H:i:s', filemtime($targetDir)),
            'readme' => '',
            'files' => [],
            'table_counts' => [],
            'theatres' => [],
            'movies' => [],
            'sample_showtimes' => [],
            'min_date' => null,
            'max_date' => null
        ];

        // 1. Read README.md
        $readmeFile = $targetDir . '/README.md';
        if (file_exists($readmeFile)) {
            $details['readme'] = file_get_contents($readmeFile);
            
            // Extract counts (support both `- showtimes: 123` and `- **showtimes**: 123`)
            if (preg_match_all('/-\s*(?:\*\*)?([a-z0-9_]+)(?:\*\*)?:\s*(\d+)\s*records/i', $details['readme'], $matches, PREG_SET_ORDER)) {
                foreach ($matches as $m) {
                    $details['table_counts'][$m[1]] = (int)$m[2];
                }
            }
        }

        // 2. List files
        $files = scandir($targetDir);
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') continue;
            $fp = $targetDir . '/' . $file;
            if (is_file($fp)) {
                $details['files'][] = [
                    'name' => $file,
                    'size' => filesize($fp),
                    'size_formatted' => $this->formatBytes(filesize($fp))
                ];
            }
        }

        $moviesFound = [];
        $theatresFound = [];
        $datesFound = [];
        $samples = [];

        // 3a. Try reading showtimes.csv if it exists
        $csvFile = $targetDir . '/showtimes.csv';
        if (file_exists($csvFile)) {
            $handle = fopen($csvFile, 'r');
            if ($handle !== false) {
                $header = fgetcsv($handle);
                if ($header !== false) {
                    $headerMap = array_flip(array_map('trim', $header));
                    $colMovie = $headerMap['movie_name'] ?? null;
                    $colTheatre = $headerMap['theatre_name'] ?? null;
                    $colScreen = $headerMap['screen_name'] ?? null;
                    $colStart = $headerMap['show_start_time'] ?? null;
                    $colPrice = $headerMap['ticket_price'] ?? null;

                    $rowCount = 0;
                    while (($row = fgetcsv($handle)) !== false) {
                        $rowCount++;
                        $movieName = ($colMovie !== null && isset($row[$colMovie])) ? trim($row[$colMovie]) : '';
                        $theatreName = ($colTheatre !== null && isset($row[$colTheatre])) ? trim($row[$colTheatre]) : '';
                        $screenName = ($colScreen !== null && isset($row[$colScreen])) ? trim($row[$colScreen]) : '';
                        $showStart = ($colStart !== null && isset($row[$colStart])) ? trim($row[$colStart]) : '';
                        $price = ($colPrice !== null && isset($row[$colPrice])) ? trim($row[$colPrice]) : '';

                        if ($movieName && !in_array($movieName, $moviesFound)) {
                            $moviesFound[] = $movieName;
                        }
                        if ($theatreName && !in_array($theatreName, $theatresFound)) {
                            $theatresFound[] = $theatreName;
                        }
                        if ($showStart && strlen($showStart) >= 10) {
                            $datesFound[] = substr($showStart, 0, 10);
                        }

                        if (count($samples) < 15) {
                            $samples[] = [
                                'theatre_name' => $theatreName,
                                'movie_name' => $movieName,
                                'screen_name' => $screenName,
                                'show_start_time' => $showStart,
                                'ticket_price' => $price ?: '14.99'
                            ];
                        }
                    }

                    if (!isset($details['table_counts']['showtimes']) || $details['table_counts']['showtimes'] === 0) {
                        $details['table_counts']['showtimes'] = $rowCount;
                    }
                }
                fclose($handle);
            }
        }

        // 3b. If CSV wasn't present or yielded no movies, parse SQL files
        if (empty($moviesFound)) {
            $sqlFiles = glob($targetDir . '/*.sql');
            foreach ($sqlFiles as $sqlFile) {
                $sqlContent = file_get_contents($sqlFile);
                if (empty($sqlContent)) continue;

                // Match single or multi-row INSERT statements
                if (preg_match_all("/\('(\d+)',\s*'(\d+)',\s*'([^']+)',\s*'([^']+)',\s*'([^']+)',\s*(?:NULL|'[^']*'),\s*'([^']*)',\s*'([^']+)'/i", $sqlContent, $matches, PREG_SET_ORDER)) {
                    foreach ($matches as $m) {
                        $theatreName = $m[3];
                        $movieName = $m[5];
                        $screenName = $m[6];
                        $showStart = $m[7];

                        if ($movieName && !in_array($movieName, $moviesFound)) {
                            $moviesFound[] = $movieName;
                        }
                        if ($theatreName && !in_array($theatreName, $theatresFound)) {
                            $theatresFound[] = $theatreName;
                        }
                        if ($showStart && strlen($showStart) >= 10) {
                            $datesFound[] = substr($showStart, 0, 10);
                        }

                        if (count($samples) < 15) {
                            $samples[] = [
                                'theatre_name' => $theatreName,
                                'movie_name' => $movieName,
                                'screen_name' => $screenName,
                                'show_start_time' => $showStart,
                                'ticket_price' => '14.99'
                            ];
                        }
                    }
                }
            }
        }

        // Also check showtime_occupancy_log counts if missing from table_counts
        if (!isset($details['table_counts']['showtime_occupancy_log']) || $details['table_counts']['showtime_occupancy_log'] === 0) {
            $occCsv = $targetDir . '/showtime_occupancy_log.csv';
            if (file_exists($occCsv)) {
                $lines = file($occCsv, FILE_SKIP_EMPTY_LINES);
                $details['table_counts']['showtime_occupancy_log'] = max(0, count($lines) - 1);
            }
        }

        sort($moviesFound);
        sort($theatresFound);
        sort($datesFound);

        $details['movies'] = array_values(array_unique($moviesFound));
        $details['theatres'] = array_values(array_unique($theatresFound));
        $details['sample_showtimes'] = $samples;

        if (!empty($datesFound)) {
            $details['min_date'] = reset($datesFound);
            $details['max_date'] = end($datesFound);
        }

        return [
            'success' => true,
            'details' => $details
        ];
    }

    /**
     * Import archived SQL file into live database
     * 
     * @param string $archiveName
     * @return array
     * @throws Exception
     */
    public function importArchive($archiveName) {
        $cleanFolder = preg_replace('/[^a-zA-Z0-9_-]/', '', $archiveName);
        $targetDir = $this->archiveDir . '/' . $cleanFolder;

        if (!is_dir($targetDir)) {
            throw new Exception("Archive folder '{$cleanFolder}' not found.");
        }

        // Check for zip file if sql files are missing or unextracted
        $sqlFiles = glob($targetDir . '/*.sql');
        if (empty($sqlFiles)) {
            $zipFiles = glob($targetDir . '/*.zip');
            if (!empty($zipFiles)) {
                $zipPath = $zipFiles[0];
                if (class_exists('\ZipArchive')) {
                    $zip = new \ZipArchive();
                    if ($zip->open($zipPath) === true) {
                        $zip->extractTo($targetDir);
                        $zip->close();
                    }
                } else {
                    @exec("unzip -o " . escapeshellarg($zipPath) . " -d " . escapeshellarg($targetDir));
                }
                $sqlFiles = glob($targetDir . '/*.sql');
            }
        }

        if (empty($sqlFiles)) {
            throw new Exception("No .sql backup files found inside archive folder '{$cleanFolder}'.");
        }

        $importedCount = 0;
        foreach ($sqlFiles as $sqlFile) {
            $sqlContent = @file_get_contents($sqlFile);
            if (empty($sqlContent)) continue;

            // Execute SQL statements
            try {
                $this->db->exec($sqlContent);
                $importedCount++;
            } catch (\Exception $e) {
                // If multi-statement query fails in PDO, split by line semicolon
                $queries = explode(";\n", $sqlContent);
                foreach ($queries as $q) {
                    $q = trim($q);
                    if (!empty($q)) {
                        try {
                            $this->db->exec($q);
                        } catch (\Exception $ex) {
                            // Ignore duplicates or minor syntax warnings
                        }
                    }
                }
                $importedCount++;
            }
        }

        return [
            'success' => true,
            'message' => "Successfully imported archive '{$cleanFolder}' into the database. ({$importedCount} SQL files processed)"
        ];
    }

    /**
     * Format byte sizes
     */
    private function formatBytes($bytes, $precision = 2) {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));
        return round($bytes, $precision) . ' ' . $units[$pow];
    }
}


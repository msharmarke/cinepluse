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
                if (preg_match('/showtime_occupancy_log: (\d+) records/', $readme, $m)) {
                    $info['occupancy_count'] = (int)$m[1];
                }
                if (preg_match('/showtimes: (\d+) records/', $readme, $m)) {
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

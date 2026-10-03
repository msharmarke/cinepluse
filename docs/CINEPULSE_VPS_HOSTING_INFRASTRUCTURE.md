# 🖥️ Cinepulse Production VPS Hosting Infrastructure Manual

> **Standalone Technical Reference** for Host Infrastructure, Server Environment, Apache/Nginx Architecture, Cron Workers, Database Config, Security Controls, and Deployment Operations for `https://cinepluse.msharmarke.com`.

---

## 1. 🌐 Executive Host Identity & Production Metrics

| Property | Value / Configuration |
| :--- | :--- |
| **Production Domain** | `https://cinepluse.msharmarke.com` |
| **Operating System** | **Linux VPS (Debian GNU/Linux 11/12)** |
| **Web Server Stack** | **Apache 2.4.68 (Debian)** + **Nginx** Reverse Proxy & Static Asset Handler |
| **PHP Runtime** | **PHP 8.x (FPM & CLI)** |
| **PHP Extensions Required** | `pdo_sqlite`, `pdo_mysql`, `curl`, `json`, `mbstring`, `opcache` |
| **Database Engine** | **SQLite 3** (`schema.sql` stored in project root) / Optional **MySQL 8.0** (`cinepluse-mysql-1`) |
| **Admin Control Passcode** | `ms88` (Configured in `config/config.ini` under `[admin] password`) |
| **Primary Repository** | `https://github.com/msharmarke.git` (`main` branch) |

---

## 2. 🏛️ Web Server Architecture & Apache/Nginx Topology

Cinepulse operates behind a hybrid Nginx reverse-proxy and Apache 2.4 backend handling HTTP requests, extensionless rewrites, and static asset delivery.

```mermaid
flowchart TD
    A["🌐 Web Client / HTTPS Request"] -->|SSL Termination Port 443| B["🛡️ Nginx Reverse Proxy"]
    B -->|Static Assets (.js, .css, .png)| C["📂 Public Assets Directory\n(/var/www/cinepulse/public/assets/)"]
    B -->|Dynamic PHP Requests| D["⚡ Apache 2.4.68 / PHP-FPM"]
    D -->|.htaccess Rewrite Engine| E["⚙️ Core Application Controllers\n(public/index.php, public/admin/tracker.php)"]
    E -->|PDO Query| F[("💾 SQLite Database\n(schema.sql)")]
    G["⏱️ System Crontab"] -->|Every 15m| H["⚙️ CLI Telemetry Daemon\n(bin/track_occupancy.php)"]
    H -->|150ms Paced HTTP| I["📡 Cineplex Public API"]
    I -->|JSON Layout & Seats| H
    H -->|Write Snapshots| F
```

### URL Rewrite & Routing Specification (`.htaccess`)

The system enforces extensionless URLs and route fallbacks through both root [`.htaccess`](file:///c:/Users/Moe/Cinepulse/cinepluse-main/.htaccess) and [`public/.htaccess`](file:///c:/Users/Moe/Cinepulse/cinepluse-main/public/.htaccess):

```apache
# Cinepulse Production Apache URL Rewrite Engine
<IfModule mod_rewrite.c>
    Options -MultiViews
    RewriteEngine On
    RewriteBase /

    # 1. Admin Static Asset Fallback Mirror
    RewriteRule ^admin/assets/(.*)$ assets/$1 [L]

    # 2. Administrative Control Routes
    RewriteRule ^admin/login/?$ admin/login.php [L,QSA]
    RewriteRule ^admin/logout/?$ admin/logout.php [L,QSA]
    RewriteRule ^admin/dashboard/?$ admin/dashboard.php [L,QSA]
    RewriteRule ^admin/tracker/?$ admin/tracker.php [L,QSA]
    RewriteRule ^admin/scan-logs/?$ admin/tracker_scan_logs.php [L,QSA]
    RewriteRule ^admin/api/?$ admin/api.php [L,QSA]
    RewriteRule ^admin/?$ admin/dashboard.php [L,QSA]

    # 3. Public Consumer Routes
    RewriteRule ^schedule/?$ index.php [L,QSA]
    RewriteRule ^movies/?$ movies.php [L,QSA]
    RewriteRule ^planner/?$ double-feature.php [L,QSA]
    RewriteRule ^watch-party/?$ watch-party.php [L,QSA]
    RewriteRule ^export-pdf/?$ export_pdf.php [L,QSA]
    RewriteRule ^api/?$ api.php [L,QSA]

    # 4. Extensionless PHP File Routing
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteCond %{REQUEST_FILENAME}\.php -f
    RewriteRule ^([^/]+)/?$ $1.php [L,QSA]
</IfModule>
```

---

## 3. ⏱️ VPS Background Daemons & Cron Worker Pipeline

Cinepulse relies on two primary background CLI workers to maintain live seating telemetry without blocking web requests:

### 🔹 1. Seating Occupancy Telemetry Daemon (`bin/track_occupancy.php`)
- **Execution Interval**: Every 15 minutes (`*/15 * * * *`).
- **Command Line Execution**:
  ```bash
  /usr/bin/php /var/www/cinepulse/bin/track_occupancy.php >> /var/www/cinepulse/cache/cron_occupancy.log 2>&1
  ```
- **Operational Workflow**:
  1. Queries SQLite table `tracked_showtimes` for records with `status = 'active'`.
  2. For each active showtime, queries Cineplex live seat availability API.
  3. Calculates occupancy percentage:
     $$\text{Occupancy Rate (\%)} = \left(\frac{\text{Total Seats} - \text{Available Seats}}{\text{Total Seats}}\right) \times 100$$
  4. Stores log in `showtime_snapshots_history` and dumps layout JSON to `snapshots/`.
  5. Automatically transitions status to `completed` 3 hours after start time.

### 🔹 2. Weekly Theatrical Ingestion Daemon (`bin/collect_showtimes.php`)
- **Execution Interval**: Daily at 3:00 AM (`0 3 * * *`).
- **Command Line Execution**:
  ```bash
  /usr/bin/php /var/www/cinepulse/bin/collect_showtimes.php >> /var/www/cinepulse/cache/cron_schedule.log 2>&1
  ```
- **Operational Workflow**:
  - Scrapes Friday-through-Thursday schedules across flagship locations and caches data into `weekly_showtimes`.

---

## 4. ⚙️ Configuration Schema (`config/config.ini`)

Production settings are managed via [`config/config.ini`](file:///c:/Users/Moe/Cinepulse/cinepluse-main/config/config.ini):

```ini
[api]
key = "dcdac5601d864addbc2675a2e96cb1f8"

[database]
host = "cinepluse-mysql-1"
name = "cinepulse_db"
user = "cinepulse_user"
pass = "cinepulse_password"

[email]
enabled = 0
smtp_from = "alerts@cinepulse.local"
smtp_from_name = "Cinepulse Alerts"

[admin]
password = "ms88"
```

---

## 5. 🔒 Security, Caching & Performance Engine

1. **OPcache Invalidation Pipeline**:
   Admin routes execute `@opcache_reset();` on each request to ensure bytecode updates take effect instantly when files change.
2. **HTTP Cache Control Headers**:
   Admin controllers emit strict no-cache headers:
   ```php
   header("Cache-Control: no-cache, no-store, must-revalidate");
   header("Pragma: no-cache");
   header("Expires: 0");
   ```
3. **API Rate Limiting & Throttling**:
   All batch API scrapers incorporate a forced `usleep(150000)` (150ms delay) between outgoing cURL requests to prevent HTTP 429 rate limits from Cineplex servers.
4. **CSRF Protection**:
   All state-changing AJAX requests require token validation via `Security::verifyCsrfOrDie()`.

---

## 6. 🚀 Server Deployment & Git Synchronization

### Production Deployment Script (`deploy.sh`)

```bash
#!/bin/bash
# Cinepulse Production VPS Deployment Pipeline
set -e

echo "🚀 Starting Cinepulse deployment pipeline..."

if [ ! -d "public" ]; then
    echo "❌ Error: Please run deploy.sh from project root."
    exit 1
fi

# Ensure storage directories exist
mkdir -p cache snapshots bin archives

# Set file permissions for web server (www-data / nginx / apache)
chmod 755 cache snapshots archives

# Verify configuration
if [ ! -f "config/config.ini" ]; then
    cp config/config.ini.example config/config.ini
fi

# Purge expired API cache
php -r "require 'src/Autoloader.php'; Cinepulse\TrackerService::purgeExpiredCache();"

echo "🎉 Deployment complete!"
```

### 1-Click Instant Server Sync Endpoint (`public/sync.php`)
Navigating to `https://cinepluse.msharmarke.com/sync.php` executes:
1. `git pull origin main` directly on the server filesystem.
2. `opcache_reset()` to clear compiled byte-code cache.

---

## 🛠️ VPS Maintenance & Operations Cheat Sheet

### 1. View Live Cron Telemetry Logs
```bash
tail -f /var/www/cinepulse/cache/cron_occupancy.log
```

### 2. Manual Git Sync via SSH
```bash
cd /var/www/cinepulse
git pull origin main
./deploy.sh
```

### 3. Check Webserver & PHP FPM Status
```bash
systemctl status apache2
systemctl status nginx
systemctl status php8.1-fpm # or php8.2-fpm
```

### 4. Fix Storage Permissions
```bash
chown -R www-data:www-data /var/www/cinepulse
chmod -R 755 /var/www/cinepulse/cache /var/www/cinepulse/snapshots
```

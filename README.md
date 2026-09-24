# Cinepulse — Modern Modular Cineplex Companion & Occupancy Tracker

Cinepulse is a clean, developer-friendly PHP application designed to interface with the Cineplex API. It allows users to browse showtimes, match back-to-back double feature layout combinations, and schedule trackers to monitor and chart auditorium seating occupancy over time.

---

## 🏗️ Folder Structure

```
cinepluse/
├── bin/                       # Command Line Cron scripts
│   ├── collect_showtimes.php  # Pre-caches theatrical schedules weekly
│   └── track_occupancy.php    # Polling daemon for seats snapshots
│
├── config/                    # Configurations folder
│   ├── config.ini.example     # Database & credentials template
│   └── locations.json         # Unified list of tracked locations
│
├── public/                    # Web-exposed Document Root
│   ├── index.php              # Showtimes search & live seat maps
│   ├── double-feature.php     # Proximity layover scheduler
│   ├── tracker.php            # Occupancy trends dashboard & scrubber
│   ├── api.php                # Dispatcher for AJAX callbacks
│   └── assets/                # Styling and script assets
│
├── src/                       # Backend Logic (Namespaced classes)
│   ├── Autoloader.php         # Custom PSR-4 autoloader
│   ├── Database.php           # PDO connection Singleton
│   ├── Security.php           # Sanitization and CSRF guards
│   ├── CineplexAPI.php        # Network client (with caching)
│   ├── ShowtimeService.php    # Groupers, sorters & compatibility rules
│   └── TrackerService.php     # Monitor registries & snapshot files
│
├── cache/                     # Temporary JSON API Cache responses (git-ignored)
├── snapshots/                 # Monitored flat seatmap snapshot files (git-ignored)
├── schema.sql                 # Combined MySQL table scripts
├── README.md                  # Quickstart guide (This document)
└── DEVELOPER.md               # Code structures & development reference
```

---

## 🚀 Quickstart Deployment Guide

### 1. Database Setup
Create a new MySQL database (e.g. `cinepulse_db`) and run the setup queries inside [schema.sql](file:///c:/Users/Moe/movies/cinepluse/schema.sql) to initialize the tables:
```bash
mysql -u your_user -p cinepulse_db < schema.sql
```

### 2. Configuration Setup
Copy [config.ini.example](file:///c:/Users/Moe/movies/cinepluse/config/config.ini.example) to `config.ini`:
```bash
cp config/config.ini.example config/config.ini
```
Edit the database connection parameters and paste your Cineplex subscription API key under `[api]`:
```ini
[api]
key = "YOUR_CINEPLEX_API_KEY_HERE"

[database]
host = "localhost"
name = "cinepulse_db"
user = "your_db_user"
pass = "your_db_password"
```

### 3. File Permissions
Ensure the `cache/` and `snapshots/` folders exist and are writeable by the web server user:
```bash
chmod 755 cache snapshots
```

### 4. Apache/Nginx Web Server Setup
Point your web server's document root to the `public/` directory, rather than the root directory, so that configurations, source files, and CLI binaries are safely unexposed from public URL routes.

---

## ⏰ Cron Jobs Setup
Add the following tasks to your server's crontab (`crontab -e`) to automate pre-caching and background logging:

```crontab
# 1. Pre-cache theatrical schedules every Monday at 2 AM
0 2 * * 1 php /path/to/cinepluse/bin/collect_showtimes.php >> /path/to/cinepluse/bin/collect_showtimes.log 2>&1

# 2. Run occupancy tracker checks and snapshot logs every 15 minutes
*/15 * * * * php /path/to/cinepluse/bin/track_occupancy.php >> /path/to/cinepluse/bin/track_occupancy.log 2>&1
```

# 🎬 Cinepulse — Modern Modular Cineplex Companion & Seating Occupancy Analytics

[![PHP Version](https://img.shields.io/badge/PHP-8.1%2B-blue.svg)](https://www.php.net/)
[![License](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)
[![Production Status](https://img.shields.io/badge/Production-Live-success.svg)](https://cinepluse.msharmarke.com/)

**Cinepulse** is a modern, modular PHP application designed to interface with the Cineplex API (`apis.cineplex.com`). It allows users and developers to browse theatrical showtimes, match back-to-back double feature layout combinations, chart auditorium seating occupancy over time, and manage automated release tracking with system analytics.

🌐 **Live Production Deployment**: [https://cinepluse.msharmarke.com](https://cinepluse.msharmarke.com/)  
📊 **Live System Analytics Dashboard**: [https://cinepluse.msharmarke.com/dashboard.php](https://cinepluse.msharmarke.com/dashboard.php)

---

## ✨ Core Features

* **🍿 Theatrical Week Schedule Browser**: Pre-caches and displays theatrical showtimes for Friday-to-Thursday release cycles across all tracked Cineplex theaters.
* **📊 Analytics Dashboard**: Real-time daemon status indicators, Chart.js trend graphs (daily occupancy & top movies), estimated ticket revenue calculator (`ticket_price * seats_occupied`), terminal execution log scrubber, and CSV export portal.
* **📈 Live Interactive Seat Maps**: Visual seating availability maps per auditorium session (occupied, available, broken, total layout capacity).
* **⚡ Double Feature Scheduler**: Smart layover planner computing proximity gaps between movies, with warnings for tight breaks (<10m), long waits (>120m), or auditorium transfers.
* **🤖 Automated Release Tracking**: Auto-registers upcoming showtimes matching user-configured movie pattern rules and experience filters (IMAX, VIP, UltraAVX, D-BOX, 3D).
* **📦 Historical Archive Engine**: Browse, inspect, and import historical showtimes and occupancy logs across 22+ archived periods (from 2025 to present).

---

## 🏗️ Project Structure

```
cinepluse/
├── bin/                          # CLI Cron Scripts & Daemons
│   ├── collect_showtimes.php     # Pre-caches theatrical week schedules (Friday -> Thursday)
│   └── track_occupancy.php       # 15-minute seating snapshot daemon & alert dispatcher
│
├── config/                       # Application Configuration
│   ├── config.ini.example        # Database & credentials template
│   └── locations.json            # Unified list of tracked Cineplex theaters
│
├── public/                       # Web Document Root (Publicly Exposed)
│   ├── index.php                 # Showtime browser & live interactive seat map viewer
│   ├── dashboard.php             # Analytics dashboard, daemon status & CSV export
│   ├── tracker.php               # Occupancy monitors dashboard & snapshot scrubber
│   ├── double-feature.php        # Double-feature layover matcher & gap calculator
│   ├── movies.php                # Global playing movies directory
│   ├── tracker_scan_logs.php     # Scraper execution audit log viewer
│   ├── watch-party.php           # Group movie planning interface
│   ├── api.php                   # Central AJAX JSON API dispatcher
│   └── assets/                   # CSS (design system, themes) & JavaScript modules
│
├── src/                          # Backend PSR-4 Core Logic (`namespace Cinepulse`)
│   ├── Autoloader.php            # PSR-4 dynamic class loader
│   ├── Database.php              # Singleton PDO wrapper (America/Toronto timezone)
│   ├── Security.php              # CSRF, XSS, and input sanitization helpers
│   ├── CineplexAPI.php           # Network client with 30-min file caching & retries
│   ├── ShowtimeService.php       # Double-feature gap logic & experience groupers
│   ├── TrackerService.php        # Snapshot manager & flat-file seatmap persistence
│   ├── DashboardService.php      # Metrics aggregation, chart datasets & CSV exporter
│   └── ArchiveService.php        # Historical archive scanner and database importer
│
├── archives/                     # Historical archive packages (SQL & CSV backups)
├── snapshots/                    # Flat seatmap JSON snapshot files (git-ignored)
├── cache/                        # API response JSON caches (git-ignored)
├── deploy.sh                     # Production VPS deployment helper script
├── schema.sql                    # MySQL schema initialization script
├── README.md                     # Application guide (This document)
└── DEVELOPER.md                  # Developer reference & extension manual
```

---

## 🚀 Quickstart Deployment Guide

### 1. Database Setup
Create a new MySQL database (e.g. `cinepulse_db`) and import `schema.sql`:
```bash
mysql -u cinepulse_user -p cinepulse_db < schema.sql
```

### 2. Configuration Setup
Copy `config/config.ini.example` to `config/config.ini`:
```bash
cp config/config.ini.example config/config.ini
```
Edit `config/config.ini` with your database parameters and Cineplex API key:
```ini
[api]
key = "YOUR_CINEPLEX_API_KEY_HERE"

[database]
host = "localhost"
name = "cinepulse_db"
user = "cinepulse_user"
pass = "your_password"
```

### 3. File Permissions
Ensure `cache/` and `snapshots/` exist and are writeable by the web server:
```bash
chmod 755 cache snapshots
```

### 4. Web Server Configuration (Nginx / Apache)
> ⚠️ **CRITICAL**: Point the web server's **Document Root** to the `/public` directory, NOT the root directory. This shields source files, configurations, and CLI scripts from public URL access.

---

## ⏰ Cron Jobs Automation

Add the following background daemons to your server's crontab (`crontab -e`):

```crontab
# 1. Pre-cache theatrical schedules every Monday at 2 AM
0 2 * * 1 php /path/to/cinepulse/bin/collect_showtimes.php >> /path/to/cinepulse/bin/collect_showtimes.log 2>&1

# 2. Run occupancy tracker checks and snapshot logs every 15 minutes
*/15 * * * * php /path/to/cinepulse/bin/track_occupancy.php >> /path/to/cinepulse/bin/track_occupancy.log 2>&1
```

---

## 📄 License & Maintainers
Developed for movie enthusiasts and Cineplex schedule tracking. Maintained by **Mohamed Sharmarke** (`msharmarke@actra.ca`).

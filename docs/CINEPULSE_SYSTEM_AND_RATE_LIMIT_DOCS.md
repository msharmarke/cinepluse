# 📊 Cinepulse Telemetry Engine & Cineplex API Rate Limit Documentation

## 1. System Overview & Architecture

Cinepulse measures and analyzes theatrical ticket sales, seat availability, and audience demand trends across Cineplex cinemas in Canada.

### ⏱️ The 15-Minute Telemetry Cycle
Occupancy tracking operates on a **15-minute polling interval**:
1. **Schedule Ingestion**: High-level showtime schedules for the theatrical week (Friday through Thursday) are pre-cached in SQLite (`weekly_showtimes` table).
2. **Active Monitor Queue**: Shows elevated to active monitoring (either manually by administrators via the dashboard or automatically by watchlist triggers) are tracked in the `tracked_showtimes` table.
3. **Automated Seating Telemetry**: Every 15 minutes, the background daemon (`bin/track_occupancy.php` or `/api?action=trigger_snapshot`) iterates through all `active` showtimes and requests live seating availability.
4. **Snapshot Storage**: Timestamped seating snapshots are appended to the `snapshots` table:
   $$\text{Occupancy Rate (\%)} = \left(\frac{\text{Total Seats} - \text{Available Seats}}{\text{Total Seats}}\right) \times 100$$
5. **Cache Retention & Lifecycle**: When a showtime concludes (3 hours after start time), its status transitions to `completed`, halting further API polling. Stale cache files are automatically garbage collected.

---

## 2. 🏛️ Theater Trimming & Location Controls

### 💡 Why Trim Active Theaters?
Scanning all 41 default Cineplex locations across Canada every 15 minutes generates unnecessary API load for secondary theaters. Trimming active telemetry to key flagship locations reduces API request volume by **~75%**, ensuring high data freshness for major metro markets while staying well under API rate limits.

### 🏛️ Flagship Active Locations (Default Telemetry Scope)

| Theater Name | ID | City / Province | Region Tag | Screen Formats | Default Status |
| :--- | :--- | :--- | :--- | :--- | :--- |
| **Scotiabank Theatre Toronto** | `7402` | Toronto, ON | GTA Flagship | 🍿 **70mm IMAX Laser**, AVX, VIP, DBOX | 🟢 **ACTIVE** |
| **Vaughan (Colossus)** | `7408` | Vaughan, ON | GTA Flagship | 🍿 **70mm IMAX GT**, AVX | 🟢 **ACTIVE** |
| **Courtney Park** | `7122` | Mississauga, ON | West GTA | 🍿 **70mm IMAX GT**, AVX | 🟢 **ACTIVE** |
| **Yonge-Dundas** | `7130` | Toronto, ON | Downtown Toronto | IMAX, 4DX, VIP | 🟢 **ACTIVE** |
| **Yorkdale** | `7406` | Toronto, ON | GTA | AVX, VIP | 🟢 **ACTIVE** |
| **Scotiabank Montreal** | `9406` | Montreal, QC | Montreal Metro | IMAX GT Laser, AVX, VIP | 🟢 **ACTIVE** |
| **Forum** | `9109` | Montreal, QC | Montreal Metro | AVX, VIP | 🟢 **ACTIVE** |
| **Scotiabank Vancouver** | `1422` | Vancouver, BC | Metro Vancouver | IMAX, AVX, VIP | 🟢 **ACTIVE** |
| **Langley** | `1404` | Langley, BC | Metro Vancouver | 🍿 **70mm IMAX GT**, AVX | 🟢 **ACTIVE** |
| **Chinook** | `3401` | Calgary, AB | Calgary Metro | 🍿 **70mm IMAX Laser**, 4DX, AVX | 🟢 **ACTIVE** |

*Note: All other 32 secondary locations remain stored in `config/locations.json` and can be toggled on/off at any time via the Admin Dashboard's **Theater Controls Center**.*

---

## 3. 🌐 Cineplex API Endpoint & Rate Limit Specification

### 📡 Public Ticketing Endpoints Used

| Endpoint Purpose | Target URL Pattern | HTTP Method | Response Format |
| :--- | :--- | :--- | :--- |
| **Daily Showtime Schedule** | `https://apis.cineplex.com/prod/ticketing/api/v1/theatre/{theatre_id}/showtimes?date={mm+dd+yyyy}` | GET | JSON |
| **Auditorium Seat Layout** | `https://apis.cineplex.com/prod/ticketing/api/v1/theatre/{theatre_id}/showtime/{showtime_id}/seat-layout` | GET | JSON |
| **Live Seat Availability** | `https://apis.cineplex.com/prod/ticketing/api/v1/theatre/{theatre_id}/showtime/{showtime_id}/seat-availability` | GET | JSON |

---

### 📈 API Request Volume & Throughput Calculations

#### A. 15-Minute Occupancy Polling Cycle (Active Trackers)
* **Active Monitored Showtimes**: ~15–30 concurrent sessions during peak operating hours (noon to midnight).
* **Endpoints Called per Session**: 1 (`/seat-availability`) or 2 (`/seat-layout` if un-cached).
* **Request Rate**: ~15–30 requests per 15-minute window = **1 to 2 requests per minute**.

#### B. Weekly Schedule Scraper (`scrapeFullTheatricalWeek`)
* **Active Monitored Theaters**: 9 flagship locations.
* **Theatrical Week Duration**: 7 days (Friday through Thursday).
* **Total Scraping Requests**: 9 theaters × 7 days = **63 GET requests**.
* **Pacing Delay**: Enforced `usleep(150000)` (150ms delay between calls).
* **Total Scraping Runtime**: ~10–12 seconds total per full weekly scrape.

---

### 🛡️ Rate Limit Rules & Throttling Guidelines

1. **Request Inter-Arrival Delay**:
   - Maintain a minimum delay of **100ms – 150ms** between consecutive outgoing HTTP requests in batch loops (`usleep(150000)`).
2. **Concurrent Request Cap**:
   - Avoid parallel async HTTP bursts > 5 concurrent sockets to preventing triggering Cloudflare / Edge WAF IP rate limiting.
3. **HTTP User-Agent & Header Rotation**:
   - Outgoing requests include standard web user-agent headers (`Mozilla/5.0...`) and `Accept: application/json`.
4. **Caching & Conditional Headers**:
   - Static seating layouts (`/seat-layout`) change rarely and are cached locally in `cache/` with a 7-day TTL.
   - Live availability (`/seat-availability`) is cached with a **60-second minimum TTL** to prevent duplicate burst queries.
5. **Handling Rate Limit & Gateway Errors**:
   - **`429 Too Many Requests`**: Implement exponential backoff ($2^n$ seconds delay, up to 3 retries).
   - **`502 / 503 Bad Gateway`**: Retry up to 2 times with a 1-second pause.

---

## 4. 🛠️ Admin Dashboard Controls Reference

* **Theater Control Center**: Allows administrators to inspect city, province, region, screen formats, and toggle active telemetry (`action=toggle_theatre`).
* **Weekly Schedule Explorer**: Filter showtimes by active theater, date, and search terms.
* **Manual Snapshot Trigger**: Instantly trigger occupancy capture via `action=trigger_snapshot`.
* **Scan Audit Logs**: Review background daemon execution details at `/admin/scan-logs`.

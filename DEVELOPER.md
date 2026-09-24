# Cinepulse — Developer Reference & Extension Manual

Welcome to the Cinepulse development manual. This guide outlines the architectural design patterns, namespaces, class dependencies, and hooks available to extend Cinepulse.

---

## 🏛️ Class Autoloading & Namespaces

Cinepulse uses a lightweight, standard PSR-4 conforming class autoloader located in [Autoloader.php](file:///c:/Users/Moe/movies/cinepluse/src/Autoloader.php).
- The root namespace is `Cinepulse`.
- All source files are mapped to the `/src/` folder.
- Do NOT use manual `require_once` statements for classes in `src/`. Simply declare `use Cinepulse\ClassName;` and the autoloader will import the class on its first instantiation.

---

## 📦 Service Definitions

### 1. Database Client (`Cinepulse\Database`)
- **Pattern**: Singleton.
- **Responsibility**: Establishes PDO MySQL connection once per request.
- **Timezone Safety**: Forces `SET time_zone = '-05:00'` during PDO connection initialization to align calculations with the `America/Toronto` timezone.
- **Usage**:
  ```php
  use Cinepulse\Database;
  $pdo = Database::getInstance()->getConnection();
  ```

### 2. Security Wrapper (`Cinepulse\Security`)
- **Responsibility**: CSRF token generators, verification filters, and input sanitization helpers.
- **Usage**:
  ```php
  use Cinepulse\Security;
  $clean_id = Security::sanitizeInput($_GET['id'], 'int');
  Security::verifyCsrfOrDie(); // Rejects request with HTTP 403 on validation failure
  ```

### 3. API Handler (`Cinepulse\CineplexAPI`)
- **Responsibility**: Handles cURL request wrappers, Ocp-Apim subscription header assignments, and automatic exponential backoff retries.
- **Cache Mechanism**: Showtime listings responses are saved in the `/cache/` folder as flat files. Cache expiration is controlled by a Time-To-Live (TTL) variable (default: 30 minutes).
- **Security Check**: Employs Server-Side Request Forgery (SSRF) boundary filters, validating that all target endpoints map only to `apis.cineplex.com`.

### 4. Showtime Service (`Cinepulse\ShowtimeService`)
- **Responsibility**: Sorting algorithms, experience formatting groupers, URL slugs mapping, and double feature proximity checks.
- **Double Feature Proximity**: Pairs movie sessions and checks overlap boundaries:
  - Session 1 Start Time + Session 1 Runtime < Session 2 Start Time.
  - Generates notifications for tight breaks (under 10 minutes) or long delays (over 120 minutes).
  - Inspects auditorium label strings (e.g. Auditorium 2 vs 8) and issues proximity alerts.

### 5. Tracker Service (`Cinepulse\TrackerService`)
- **Responsibility**: Managing active showtime registries, loading flat layout files, and cleaning expired cache files.
- **Mitigation of DB Bloat**: Layout grids coordinates and seat maps coordinates are stored inside individual static `.json` files under the `/snapshots/` directory, rather than in relational DB records. Only numeric counts and filename keys are stored in `showtime_snapshots_history`.
- **Cache Cleanups**: Implements the garbage collection method `purgeExpiredCache()` which deletes all cache records older than 24 hours.

---

## 🔧 Extending the Alert Pipeline

If you want to add new occupancy notification alerts (such as SMS notifications via Twilio, Discord webhooks, or Telegram bots), you can easily hooks into the `checkAndSendAlerts()` function inside [track_occupancy.php](file:///c:/Users/Moe/movies/cinepluse/bin/track_occupancy.php):

```php
// Example: Adding a Discord Alert Hook inside track_occupancy.php
function sendDiscordWebhook($message) {
    $webhookUrl = "YOUR_DISCORD_WEBHOOK_URL";
    $payload = json_encode(['content' => $message]);
    
    $ch = curl_init($webhookUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_exec($ch);
    curl_close($ch);
}
```

---

## 🎨 Theme System Modifications

Themes are styled globally inside [themes.css](file:///c:/Users/Moe/movies/cinepluse/public/assets/css/themes.css) using CSS Custom Properties (variables). To add a custom theme palette, append your selectors to the file:

```css
/* Example: Custom Golden Luxury Theme */
html.theme-luxury {
    --theme-primary: #d4af37;      /* Gold */
    --theme-secondary: #aa7c11;    /* Dark Gold */
    --bg-primary: #121212;         /* Dark background */
    --border-light: #2a2a2a;       /* Dark borders */
}
```
You can bind this class selector using the visual interface switcher.

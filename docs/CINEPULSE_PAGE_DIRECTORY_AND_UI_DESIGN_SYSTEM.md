# 🎨 Cinepulse Page Directory, Asset Wiring & Design System Specification

> **Master Architecture & UI Reference** documenting all public consumer and admin pages, CSS stylesheets, layout components, route rewrites, and unified design system tokens for `https://cinepluse.msharmarke.com`.

---

## 1. 📂 Complete Page Directory & Route Mapping

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                          🌐 CINEPULSE PAGE ROSTER                           │
├──────────────────┬──────────────────────┬───────────────────────────────────┤
│ Route Path       │ Source File          │ Purpose / Feature Description     │
├──────────────────┼──────────────────────┼───────────────────────────────────┤
│ `/schedule`      │ `public/index.php`   │ Primary 3D Showtime & Seat Browser│
│ `/velocity`      │ `public/velocity.php`│ Real-Time Occupancy Velocity Engine│
│ `/passport`      │ `public/passport.php`│ User Profile, Stamps & Sign-Out   │
│ `/movies`        │ `public/movies.php`  │ Theatrical Movie Roster & Catalog │
│ `/planner`       │ `double-feature.php` │ Double Feature Planner Tool       │
│ `/watch-party`   │ `watch-party.php`    │ Watch Party Event Creator         │
│ `/admin/dashboard`│ `admin/dashboard.php`│ Admin Telemetry & System Controls │
│ `/admin/tracker` │ `admin/tracker.php`  │ Active Showtimes Monitor List     │
│ `/api`           │ `public/api.php`     │ Central REST JSON API Dispatcher  │
└──────────────────┴──────────────────────┴───────────────────────────────────┘
```

---

## 2. 🎨 Unified Design System Tokens & Asset Dependencies

### Primary Asset Dependencies
1. **Stylesheet**: `/assets/css/style.css` (Contains dark mode tokens, glassmorphism utilities, card grids, KPI pills, and responsive layout classes).
2. **Google Font**: `Outfit` (`family=Outfit:wght@400;500;600;700;800`)
3. **Primary Color Palette**:
   * **Background**: Dark Navy (`#0b0e14` / `#111827`)
   * **Card Background**: Glassmorphic Dark (`#1f2937` / `rgba(255,255,255,0.04)`)
   * **Accent Fire (Velocity)**: `#FF4500` / `#f59e0b`
   * **Accent Gold (Passport/Badges)**: `#FFD700` / `#fbbf24`
   * **Accent Blue (Schedule)**: `#3b82f6` / `#60a5fa`
   * **Danger / Logout**: `#ef4444` / `#fca5a5`

---

## 3. 🧩 Sidebar Navigation Architecture

All pages embed the standardized sidebar layout:

```html
<aside class="sidebar">
    <div class="sidebar-header">
        <h1>🎬 Cinepulse</h1>
        <p>Command Center Analytics</p>
    </div>
    <nav class="sidebar-nav">
        <a href="/schedule">📅 Schedule</a>
        <a href="/velocity">🔥 Velocity</a>
        <a href="/passport">🏅 Profile & Passport</a>
        <a href="/movies">🎬 Movies</a>
        <a href="/admin/dashboard">📊 Dashboard</a>
    </nav>
    <div style="padding: 1rem 1.5rem; margin-top: auto; display: flex; flex-direction: column; gap: 0.75rem;">
        <!-- Authenticated User Profile or Sign-In Button -->
        <button id="openThemeModal" class="btn-dash btn-dash-secondary">🎨 Theme Options</button>
    </div>
</aside>
```

---

## 4. 🔑 Google OAuth 2.0 Auth Routing Matrix

| Trigger | Endpoint | Action Executed | Next Destination |
| :--- | :--- | :--- | :--- |
| **Sign in Click** | `/api?action=login_google` | Initiates Google OAuth 2.0 OpenID Connect redirect | `accounts.google.com` |
| **Google Callback** | `/api?action=auth_google_callback` | Exchanges code, upserts user in PostgreSQL, sets JWT cookie | `/passport` |
| **Sign Out Click** | `/passport?action=logout` | Clears `cinepulse_session` cookie & destroys PHP session | `/schedule` |
| **User Profile Fetch** | `/api?action=get_current_user` | Decodes session cookie, returns user JSON | Client UI |

---

## 🎯 Verification Checklist for Page Consistency

- [x] All pages include `/assets/css/style.css` (No 404 broken CSS references).
- [x] All pages use `Outfit` font family.
- [x] Sidebar navigation contains identical links for 3 Core Pillars (`/schedule`, `/velocity`, `/passport`).
- [x] Google Auth login / user profile pill rendered in sidebar across all views.
- [x] Extensionless URL rewrite rules configured in both root `.htaccess` and `public/.htaccess`.

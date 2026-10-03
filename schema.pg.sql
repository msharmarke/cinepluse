-- Cinepulse PostgreSQL Master Database Schema
-- Optimized for high-throughput concurrency, JSONB passports, Google OAuth 2.0, and Velocity tracking

-- 1. Users Table (Google OAuth 2.0 Only)
CREATE TABLE IF NOT EXISTS users (
    id SERIAL PRIMARY KEY,
    google_id VARCHAR(120) UNIQUE NOT NULL,
    email VARCHAR(255) NOT NULL,
    display_name VARCHAR(100) NOT NULL,
    avatar_url TEXT,
    role VARCHAR(20) DEFAULT 'member',
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    last_login_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_users_google_id ON users(google_id);
CREATE INDEX IF NOT EXISTS idx_users_email ON users(email);

-- 2. Cinepulse User Passport & Stats
CREATE TABLE IF NOT EXISTS user_passports (
    user_id INT PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
    favorite_theatre_id INT DEFAULT 7402,
    favorite_theatre_name VARCHAR(255) DEFAULT 'Scotiabank Theatre Toronto',
    bio VARCHAR(250),
    badges JSONB DEFAULT '["imax_pioneer"]'::jsonb,
    movies_watched_count INT DEFAULT 0,
    total_showtimes_tracked INT DEFAULT 0,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- 3. Passport Movie Stamps (Logged Movies / Ticket Check-Ins)
CREATE TABLE IF NOT EXISTS passport_stamps (
    id SERIAL PRIMARY KEY,
    user_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    movie_title VARCHAR(255) NOT NULL,
    theatre_id INT DEFAULT 7402,
    theatre_name VARCHAR(255) NOT NULL,
    screening_date DATE NOT NULL,
    screening_time VARCHAR(20),
    format_type VARCHAR(50) DEFAULT 'IMAX 70mm', -- 'IMAX 70mm', 'UltraAVX', 'VIP', 'Standard'
    seat_label VARCHAR(20),                      -- e.g. 'Row G, Seat 14'
    rating NUMERIC(2,1) DEFAULT 5.0,            -- e.g. 5.0
    notes TEXT,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_passport_stamps_user ON passport_stamps(user_id);
CREATE INDEX IF NOT EXISTS idx_passport_stamps_date ON passport_stamps(screening_date DESC);

-- 4. Showtime Occupancy & Velocity Tracking Engine
CREATE TABLE IF NOT EXISTS showtime_velocity (
    showtime_id VARCHAR(100) PRIMARY KEY,
    movie_title VARCHAR(255) NOT NULL,
    theatre_id INT NOT NULL,
    theatre_name VARCHAR(255) NOT NULL,
    showtime_start TIMESTAMP WITH TIME ZONE NOT NULL,
    total_seats INT NOT NULL,
    available_seats INT NOT NULL,
    occupancy_pct NUMERIC(5,2) NOT NULL,
    fill_rate_seats_per_hour NUMERIC(6,2) DEFAULT 0.00,
    velocity_status VARCHAR(50) DEFAULT 'normal', -- 'selling_fast', 'nearly_full', 'sold_out'
    last_updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_velocity_rate ON showtime_velocity(fill_rate_seats_per_hour DESC);
CREATE INDEX IF NOT EXISTS idx_velocity_pct ON showtime_velocity(occupancy_pct DESC);
CREATE INDEX IF NOT EXISTS idx_velocity_theatre ON showtime_velocity(theatre_id);

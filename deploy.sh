#!/bin/bash
# Cinepulse Production VPS Deployment Script
# Usage: ./deploy.sh

set -e

echo "🚀 Starting Cinepulse deployment pipeline..."

# 1. Check permissions
if [ ! -d "public" ]; then
    echo "❌ Error: Please run deploy.sh from the project root directory."
    exit 1
fi

# 2. Ensure directories exist
mkdir -p cache snapshots bin

# 3. Set file permissions for web server (www-data / nginx / apache)
chmod 755 cache snapshots
echo "✅ Directory permissions updated for cache/ and snapshots/."

# 4. Check configuration
if [ ! -f "config/config.ini" ]; then
    echo "⚠️ Warning: config/config.ini missing. Copying from config.ini.example..."
    cp config/config.ini.example config/config.ini
    echo "➡️ Please edit config/config.ini with your live production database credentials."
fi

# 5. Purge stale cache files
echo "🧹 Purging expired API cache files..."
php -r "require 'src/Autoloader.php'; Cinepulse\TrackerService::purgeExpiredCache();"

echo "🎉 Deployment complete! Ensure Web Server Document Root points to /path/to/cinepulse/public"

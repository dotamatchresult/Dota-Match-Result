# Server Setup Guide

This guide provides step-by-step instructions for deploying the DotA 2 Match Result Notification System on a production server.

## Server Requirements

### PHP Requirements
- **PHP Version**: 8.2 or higher
- **Required PHP Extensions**:
  - `pdo` and `pdo_mysql`
  - `mbstring`
  - `xml`
  - `curl` or `openssl`
  - `json`
  - `fileinfo`
  - `tokenizer`
  - `bcmath`
  - `ctype`

### Database
- MySQL 8.0+ or MariaDB 10.3+
- PostgreSQL 12+ (alternative)

### Web Server
- Nginx or Apache with rewrite module enabled
- SSL certificate recommended for production

### Network Access
The server requires outbound HTTPS access to:
- `api.steampowered.com` (Steam API)
- `api.opendota.com` (OpenDota API)
- `api.fonnte.com` (WhatsApp API)
- `api.telegram.org` (Telegram Bot API)

### Additional Software
- Composer 2.0+
- Node.js 18+ and npm (for asset compilation)
- Cron (for scheduled tasks and queue processing)

---

## Installation Steps

### 1. Clone Repository

```bash
cd /var/www
git clone <repository-url> dota-match-result
cd dota-match-result
```

### 2. Install Dependencies

```bash
# Install PHP dependencies
composer install --optimize-autoloader --no-dev

# Install Node.js dependencies and build assets
npm install
npm run build
```

### 3. Configure Environment

```bash
# Copy environment file
cp .env.example .env

# Generate application key
php artisan key:generate
```

Edit `.env` file and configure the following:

```env
# Application
APP_NAME="DotA Match Notifier"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-domain.com
APP_LOCALE=id
APP_TIMEZONE=Asia/Jakarta

# Database
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=dota_match_result
DB_USERNAME=your_db_user
DB_PASSWORD=your_db_password

# Queue (use database driver)
QUEUE_CONNECTION=database

# Cache
CACHE_STORE=database

# Session
SESSION_DRIVER=database

# Steam API Key
# Get from: https://steamcommunity.com/dev/apikey
STEAM_API_KEY=your_steam_api_key_here

# Fonnte WhatsApp API Key
# Get from: https://fonnte.com
FONNTE_API_KEY=your_fonnte_api_key_here

# Telegram Bot Token
# Get from: @BotFather on Telegram
TELEGRAM_BOT_TOKEN=your_telegram_bot_token_here
```

### 4. Set Up Database

```bash
# Run migrations
php artisan migrate --force

# Seed initial data (creates admin user and sample members)
php artisan db:seed
```

**Default Admin Credentials** (created by seeder):
- Email: `admin@domain.com`
- Password: `secret`
- **⚠️ Change these credentials immediately after first login!**

### 5. Set File Permissions

```bash
# Set proper ownership (adjust www-data to your web server user)
chown -R www-data:www-data /var/www/dota-match-result

# Set directory permissions
chmod -R 775 storage bootstrap/cache
```

### 6. Optimize for Production

```bash
# Cache configuration
php artisan config:cache

# Cache routes
php artisan route:cache

# Cache views
php artisan view:cache
```

---

## Configure Cron Jobs

The application uses cron for both the Laravel scheduler and queue processing. Add these entries to your crontab:

```bash
# Edit crontab
crontab -e
```

Add the following lines (adjust path to your installation):

```cron
# Laravel Scheduler (runs every minute, handles 5-minute match checks)
* * * * * cd /var/www/dota-match-result && php artisan schedule:run >> /dev/null 2>&1

# Queue Worker (processes WhatsApp notification jobs)
* * * * * cd /var/www/dota-match-result && php artisan queue:work --stop-when-empty --tries=3 --max-time=50 >> /dev/null 2>&1
```

### Why `--stop-when-empty`?

- Processes all pending jobs then exits
- Cronjob automatically restarts it every minute
- No need for Supervisor or persistent worker processes
- Simpler deployment and maintenance
- Prevents memory leaks from long-running processes

---

## Configure Filament Admin Panel

### Access Admin Panel

1. Open your browser and navigate to: `https://your-domain.com/admin`

2. Log in with default credentials:
   - Email: `admin@domain.com`
   - Password: `secret`

3. **Immediately change the password** after first login

### Configure Required Settings

The application requires you to configure notification settings via the admin panel:

#### WhatsApp Configuration

1. Navigate to **Settings** in the admin panel
2. Find and edit `fonnte_phone_number`
3. Enter your WhatsApp number (e.g., `628123456789`)
   - Format: Country code + number (no + or spaces)
   - Example: Indonesia number 08123456789 becomes `628123456789`
4. Save changes

#### Telegram Configuration

1. Navigate to **Settings** in the admin panel
2. Find and edit `telegram_group_id`
3. Enter your Telegram group chat ID (e.g., `-1001234567890`)
   - Must include the negative sign for group chats
   - See "Telegram Bot Setup" section below for how to obtain this ID
4. Save changes

### Add Members to Track

1. Navigate to **Members** in the admin panel
2. Click **New Member**
3. Enter:
   - **Name**: Display name/alias (e.g., "Kudog")
   - **Steam ID**: 64-bit Steam ID (e.g., "76561198256821667")
   - **Destination**: Choose WhatsApp or Telegram (defaults to WhatsApp)
4. Save

**Finding Steam ID**:
- Visit: https://steamid.io/
- Enter Steam profile URL
- Copy the **steamID64** value

---

## Telegram Bot Setup

This section explains how to create and configure a Telegram bot for receiving match notifications.

### 1. Create a Bot with BotFather

1. Open Telegram and search for `@BotFather`
2. Start a chat and send `/newbot`
3. Follow the prompts:
   - Enter a display name for your bot (e.g., "DotA Match Notifier")
   - Enter a username ending in "bot" (e.g., "dota_match_notify_bot")
4. BotFather will provide your bot token (e.g., `123456789:ABCdefGHIjklMNOpqrsTUVwxyz`)
5. Copy this token and add it to your `.env` file as `TELEGRAM_BOT_TOKEN`

### 2. Create a Group Chat

1. Create a new Telegram group
2. Add your bot to the group:
   - Click the group name → "Add members"
   - Search for your bot username
   - Add the bot
3. **Important**: Give the bot admin rights (optional but recommended for reliability):
   - Click the group name → "Administrators"
   - Add your bot as an administrator

### 3. Get the Group Chat ID

You need the group's chat ID to configure the application. There are several methods:

#### Method 1: Using a Web API Request

1. Send a message in your group (tag the bot with `@yourbotname` to ensure it sees the message)
2. Open a browser and visit:
   ```
   https://api.telegram.org/bot<YOUR_BOT_TOKEN>/getUpdates
   ```
   Replace `<YOUR_BOT_TOKEN>` with your actual bot token
3. Look for a JSON section like:
   ```json
   "chat": {
     "id": -1001234567890,
     "title": "Your Group Name",
     "type": "supergroup"
   }
   ```
4. Copy the `id` value (including the negative sign)

#### Method 2: Using a Telegram Bot

1. Add `@userinfobot` or `@myidbot` to your group
2. These bots will automatically post the group's chat ID
3. Copy the ID (should be a negative number like `-1001234567890`)
4. You can remove these helper bots after getting the ID

#### Method 3: Using a Script

Run this in your terminal after replacing `<YOUR_BOT_TOKEN>`:

```bash
curl -s "https://api.telegram.org/bot<YOUR_BOT_TOKEN>/getUpdates" | grep -o '"chat":{"id":-[0-9]*' | grep -o '\-[0-9]*'
```

### 4. Configure the Application

1. Add the chat ID to Settings in Filament admin panel:
   - Setting key: `telegram_group_id`
   - Value: `-1001234567890` (your actual group chat ID)
2. Members can now choose "Telegram" as their destination preference
3. Match notifications will be sent to the Telegram group for members with Telegram destination

### Troubleshooting Telegram

- **Bot not receiving messages**: Ensure the bot is a member of the group
- **"Chat not found" error**: Verify the chat ID includes the negative sign
- **Bot not responding**: Check that `TELEGRAM_BOT_TOKEN` in `.env` is correct
- **Parse errors in messages**: Ensure bot uses Markdown parse mode (handled automatically)

---

## Verify Installation

### Check Application Status

```bash
# Check if cron jobs are running
grep CRON /var/log/syslog | tail -n 20

# Check application logs
tail -f storage/logs/laravel.log

# Check failed jobs
php artisan queue:failed
```

### Test Match Checking

```bash
# Manually trigger match check
php artisan matches:check

# Should output:
# Checking for new matches...
# Checking matches for [Member Name] ([Steam ID])...
# ...
```

### Monitor Queue Processing

```bash
# Check jobs table
php artisan tinker
>>> DB::table('jobs')->count();

# Check failed jobs
php artisan queue:failed
```

---

## Production Checklist

### Security
- ✅ Change default admin password (`admin@domain.com`)
- ✅ Set `APP_DEBUG=false` in `.env`
- ✅ Set `APP_ENV=production` in `.env`
- ✅ Configure firewall (allow only HTTP/HTTPS ports)
- ✅ Install SSL certificate (Let's Encrypt recommended)
- ✅ Set `.env` file permissions to 600: `chmod 600 .env`
- ✅ Keep API keys confidential (`STEAM_API_KEY`, `FONNTE_API_KEY`)

### Configuration
- ✅ Configure `fonnte_phone_number` in Filament Settings
- ✅ Add members to track via Filament Members resource
- ✅ Verify `minimum_match_date` setting (default: 2026-01-24 17:00:00)
- ✅ Test API connectivity to Steam, OpenDota, and Fonnte

### Monitoring
- ✅ Set up log monitoring: `storage/logs/laravel.log`
- ✅ Monitor failed jobs: `php artisan queue:failed`
- ✅ Check cron execution in system logs
- ✅ Verify match checks run every 5 minutes
- ✅ Confirm WhatsApp notifications are being sent

### Performance
- ✅ Run `php artisan config:cache`
- ✅ Run `php artisan route:cache`
- ✅ Run `php artisan view:cache`
- ✅ Set up log rotation for `storage/logs/laravel.log`

---

## Troubleshooting

### No Notifications Received

1. Check Fonnte API key is valid in `.env`
2. Verify `fonnte_phone_number` is configured in Settings
3. Check failed jobs: `php artisan queue:failed`
4. Review logs: `tail -f storage/logs/laravel.log`
5. Verify cron jobs are running

### Match Check Not Running

1. Verify cron is configured: `crontab -l`
2. Check cron logs: `grep CRON /var/log/syslog`
3. Manually run: `php artisan matches:check`
4. Verify members exist in database
5. Check Steam API key is valid

### Queue Jobs Not Processing

1. Verify cron entry for `queue:work --stop-when-empty` exists
2. Check jobs table: `DB::table('jobs')->count()` via tinker
3. Check failed jobs: `php artisan queue:failed`
4. Manually process queue: `php artisan queue:work --stop-when-empty`
5. Review error logs

### Permission Errors

```bash
# Fix storage permissions
chmod -R 775 storage bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache
```

---

## API Rate Limits

Be aware of external API rate limits:

- **Steam API**: 100,000 calls per day (free tier)
- **OpenDota API**: 60 calls per minute (free tier)
- **Fonnte**: Based on your subscription plan

The system is designed to stay well within these limits with 5-minute polling intervals.

---

## Maintenance

### Regular Tasks

- **Daily**: Monitor application logs for errors
- **Weekly**: Check failed jobs queue
- **Monthly**: Review and clean old match records if needed
- **As Needed**: Update dependencies with `composer update`

### Updating the Application

```bash
cd /var/www/dota-match-result

# Pull latest code
git pull origin main

# Update dependencies
composer install --optimize-autoloader --no-dev
npm install && npm run build

# Run migrations (if any)
php artisan migrate --force

# Clear and rebuild caches
php artisan config:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

---

## Support

For issues or questions:

1. Check application logs: `storage/logs/laravel.log`
2. Review failed jobs: `php artisan queue:failed`
3. Verify API keys and configuration
4. Check external API status (Steam, OpenDota, Fonnte)

---

## License

This project is proprietary and intended for internal use only.

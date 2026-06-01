# DotA 2 Match Result Notification System

## Project Overview

This Laravel application is an automated notification system that monitors registered members' DotA 2 match results and sends formatted WhatsApp notifications to a central number. The system polls the Steam API every 5 minutes to check for new matches, detects when multiple members played together (party matches), and ensures each match is only notified once.

## Core Purpose

The system tracks specific DotA 2 players (identified by their Steam ID) and automatically sends match result notifications when they finish a game. This allows a group to stay updated on match outcomes through a central WhatsApp group or number, with properly formatted messages showing:

- Match ID
- Winning team (Radiant/Dire)
- Member names with their heroes and K/D/A statistics
- Party detection (when multiple tracked members played together)

## Key Features

### 1. Member Management
- **Member Database**: Stores registered players with their Steam ID and custom Name (alias)
- **Filament Admin Panel**: Provides a web-based CRUD interface for managing members
- **Steam ID Tracking**: Uses Steam ID as the unique identifier for API queries

### 2. Automated Match Polling
- **Scheduled Job**: Runs every 5 minutes via Laravel's task scheduler
- **Recent Matches**: Fetches the last 10 matches for each registered member from Steam API
- **Duplicate Prevention**: Checks database to ensure each match is only notified once
- **Party Detection**: Identifies when multiple members played in the same match

### 3. WhatsApp Notifications
- **Fonnte Integration**: Sends messages via Fonnte WhatsApp API
- **Database-Configured Number**: Phone number is stored in settings table and manageable via Filament admin panel
- **Static Message Format**: Consistent formatting for all match results
- **No Delivery Tracking**: Messages are sent without storing delivery status

### 4. Error Handling & Reliability
- **Error Logging**: All API failures are logged for troubleshooting
- **Retry Logic**: Failed notifications are retried with exponential backoff
- **Queue System**: Notifications processed asynchronously via Laravel queues
- **API Resilience**: Handles Steam and Fonnte API failures gracefully

## Message Format Example

```
🕹️ *Turbo* _(24 minutes)_

*Kudog, Kocrol, Bajindoel* won a game as Radiant

*Radiant* 37 ⚔️ 28 *Dire*

*Kudog* _(Queen of Pain)_ – 13/1/9
*Kocrol* _(Lion)_ – 3/12/15
*Bajindoel* _(Phantom Lancer)_ – 9/8/10

*Match ID* — 8662122936
```

### Message Components
- **Header**: Match ID with unique identifier
- **Summary Line**: Comma-separated member names, win/loss status, and team (Radiant/Dire)
- **Player Stats**: Each member on a new line with format: `Name (Hero) – Kills/Deaths/Assists`

### Party Detection
When multiple tracked members play in the same match, they are all included in a single notification message, grouped together to show they played as a party.

## Data Models

### Member Model
**Purpose**: Stores registered players to be tracked

**Fields**:
- `id` (primary key)
- `steam_id` (string, unique) - Steam 64-bit ID for API queries
- `name` (string) - Custom alias/nickname (not Steam username)
- `created_at`, `updated_at` (timestamps)

**Relationships**: Has many matches through pivot table

### Match Model (DotaMatch)
**Purpose**: Tracks processed matches to prevent duplicate notifications

**Fields**:
- `id` (primary key)
- `match_id` (string, unique) - DotA 2 match identifier
- `match_data` (json) - Complete match details (team, players, heroes, stats)
- `members` (json) - Array of tracked member IDs who participated
- `notified_at` (timestamp) - When notification was sent successfully
- `created_at`, `updated_at` (timestamps)

**Purpose of Storage**: 
- Duplicate prevention (check if match_id exists)
- Audit trail of sent notifications
- Party detection (identify multiple members in same match)
- Historical data for potential future features

### Setting Model
**Purpose**: Stores application configuration in database

**Fields**:
- `id` (primary key)
- `key` (string, unique) - Setting identifier (e.g., 'fonnte_phone_number')
- `value` (text) - Setting value
- `type` (string) - Data type (string, number, boolean, etc.)
- `label` (string) - Display name
- `description` (text, nullable) - Optional description
- `created_at`, `updated_at` (timestamps)

**Key Settings**:
- `fonnte_phone_number` - WhatsApp number to send notifications (e.g., 628123456789)

**Helper Methods**:
- `Setting::get($key, $default)` - Retrieve cached setting value
- `Setting::set($key, $value)` - Update setting and clear cache

## Technical Architecture

### Framework & Packages
- **Laravel 12**: Latest framework version with streamlined structure
- **PHP 8.4.16**: Modern PHP features
- **Filament**: Admin panel for member management (requires installation)
- **Pest**: Testing framework for unit and feature tests
- **Laravel Pint**: Code formatting

### External APIs
1. **Steam Web API**
   - Endpoint: `GetMatchHistory` - Fetch player's recent matches
   - Endpoint: `GetMatchDetails` - Retrieve detailed match information
   - Authentication: API key via `STEAM_API_KEY` environment variable

2. **Fonnte API**
   - Service: WhatsApp message sending
   - Authentication: API key via `FONNTE_API_KEY` environment variable
   - Endpoint: POST to Fonnte messaging endpoint

### Application Components

#### Services (app/Services/)
1. **SteamApiService**
   - `getMatchHistory(string $steamId, int $limit = 10)` - Fetch recent matches
   - `getMatchDetails(string $matchId)` - Get detailed match data
   - Handles HTTP requests, error handling, rate limiting

2. **FonnteService**
   - `sendMessage(string $phoneNumber, string $message)` - Send WhatsApp message
   - Error handling for API failures
   - Returns success/failure status

#### Console Commands (app/Console/Commands/)
**CheckMatchesCommand**
- Runs every 5 minutes via scheduler
- Iterates through all active members
- Fetches last 10 matches from Steam API for each member
- Checks database for existing match records
- Groups members who played in the same match
- Dispatches `ProcessMatchNotification` job for new matches

#### Jobs (app/Jobs/)
**ProcessMatchNotification**
- Implements `ShouldQueue` interface
- Receives match data and member information
- Formats message according to static template
- Calls FonnteService to send WhatsApp message
- Implements retry logic with exponential backoff (3 attempts)
- Logs errors on final failure
- Updates match record with `notified_at` timestamp on success

#### Scheduled Tasks (routes/console.php)
```php
Schedule::command('matches:check')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground();
```

#### Filament Resources (app/Filament/Resources/)
**MemberResource**
- Lists all tracked members
- Forms for creating/editing members
- Validation for Steam ID format
- Displays member statistics (matches tracked, last checked)

**SettingResource**
- Manages application configuration
- Edit system settings like Fonnte phone number
- Key-value based configuration storage
- Settings are cached for performance

### Workflow Diagram

```
Every 5 Minutes:
    CheckMatchesCommand runs
        ↓
    For each Member:
        → Fetch last 10 matches from Steam API
        → Check database for existing match records
        ↓
    New matches found?
        → Group by match_id (detect parties)
        → Dispatch ProcessMatchNotification job
        ↓
    Job processes in queue:
        → Format message with member names, heroes, K/D/A
        → Send via FonnteService
        → Update match record with notified_at timestamp
        ↓
    On failure:
        → Retry with exponential backoff (3 attempts)
        → Log error if all retries fail
```

### Duplicate Prevention Strategy

The system prevents duplicate notifications through:

1. **Match ID Uniqueness**: Each DotA 2 match has a unique identifier
2. **Database Check**: Before dispatching notification, query matches table for existing record
3. **Atomic Creation**: Use `firstOrCreate()` to handle race conditions
4. **Notification Timestamp**: `notified_at` field tracks when message was sent
5. **Queue Uniqueness**: Jobs include match_id to prevent duplicate queuing

### Error Handling & Retry Logic

#### Steam API Failures
- Log error with match context
- Skip current member, continue to next
- Will retry on next scheduled run (5 minutes later)

#### Fonnte API Failures
- Job retry with exponential backoff: 30s, 2min, 5min
- After 3 failed attempts, log error and mark job as failed
- Match remains in database without `notified_at` timestamp
- Manual retry possible via job requeue

#### Rate Limiting
- Implement delays between Steam API calls to respect rate limits
- Use Laravel's rate limiting features for API calls
- Cache match history to reduce redundant API calls

## Environment Configuration

Required environment variables (add to .env):

```env
# Steam API Configuration
STEAM_API_KEY=your_steam_api_key_here

# Fonnte WhatsApp Configuration
FONNTE_API_KEY=your_fonnte_api_key_here
# Note: Phone number is configured in database via Filament admin panel

# Queue Configuration
QUEUE_CONNECTION=database

# Application
APP_ENV=production
APP_DEBUG=false
```

### Configuring WhatsApp Phone Number

After setting up the application:

1. Access Filament admin panel at `/admin`
2. Navigate to **Settings**
3. Edit the `fonnte_phone_number` setting
4. Enter the WhatsApp number (e.g., `628123456789`)
5. Save changes

### API Key Acquisition

1. **Steam API Key**: 
   - Visit: https://steamcommunity.com/dev/apikey
   - Register with Steam account
   - Free, no limits for reasonable usage

2. **Fonnte API Key**:
   - Visit: https://fonnte.com
   - Register and subscribe to WhatsApp service
   - Obtain API token from dashboard

## Development Setup

### Installation Steps

1. Clone repository and install dependencies:
   ```bash
   composer install
   npm install
   ```

2. Configure environment:
   ```bash
   cp .env.example .env
   php artisan key:generate
   ```

3. Set up database and run migrations:
   ```bash
   php artisan migrate
   ```

4. Install Filament admin panel:
   ```bash
   composer require filament/filament
   php artisan filament:install --panels
   ```

5. Create admin user for Filament:
   ```bash
   php artisan make:filament-user
   ```

6. Seed initial members (optional):
   ```bash
   php artisan db:seed --class=MemberSeeder
   ```

7. Start scheduler (in production use cron):
   ```bash
   php artisan schedule:work
   ```

   Note: Queue processing is handled via cronjob, not a persistent worker

### Testing

Run tests to verify functionality:
```bash
php artisan test --compact
```

Test specific features:
```bash
php artisan test --filter=MatchNotificationTest
```

## Production Deployment

### Cron Configuration

Add to server crontab for production scheduling and queue processing:
```bash
# Run Laravel scheduler every minute (handles the 5-minute match check)
* * * * * cd /path-to-project && php artisan schedule:run >> /dev/null 2>&1

# Process queued jobs every minute (handles WhatsApp notifications)
* * * * * cd /path-to-project && php artisan queue:work --stop-when-empty --tries=3 --max-time=50 >> /dev/null 2>&1
```

**Why `--stop-when-empty`?**
- Processes all pending jobs then exits
- Cronjob restarts it every minute
- No need for Supervisor or persistent worker
- Simpler deployment and maintenance

### Monitoring

Monitor key areas:
- Queue jobs (failed jobs table)
- Application logs (storage/logs/laravel.log)
- Steam API response times
- Fonnte API success rates
- Match notification delays

## Future Enhancements (Out of Scope)

Potential features for future development:

1. **Statistics Dashboard**: Display member win rates, favorite heroes, performance trends
2. **Multiple WhatsApp Numbers**: Send to different groups based on member configuration
3. **Custom Message Templates**: Allow customization of notification format
4. **Real-time Notifications**: Implement webhook-based detection instead of polling
5. **Discord Integration**: Alternative notification channel using Discord webhooks
6. **Match Filters**: Only notify for ranked matches, or specific game modes
7. **Hero Statistics**: Track which heroes members play most frequently
8. **Performance Alerts**: Notify on exceptional performances (rampage, ultra kill, etc.)

## Maintenance & Support

### Regular Tasks
- Monitor failed jobs queue
- Review application logs weekly
- Update Steam API endpoints if changed
- Keep dependencies updated (composer update)
- Backup match database monthly

### Troubleshooting Common Issues

**No notifications received**:
- Check queue worker is running
- Verify Steam API key is valid
- Confirm Fonnte API key and phone number are correct
- Review failed jobs table

**Duplicate notifications**:
- Check database for match_id uniqueness constraint
- Verify duplicate prevention logic in CheckMatchesCommand
- Review queue job uniqueness configuration

**Missing party members**:
- Ensure all members have correct Steam IDs in database
- Verify party detection logic groups same match_id
- Check Steam API returns all match participants

## Contributing Guidelines

When contributing to this project:

1. **Follow Laravel conventions** from `.github/copilot-instructions.md`
2. **Run Pint** before committing: `vendor/bin/pint --dirty`
3. **Write tests** for new features using Pest
4. **Update documentation** when adding features
5. **Use Form Requests** for validation, not inline controller validation
6. **Leverage Eloquent** over raw queries
7. **Follow existing patterns** in the codebase

## License

This project is proprietary and intended for internal use only.

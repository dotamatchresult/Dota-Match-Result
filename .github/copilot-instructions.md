<laravel-boost-guidelines>
=== .ai/purpose rules ===

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

=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to enhance the user's satisfaction building Laravel applications.

## Foundational Context
This application is a Laravel application and its main Laravel ecosystems package & versions are below. You are an expert with them all. Ensure you abide by these specific packages & versions.

- php - 8.4.19
- filament/filament (FILAMENT) - v5
- laravel/framework (LARAVEL) - v12
- laravel/prompts (PROMPTS) - v0
- livewire/livewire (LIVEWIRE) - v4
- laravel/mcp (MCP) - v0
- laravel/pint (PINT) - v1
- laravel/sail (SAIL) - v1
- pestphp/pest (PEST) - v3
- phpunit/phpunit (PHPUNIT) - v11
- tailwindcss (TAILWINDCSS) - v4

## Conventions
- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts
- Do not create verification scripts or tinker when tests cover that functionality and prove it works. Unit and feature tests are more important.

## Application Structure & Architecture
- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling
- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Replies
- Be concise in your explanations - focus on what's important rather than explaining obvious details.

## Documentation Files
- You must only create documentation files if explicitly requested by the user.

=== boost rules ===

## Laravel Boost
- Laravel Boost is an MCP server that comes with powerful tools designed specifically for this application. Use them.

## Artisan
- Use the `list-artisan-commands` tool when you need to call an Artisan command to double-check the available parameters.

## URLs
- Whenever you share a project URL with the user, you should use the `get-absolute-url` tool to ensure you're using the correct scheme, domain/IP, and port.

## Tinker / Debugging
- You should use the `tinker` tool when you need to execute PHP to debug code or query Eloquent models directly.
- Use the `database-query` tool when you only need to read from the database.

## Reading Browser Logs With the `browser-logs` Tool
- You can read browser logs, errors, and exceptions using the `browser-logs` tool from Boost.
- Only recent browser logs will be useful - ignore old logs.

## Searching Documentation (Critically Important)
- Boost comes with a powerful `search-docs` tool you should use before any other approaches when dealing with Laravel or Laravel ecosystem packages. This tool automatically passes a list of installed packages and their versions to the remote Boost API, so it returns only version-specific documentation for the user's circumstance. You should pass an array of packages to filter on if you know you need docs for particular packages.
- The `search-docs` tool is perfect for all Laravel-related packages, including Laravel, Inertia, Livewire, Filament, Tailwind, Pest, Nova, Nightwatch, etc.
- You must use this tool to search for Laravel ecosystem documentation before falling back to other approaches.
- Search the documentation before making code changes to ensure we are taking the correct approach.
- Use multiple, broad, simple, topic-based queries to start. For example: `['rate limiting', 'routing rate limiting', 'routing']`.
- Do not add package names to queries; package information is already shared. For example, use `test resource table`, not `filament 4 test resource table`.

### Available Search Syntax
- You can and should pass multiple queries at once. The most relevant results will be returned first.

1. Simple Word Searches with auto-stemming - query=authentication - finds 'authenticate' and 'auth'.
2. Multiple Words (AND Logic) - query=rate limit - finds knowledge containing both "rate" AND "limit".
3. Quoted Phrases (Exact Position) - query="infinite scroll" - words must be adjacent and in that order.
4. Mixed Queries - query=middleware "rate limit" - "middleware" AND exact phrase "rate limit".
5. Multiple Queries - queries=["authentication", "middleware"] - ANY of these terms.

=== php rules ===

## PHP

- Always use curly braces for control structures, even if it has one line.

### Constructors
- Use PHP 8 constructor property promotion in `__construct()`.
    - <code-snippet>public function __construct(public GitHub $github) { }</code-snippet>
- Do not allow empty `__construct()` methods with zero parameters unless the constructor is private.

### Type Declarations
- Always use explicit return type declarations for methods and functions.
- Use appropriate PHP type hints for method parameters.

<code-snippet name="Explicit Return Types and Method Params" lang="php">
protected function isAccessible(User $user, ?string $path = null): bool
{
    ...
}
</code-snippet>

## Comments
- Prefer PHPDoc blocks over inline comments. Never use comments within the code itself unless there is something very complex going on.

## PHPDoc Blocks
- Add useful array shape type definitions for arrays when appropriate.

## Enums
- Typically, keys in an Enum should be TitleCase. For example: `FavoritePerson`, `BestLake`, `Monthly`.

=== herd rules ===

## Laravel Herd

- The application is served by Laravel Herd and will be available at: `https?://[kebab-case-project-dir].test`. Use the `get-absolute-url` tool to generate URLs for the user to ensure valid URLs.
- You must not run any commands to make the site available via HTTP(S). It is always available through Laravel Herd.

=== tests rules ===

## Test Enforcement

- Every change must be programmatically tested. Write a new test or update an existing test, then run the affected tests to make sure they pass.
- Run the minimum number of tests needed to ensure code quality and speed. Use `php artisan test --compact` with a specific filename or filter.

=== laravel/core rules ===

## Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using the `list-artisan-commands` tool.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Database
- Always use proper Eloquent relationship methods with return type hints. Prefer relationship methods over raw queries or manual joins.
- Use Eloquent models and relationships before suggesting raw database queries.
- Avoid `DB::`; prefer `Model::query()`. Generate code that leverages Laravel's ORM capabilities rather than bypassing them.
- Generate code that prevents N+1 query problems by using eager loading.
- Use Laravel's query builder for very complex database operations.

### Model Creation
- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `list-artisan-commands` to check the available options to `php artisan make:model`.

### APIs & Eloquent Resources
- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

### Controllers & Validation
- Always create Form Request classes for validation rather than inline validation in controllers. Include both validation rules and custom error messages.
- Check sibling Form Requests to see if the application uses array or string based validation rules.

### Queues
- Use queued jobs for time-consuming operations with the `ShouldQueue` interface.

### Authentication & Authorization
- Use Laravel's built-in authentication and authorization features (gates, policies, Sanctum, etc.).

### URL Generation
- When generating links to other pages, prefer named routes and the `route()` function.

### Configuration
- Use environment variables only in configuration files - never use the `env()` function directly outside of config files. Always use `config('app.name')`, not `env('APP_NAME')`.

### Testing
- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

### Vite Error
- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

=== laravel/v12 rules ===

## Laravel 12

- Use the `search-docs` tool to get version-specific documentation.
- Since Laravel 11, Laravel has a new streamlined file structure which this project uses.

### Laravel 12 Structure
- In Laravel 12, middleware are no longer registered in `app/Http/Kernel.php`.
- Middleware are configured declaratively in `bootstrap/app.php` using `Application::configure()->withMiddleware()`.
- `bootstrap/app.php` is the file to register middleware, exceptions, and routing files.
- `bootstrap/providers.php` contains application specific service providers.
- The `app\Console\Kernel.php` file no longer exists; use `bootstrap/app.php` or `routes/console.php` for console configuration.
- Console commands in `app/Console/Commands/` are automatically available and do not require manual registration.

### Database
- When modifying a column, the migration must include all of the attributes that were previously defined on the column. Otherwise, they will be dropped and lost.
- Laravel 12 allows limiting eagerly loaded records natively, without external packages: `$query->latest()->limit(10);`.

### Models
- Casts can and likely should be set in a `casts()` method on a model rather than the `$casts` property. Follow existing conventions from other models.

=== livewire/core rules ===

## Livewire

- Use the `search-docs` tool to find exact version-specific documentation for how to write Livewire and Livewire tests.
- Use the `php artisan make:livewire [Posts\CreatePost]` Artisan command to create new components.
- State should live on the server, with the UI reflecting it.
- All Livewire requests hit the Laravel backend; they're like regular HTTP requests. Always validate form data and run authorization checks in Livewire actions.

## Livewire Best Practices
- Livewire components require a single root element.
- Use `wire:loading` and `wire:dirty` for delightful loading states.
- Add `wire:key` in loops:

    ```blade
    @foreach ($items as $item)
        <div wire:key="item-{{ $item->id }}">
            {{ $item->name }}
        </div>
    @endforeach
    ```

- Prefer lifecycle hooks like `mount()`, `updatedFoo()` for initialization and reactive side effects:

<code-snippet name="Lifecycle Hook Examples" lang="php">
    public function mount(User $user) { $this->user = $user; }
    public function updatedSearch() { $this->resetPage(); }
</code-snippet>

## Testing Livewire

<code-snippet name="Example Livewire Component Test" lang="php">
    Livewire::test(Counter::class)
        ->assertSet('count', 0)
        ->call('increment')
        ->assertSet('count', 1)
        ->assertSee(1)
        ->assertStatus(200);
</code-snippet>

<code-snippet name="Testing Livewire Component Exists on Page" lang="php">
    $this->get('/posts/create')
    ->assertSeeLivewire(CreatePost::class);
</code-snippet>

=== pint/core rules ===

## Laravel Pint Code Formatter

- You must run `vendor/bin/pint --dirty` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test`, simply run `vendor/bin/pint` to fix any formatting issues.

=== pest/core rules ===

## Pest
### Testing
- If you need to verify a feature is working, write or update a Unit / Feature test.

### Pest Tests
- All tests must be written using Pest. Use `php artisan make:test --pest {name}`.
- You must not remove any tests or test files from the tests directory without approval. These are not temporary or helper files - these are core to the application.
- Tests should test all of the happy paths, failure paths, and weird paths.
- Tests live in the `tests/Feature` and `tests/Unit` directories.
- Pest tests look and behave like this:
<code-snippet name="Basic Pest Test Example" lang="php">
it('is true', function () {
    expect(true)->toBeTrue();
});
</code-snippet>

### Running Tests
- Run the minimal number of tests using an appropriate filter before finalizing code edits.
- To run all tests: `php artisan test --compact`.
- To run all tests in a file: `php artisan test --compact tests/Feature/ExampleTest.php`.
- To filter on a particular test name: `php artisan test --compact --filter=testName` (recommended after making a change to a related file).
- When the tests relating to your changes are passing, ask the user if they would like to run the entire test suite to ensure everything is still passing.

### Pest Assertions
- When asserting status codes on a response, use the specific method like `assertForbidden` and `assertNotFound` instead of using `assertStatus(403)` or similar, e.g.:
<code-snippet name="Pest Example Asserting postJson Response" lang="php">
it('returns all', function () {
    $response = $this->postJson('/api/docs', []);

    $response->assertSuccessful();
});
</code-snippet>

### Mocking
- Mocking can be very helpful when appropriate.
- When mocking, you can use the `Pest\Laravel\mock` Pest function, but always import it via `use function Pest\Laravel\mock;` before using it. Alternatively, you can use `$this->mock()` if existing tests do.
- You can also create partial mocks using the same import or self method.

### Datasets
- Use datasets in Pest to simplify tests that have a lot of duplicated data. This is often the case when testing validation rules, so consider this solution when writing tests for validation rules.

<code-snippet name="Pest Dataset Example" lang="php">
it('has emails', function (string $email) {
    expect($email)->not->toBeEmpty();
})->with([
    'james' => 'james@laravel.com',
    'taylor' => 'taylor@laravel.com',
]);
</code-snippet>

=== tailwindcss/core rules ===

## Tailwind CSS

- Use Tailwind CSS classes to style HTML; check and use existing Tailwind conventions within the project before writing your own.
- Offer to extract repeated patterns into components that match the project's conventions (i.e. Blade, JSX, Vue, etc.).
- Think through class placement, order, priority, and defaults. Remove redundant classes, add classes to parent or child carefully to limit repetition, and group elements logically.
- You can use the `search-docs` tool to get exact examples from the official documentation when needed.

### Spacing
- When listing items, use gap utilities for spacing; don't use margins.

<code-snippet name="Valid Flex Gap Spacing Example" lang="html">
    <div class="flex gap-8">
        <div>Superior</div>
        <div>Michigan</div>
        <div>Erie</div>
    </div>
</code-snippet>

### Dark Mode
- If existing pages and components support dark mode, new pages and components must support dark mode in a similar way, typically using `dark:`.

=== tailwindcss/v4 rules ===

## Tailwind CSS 4

- Always use Tailwind CSS v4; do not use the deprecated utilities.
- `corePlugins` is not supported in Tailwind v4.
- In Tailwind v4, configuration is CSS-first using the `@theme` directive — no separate `tailwind.config.js` file is needed.

<code-snippet name="Extending Theme in CSS" lang="css">
@theme {
  --color-brand: oklch(0.72 0.11 178);
}
</code-snippet>

- In Tailwind v4, you import Tailwind using a regular CSS `@import` statement, not using the `@tailwind` directives used in v3:

<code-snippet name="Tailwind v4 Import Tailwind Diff" lang="diff">
   - @tailwind base;
   - @tailwind components;
   - @tailwind utilities;
   + @import "tailwindcss";
</code-snippet>

### Replaced Utilities
- Tailwind v4 removed deprecated utilities. Do not use the deprecated option; use the replacement.
- Opacity values are still numeric.

| Deprecated |	Replacement |
|------------+--------------|
| bg-opacity-* | bg-black/* |
| text-opacity-* | text-black/* |
| border-opacity-* | border-black/* |
| divide-opacity-* | divide-black/* |
| ring-opacity-* | ring-black/* |
| placeholder-opacity-* | placeholder-black/* |
| flex-shrink-* | shrink-* |
| flex-grow-* | grow-* |
| overflow-ellipsis | text-ellipsis |
| decoration-slice | box-decoration-slice |
| decoration-clone | box-decoration-clone |
</laravel-boost-guidelines>

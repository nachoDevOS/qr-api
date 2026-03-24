# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Commands

### Setup (first time)
```bash
npm run setup
# Equivalent to: composer install, cp .env.example .env, php artisan key:generate, php artisan migrate, npm install, npm run build
```

### Development
```bash
npm run dev
# Runs concurrently: php artisan serve, php artisan queue:listen, vite dev server
```

### Build
```bash
npm run build   # Vite production build
```

### Testing
```bash
npm run test                        # Clear config cache + run all tests
php artisan test                    # Run all tests
php artisan test --filter TestName  # Run a single test
php artisan test tests/Feature/ExampleTest.php  # Run specific file
```

### Code Formatting
```bash
./vendor/bin/pint  # Laravel Pint (PHP code formatter)
```

### Database
```bash
php artisan migrate          # Run migrations
php artisan migrate:fresh    # Drop all tables and re-run migrations
php artisan tinker           # Interactive REPL
```

## Architecture

**Laravel 12** backend + **Vite** + **Tailwind CSS 4** frontend stack.

### Request Flow
```
public/index.php → bootstrap/app.php → routes/web.php → Controllers → Models → Database (MySQL)
```

- Routes: `routes/web.php` (HTTP), `routes/console.php` (Artisan commands)
- Controllers: `app/Http/Controllers/`
- Models: `app/Models/` — Eloquent ORM, MySQL database named `qr_api`
- Views: `resources/views/` (Blade templates)
- Frontend assets: `resources/css/app.css` (Tailwind), `resources/js/app.js` — bundled by Vite

### Testing
- Framework: **Pest PHP 3** (built on PHPUnit)
- Feature tests extend `Tests\TestCase`, live in `tests/Feature/`
- Unit tests live in `tests/Unit/`
- Tests use SQLite in-memory DB, array cache/session/mail/queue drivers (configured in `phpunit.xml`)

### Queue & Background Jobs
- Queue driver: `database` (local) — tables: `jobs`, `job_batches`, `failed_jobs`
- Start listener: `php artisan queue:listen`

### Environment
- `.env` is gitignored; copy from `.env.example`
- Key env vars: `DB_CONNECTION=mysql`, `DB_DATABASE=qr_api`, `QUEUE_CONNECTION=database`, `SESSION_DRIVER=database`

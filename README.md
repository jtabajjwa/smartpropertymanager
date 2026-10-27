# Primora

A multi-tenant property management system built on Laravel 12. Landlords sign up for their own isolated organization ("landlord") and manage locations, estates, units, tenants, leases, invoices, payments, security deposits, utilities, and expenses. Each landlord's data is fully isolated from every other landlord's, sharing one database.

Stack: Laravel 12 (PHP), Blade + Alpine.js + Tailwind CSS 4 (server-rendered, no SPA framework), MySQL/MariaDB, Vite.

---

## Requirements

- **PHP 8.4** — the committed `composer.lock` resolves packages (`symfony/clock`, `nesbot/carbon`, `sebastian/*` test deps) that require PHP 8.3+ at minimum; in practice you need 8.4. If your local PHP is older (e.g. WAMP/XAMPP often ship 8.1–8.2), `composer install` will refuse to run. See [Installing PHP 8.4 alongside an older version](#installing-php-84-alongside-an-older-version) below if you're not ready to upgrade your whole environment.
- **Composer 2**
- **Node.js 18+ and npm**
- **MySQL 8 or MariaDB** — any instance reachable over TCP works (WAMP/XAMPP/Docker/native install)
- Required PHP extensions: `pdo_mysql`, `mbstring`, `openssl`, `curl`, `fileinfo`, `gd`, `intl`, `zip`, `bcmath` (bundled with most PHP distributions; on Windows builds these ship as DLLs you enable in `php.ini`)

### Installing PHP 8.4 alongside an older version

If you don't want to touch your system's existing PHP (e.g. a shared WAMP install used by other projects), install a second, standalone PHP 8.4 somewhere isolated and just use its binary for this project — no need to change any system PATH or config:

1. Download the NTS x64 zip for your OS from [windows.php.net/downloads](https://windows.php.net/downloads/) (Windows) or your platform's PHP distribution.
2. Extract it somewhere self-contained, e.g. `C:\dev-tools\php8.4\`.
3. `copy php.ini-development php.ini`, then in that `php.ini`:
   - Uncomment `extension_dir = "ext"`
   - Uncomment `extension=` lines for: `curl`, `fileinfo`, `gd`, `intl`, `mbstring`, `mysqli`, `openssl`, `pdo_mysql`, `zip`, `pdo_sqlite` (the last one is needed to run the test suite, which uses an in-memory SQLite database)
4. Use the full path to that `php.exe` for every command below instead of a bare `php` — e.g. `C:\dev-tools\php8.4\php.exe artisan migrate`. (Tip: a one-line batch/shell wrapper script in your PATH saves retyping this.)

---

## Setup

```bash
# 1. Install PHP and JS dependencies
composer install
npm install

# 2. Copy the environment file and generate an app key
cp .env.example .env
php artisan key:generate
```

### 3. Configure the database

Create a database and a dedicated user (don't use `root` in anything beyond local dev):

```sql
CREATE DATABASE primora CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'primora'@'127.0.0.1' IDENTIFIED BY 'choose-a-password';
CREATE USER 'primora'@'localhost' IDENTIFIED BY 'choose-a-password';
GRANT ALL PRIVILEGES ON primora.* TO 'primora'@'127.0.0.1';
GRANT ALL PRIVILEGES ON primora.* TO 'primora'@'localhost';
FLUSH PRIVILEGES;
```

Then set in `.env`:

```
DB_CONNECTION=mariadb
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=primora
DB_USERNAME=primora
DB_PASSWORD=choose-a-password
```

> **Note on storage engine:** some MySQL/MariaDB installs (older WAMP defaults in particular) create new tables as `MyISAM` instead of `InnoDB`, which breaks this app's foreign keys and unique indexes with a `1071 Specified key was too long` error on the very first migration. You shouldn't need to do anything about this — `config/database.php` already forces `ENGINE=InnoDB` on every table this app creates (`DB_ENGINE` env var if you ever need to override it) — but if you ever see that error, that's the cause.

### 4. Migrate and seed

```bash
php artisan migrate
php artisan db:seed
```

This creates one default "landlord" organization and seeds an admin login (see `database/seeders/AdminSeeder.php`):

- **Email:** `rhodanansubuga@gmail.com`
- **Password:** `12345678`

You'll be forced to change the password on first login. Additional landlord organizations can self-register at `/signup` — each gets its own fully isolated data.

### 4b. Make uploaded files viewable

Logos and estate/unit photos are stored in `storage/app/public`. Link that folder into `public/` once per machine or server:

```bash
php artisan storage:link
```

For photo uploads, PHP must accept files up to 10 MB. In `php.ini`, set `upload_max_filesize = 10M` and `post_max_size = 12M`, and enable `extension=gd` and `extension=exif`. EXIF lets the app straighten phone photos before resizing them; without it, photos are stored unresized. On cPanel hosting these are under **Select PHP Version → Options/Extensions**.

### 5. Build frontend assets

```bash
npm run build       # one-off production build
# or
npm run dev          # Vite dev server with hot reload, for active frontend work
```

### 6. Run it

```bash
C:\dev-tools\php8.4.25\php.exe artisan serve
```

Visit **http://127.0.0.1:8000**.

---

## Daily development startup

Once setup is done, this is all you need to start working each day. Run the commands from the project root, each in its own terminal.

**1. Start the database.** Start WAMP (or whichever MySQL/MariaDB you use) so the database is reachable on `127.0.0.1:3306`.

**2. Start the app server** (required):

```bash
C:\dev-tools\php8.4.25\php.exe artisan serve
```

- Landlord app: **http://127.0.0.1:8000** (login at `/login`, signup at `/signup`)
- Staff portal: **http://127.0.0.1:8000/staff/login**

> Tip: `php84.bat` in the project root is a local shortcut for the PHP 8.4 binary. `.\php84.bat artisan serve` does the same thing. The file isn't committed (it's in `.gitignore`), so create it on a new machine if you want it.

**3. Start Vite** (optional, only when editing Blade views, CSS or JS):

```bash
npm run dev
```

This hot-reloads frontend changes in the browser. Without it, the app uses the last production build in `public/build`, so run `npm run build` before you stop working if you changed any frontend files. Vite creates a `public/hot` file while it runs. If assets ever fail to load after Vite has stopped, delete that file.

**4. Run scheduled jobs** (optional, only when testing them):

```bash
# run the scheduler continuously, as production cron would
C:\dev-tools\php8.4.25\php.exe artisan schedule:work

# or trigger a single job once
C:\dev-tools\php8.4.25\php.exe artisan invoices:generate-recurring
C:\dev-tools\php8.4.25\php.exe artisan sms:overdue-reminders
C:\dev-tools\php8.4.25\php.exe artisan sms:expiring-leases
C:\dev-tools\php8.4.25\php.exe artisan sms:upcoming-invoices
```

You don't need a queue worker: all email is sent immediately, not queued.

**Stopping:** press `Ctrl+C` in each terminal.

**When something goes wrong:**
- Errors are logged to `storage/logs/laravel.log`.
- After changing `.env`, run `artisan config:clear`.
- After pulling new code, run `composer install`, `npm install` and `artisan migrate`.

---

## Sending real email (signup verification)

New landlord signups require email verification before the account can be used. By default `.env.example` ships with `MAIL_MAILER=log`, which just writes emails to `storage/logs/laravel.log` instead of sending them — fine for initial setup, but you won't receive real verification emails until you configure a real mailer.

To use Gmail SMTP for local/dev testing:

```
MAIL_MAILER=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=your.gmail.address@gmail.com
MAIL_PASSWORD="your 16-character app password"
MAIL_FROM_ADDRESS="your.gmail.address@gmail.com"
MAIL_FROM_NAME="${APP_NAME}"
```

Gmail requires an **App Password**, not your normal account password — generate one at [myaccount.google.com/apppasswords](https://myaccount.google.com/apppasswords) (requires 2-Step Verification enabled on the account). For anything beyond local testing, use a real transactional email provider (Mailgun, SES, Postmark, Resend, etc.) — swapping providers later is just an `.env` change, nothing in the code depends on which one you use.

After changing mail settings, clear the config cache: `php artisan config:clear`.

## SMS (password reset)

Password reset sends a one-time code via SMS (not email), using the Marz SMS gateway. It's disabled by default (`MARZ_SMS_ENABLED=false` in `.env`) — the app still runs fine without it, but password reset won't actually deliver a code until you set:

```
MARZ_SMS_ENABLED=true
MARZ_SMS_API_KEY=...
MARZ_SMS_API_SECRET=...
```

---

## Landlord payouts (Yo! withdrawals)

Mobile money payouts use Yo! Payments `acwithdrawfunds`, which must be signed with Primora's private key once public key authentication is on for the Yo! account (it is in production). Set it up once:

```bash
# 1. Generate a key pair (keep the private key OUT of the web root and out of git)
openssl genrsa -out yo-private.pem 2048
openssl rsa -in yo-private.pem -pubout -out yo-public.pem

# 2. Send yo-public.pem (never the private key) to your Yo! account representative
#    to configure on the Yo! Payments account.
```

Then in `.env`:

```env
YO_PRIVATE_KEY_PATH=/home/primora/keys/yo-private.pem
# YO_PRIVATE_KEY_PASSPHRASE=   (only if the key has one)
```

Without a key, payouts can only be sent in sandbox mode. Bank payouts don't use Yo!: staff make the EFT from Primora's bank and record its reference.

Payout charges (deducted from what the landlord receives) and Yo! collection charges come from the fee bands under **Staff → Rent Collections → Fee bands**.

## Primora's books

Payments, payouts, settlements and SMS purchases post to a double-entry ledger automatically (**Staff → Books**). To post anything that happened before the books existed, or a posting that failed, run (safe to repeat):

```bash
php artisan books:backfill
```

Enter opening balances (e.g. the bank balance) as a journal entry against Owner's equity.

## Landlord books & taxes

Landlord admins get **Books & Taxes** (income statement, results by property, taxes and levies, and a one-click tax pack as PDF or Excel for a fiscal year). Nothing extra is entered: it's built from invoices, payments, expenses, deposit deductions and Primora's charges (`App\Accounting\LandlordBooks`).

- **Basis:** each landlord chooses cash (income when paid; unallocated payments are rent in advance) or accrual (income when invoiced; write-offs become bad debts). Security deposits are never income.
- **Taxes:** Primora's catalogue (`tax_templates`, editable under **Staff → Books → Landlord tax rates** with the `books.tax_rates` permission): URA rental income tax (individual: rate above a threshold; company: rate on rent less expenses, capped), local service tax (bands), property rates (% of rateable value, per estate), ground rent and trading licence (fixed amounts). Landlords switch on the ones that apply and can override any figure; payments they record count against the year, and deductible levies count as expenses.
- **Chart of accounts, trial balance, balance sheet:** `App\Accounting\LandlordLedger` derives a double-entry journal from the same records on every request (nothing stored, so it can never drift from the records): a simple default chart (cash/bank/mobile money, rent collected by Primora, receivables and rent in advance on accrual, deposits held, retained earnings, income, one expense account per expense item, levies, income tax & LST). Income and expenses close to retained earnings at each fiscal year end. The tax pack includes the balance sheet and trial balance.
- The default rates are estimates — check them against current URA and council figures before each filing season.

## Plan features

Plans can include or leave out these features (`App\Support\PlanFeatures`): Books & taxes, Balance sheet & trial balance, Accrual accounting, Team members, Audit log, Vendors & job cards. Everything else (properties, tenants, invoices, payments, tenant portal, collections, payouts, SMS) is on every plan.

- **Staff → Plans:** tick the features each plan includes. All ticked (the default, and every existing plan) means every feature, including ones added later.
- **Staff → Landlord → Plan features:** grant one landlord a feature their plan leaves out (e.g. keep what they had when you introduce tiers, or a trial).
- Landlords without a plan have everything. Locked features show a padlock in the sidebar; their pages show what the feature does, which plans include it and a Change plan button. Routes use the `feature:` middleware, e.g. `->middleware('feature:books')`.
- A landlord on accrual whose plan drops accrual accounting is reported on the cash basis until it's back (their setting is kept).

---

## Deploying (staging & production)

Staging (staging.primoraproptech.com) and production (app.primoraproptech.com) run on one EL10 VPS. Set up the server once with `deploy/setup-server.sh`, then use the `primora` command on the server (`sudo primora deploy staging`, `sudo primora promote`, `sudo primora rollback <env>`, …). On a new server, create the first staff admin with `php artisan staff:create-admin`. Never run the seeders there: they hold development accounts. Full guide: [deploy/README.md](deploy/README.md).

---

## Running tests

```bash
php artisan test
```

The suite uses an in-memory SQLite database (`phpunit.xml`), so it doesn't touch your configured MySQL database at all — no extra setup needed beyond having the `pdo_sqlite` PHP extension enabled.

---

## Architecture notes

- **Multi-tenancy:** every business table (locations, estates, units, tenants, leases, invoices, payments, expenses, users, audit logs, SMS logs) carries a `landlord_id` and is automatically scoped to the current landlord via a global Eloquent scope (`App\Traits\BelongsToLandlord` + `App\Services\CurrentLandlord`). Scoped models throw rather than silently leaking data if that context is ever missing.
- **Auth:** users log in by email (not username — there is no username field). New landlords self-register at `/signup`, which creates both the `Landlord` organization and its first admin `User` in one step, then requires email verification before the account can be used.
- **No API/SPA layer:** the whole app is server-rendered Blade with Alpine.js for interactivity (modals, wizards, dropdowns) — forms submit as normal POSTs, not fetch/AJAX. The one exception is a small set of JSON endpoints under `/search/*` used for searchable dropdowns.
- **Scheduled jobs:** recurring rent invoicing and SMS reminders run via `routes/console.php` (`php artisan schedule:work` locally, or a real cron entry calling `schedule:run` every minute in production).

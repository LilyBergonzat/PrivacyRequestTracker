# Privacy Request Tracker

A small self-hosted web app to handle data subject requests (access, deletion, correction, objection) across the systems a community runs: a Discord Modmail bot, a website, other bots, and so on.

It is a tracker and a guided checklist. Staff do the actual deletions by hand, following instructions stored for each system. The app records what was done, when and by whom, keeps track of the legal deadlines, and never stores the requester's identifiers in plain text.

> This project is not legal advice. The deadlines follow GDPR Art. 12(3): one month to respond, extendable by two more months if the requester is told within the first month. Check what applies to your own organization.

## Features

- **Requests** with their type, channel, received date and legal deadline, plus one deadline per system (a system can promise a shorter response time than the law).
- **Duplicate check**: when a request is entered, the app tells you if the same person already made a request, without knowing who they are.
- **Guided checklists**: each system has its own steps, written in Markdown, with copyable commands. Placeholders like `{{discord_id}}` are filled in the browser only.
- **Identity verification**, per-system resolution (done, no data found, not applicable), deadline extensions, requester notification, closing and rejecting.
- **Activity timeline** for every request, append-only.
- **Lookup page** to answer "has this person asked before?".
- **Daily reminders** by email and/or Discord webhook for requests that are overdue or due soon.
- **Automatic purge** of closed requests after a retention period.
- **CSV export** of the record of processing, without any personal data.
- **System templates** in JSON, with an example for the [Modmail](https://github.com/modmail-dev/modmail) bot.
- Staff accounts with two roles (admin and handler) and mandatory two-factor authentication.

## How personal data is handled

- **Identifiers** (Discord ID, email, username, or any custom identifier) are normalized, then stored only as an HMAC-SHA256 with a secret key. The raw value is discarded right after hashing. A plain SHA-256 would not be enough: a list of Discord IDs could be hashed and compared.
- **Free text** (notes, summaries, reasons) is encrypted in the database with Laravel's encryption.
- **Contact details** are encrypted, and deleted as soon as the request is closed.
- **Placeholders** typed on a request page stay in the browser tab. They are never sent to the server.
- **Logs, emails, Discord messages and the CSV export** only contain references, types, statuses and dates.
- **Closed requests** are deleted with everything attached to them once their retention period is over. A single line is kept in the activity log to show that a purge happened (reference and type only).

## Requirements

- PHP 8.3 or newer, with the usual Laravel extensions
- Composer
- Node.js 22 or newer and npm (to build the assets)
- MariaDB or MySQL
- A web server serving the app over HTTPS
- Cron, to run the scheduler

## Installation

```bash
git clone https://github.com/LilyBergonzat/PrivacyRequestTracker.git
cd PrivacyRequestTracker
composer install --no-dev --optimize-autoloader
npm ci
npm run build
cp .env.example .env
php artisan key:generate
```

Create an empty database, then fill in the `DB_*` variables in `.env`.

### Generate the identifier key

```bash
php artisan privacy:key:generate
```

The command prints a new key and the version to use. It writes nothing to disk. Copy both lines into `.env`:

```dotenv
SUBJECT_HMAC_KEYS=v1:your-generated-key
SUBJECT_HMAC_CURRENT=v1
```

### Create the database and the first admin

Set the admin email in `.env`:

```dotenv
ADMIN_EMAIL=you@example.org
ADMIN_NAME="Your Name"
```

Then run:

```bash
php artisan migrate
php artisan db:seed
```

The seeder creates the admin account and prints a one-time password. It will not be shown again. On the first login, the app asks the admin to set up two-factor authentication.

Other staff accounts are created by an admin from the Users page. There is no public registration.

### Optional: example systems

```bash
php artisan db:seed --class=ExampleSystemsSeeder
```

This imports the templates from `resources/system-templates/` (currently the Modmail bot). Running it again skips the systems that already exist, so your changes are kept.

## Configuration

All settings live in `.env`.

| Variable | Default | Description |
|---|---|---|
| `PRIVACY_TIMEZONE` | `UTC` | Your organization's time zone. Deadlines end at the end of the day in this time zone, and the daily tasks run on its clock. Dates are stored in UTC. |
| `PRIVACY_DEADLINE_WARNING_DAYS` | `7` | How many days before a deadline a request is shown as "soon" and included in reminders. |
| `PRIVACY_RETENTION_MONTHS` | `24` | How long a closed request is kept before being purged. Changing it only affects requests closed afterwards. |
| `PRIVACY_REQUIRE_TWO_FACTOR` | `true` | Two-factor authentication for every account. Only turn it off for local development. |
| `SUBJECT_HMAC_KEYS` | | Versioned identifier keys, e.g. `v1:…,v2:…`. See [Keys](#keys). |
| `SUBJECT_HMAC_CURRENT` | | The key version used for new requests. |
| `PRIVACY_NOTIFY_MAIL` | `false` | Send the daily reminder by email to every active staff account. |
| `PRIVACY_DISCORD_WEBHOOK_URL` | | Send the daily reminder to a Discord channel. Leave empty to turn it off. Keep this URL secret: anyone who has it can post in the channel. |
| `ADMIN_EMAIL`, `ADMIN_NAME` | | Used by the seeder to create the first admin. |

### Email

Reminder emails use Laravel's mail settings (`MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS`). Use the SMTP settings of your email provider. Port 587 usually works with `MAIL_SCHEME=null`, and port 465 needs `MAIL_SCHEME=smtps`.

## Serving the app

`php artisan serve` is for local development only: it runs PHP's built-in server, which handles one request at a time. In production, use a web server such as Nginx or Apache with PHP-FPM.

- The document root must be the `public/` folder, never the project root, so that `.env` and the source code cannot be downloaded.
- The `storage/` and `bootstrap/cache/` folders must be writable by the user PHP-FPM runs as.
- No queue worker is needed: the app does not use queued jobs.

An example Nginx configuration, with HTTPS certificates from Let's Encrypt:

```nginx
server {
    listen 80;
    server_name privacy.example.org;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl;
    http2 on;
    server_name privacy.example.org;
    root /path/to/PrivacyRequestTracker/public;

    ssl_certificate /etc/letsencrypt/live/privacy.example.org/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/privacy.example.org/privkey.pem;

    index index.php;
    charset utf-8;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ ^/index\.php(/|$) {
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_hide_header X-Powered-By;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

Adjust the domain, the path and the PHP-FPM socket to your server. The Laravel documentation has more details on [deployment](https://laravel.com/docs/deployment).

If the app runs behind a reverse proxy or a load balancer that handles HTTPS, configure Laravel's [trusted proxies](https://laravel.com/docs/requests#configuring-trusted-proxies) so it knows the original request was secure. Otherwise the secure cookies and the HSTS header will not work.

## Scheduled tasks

The app needs a single cron entry, which runs every minute and lets Laravel decide what is due:

```cron
* * * * * cd /path/to/PrivacyRequestTracker && php artisan schedule:run >> /dev/null 2>&1
```

It runs two commands, in the organization's time zone:

| Time | Command | What it does |
|---|---|---|
| 03:00 | `privacy:purge` | Deletes the closed requests past their retention period, and clears any contact details left on closed requests. |
| 08:00 | `privacy:deadlines` | Sends one reminder listing the requests that are overdue or due soon. Nothing is sent when everything is on track. |

Both can be run by hand. `php artisan privacy:purge --dry-run` shows what would be deleted without deleting anything.

## Production checklist

- `APP_ENV=production` and `APP_DEBUG=false`. With debug enabled, error pages can show what was typed in a form, identifiers included.
- The app is only reachable over HTTPS, and `SESSION_SECURE_COOKIE=true`.
- `SESSION_ENCRYPT=true` and `SESSION_LIFETIME=720` (12 hours at most).
- `php artisan config:cache`, `php artisan route:cache` and `php artisan view:cache` after each deployment.
- The cron entry above is in place.
- Assets are built with `npm run build`. The app sends a strict Content Security Policy, which is disabled only while the Vite development server is running.

## Keys

Two secrets protect the data, and **neither is stored in the database**:

- **`APP_KEY`** encrypts the notes, summaries, reasons and contact details.
- **The identifier keys** (`SUBJECT_HMAC_KEYS`) are used to hash identifiers.

Back both up **separately from the database**, for example in a password manager. A database backup on its own only contains hashes and encrypted text.

What happens if a key is lost or leaked:

- **Lost `APP_KEY`**: the encrypted fields can no longer be read.
- **Lost identifier key**: the duplicate check and the lookup stop finding the requests hashed with it.
- **Leaked identifier key**: someone with the key and a copy of the database could check whether a guessed identifier made a request.

### Rotating the identifier key

1. Run `php artisan privacy:key:generate`.
2. Add the new key to `SUBJECT_HMAC_KEYS`, keeping the old ones: `v1:…,v2:…`.
3. Set `SUBJECT_HMAC_CURRENT` to the new version.

New requests use the new key. Lookups try every configured key, so older requests are still found. Only remove an old key once every request hashed with it has been purged.

## Backups and restores

The purge removes data from the database, but older backups still contain it. To keep that under control:

- Keep backups for a short, fixed period (for example 30 days), encrypted, and only use them for disaster recovery.
- **After restoring the tracker**, requests closed after the backup date come back as open, with their contact details. Close them again: this deletes the contact details again.
- **After restoring any other system** (a bot database, a website), the deletions made after the backup date are undone. Before putting the system back into service, redo the deletions of every request completed after the backup date. The tracker lists these requests and their steps.

## System templates

A system template is a JSON file describing a system and its steps. The ones shipped with the app live in `resources/system-templates/`:

```json
{
    "format_version": 1,
    "slug": "modmail-bot",
    "name": "Modmail bot",
    "description": "Markdown description",
    "response_period_days": null,
    "steps": [
        {
            "title": "Delete their own threads",
            "request_type": "deletion",
            "instructions": [
                "```js",
                "db.logs.deleteMany({ \"recipient.id\": \"{{discord_id}}\" });",
                "```"
            ]
        }
    ]
}
```

- `response_period_days`: `null` for the legal deadline, or a shorter number of days.
- `request_type`: `null` for every type, or one of `access`, `deletion`, `correction`, `objection`, `other`.
- `instructions`: one string per line, joined when imported.
- Placeholders such as `{{discord_id}}`, `{{email}}` or `{{username}}` are filled on the request page, in the browser.

Templates for other tools are welcome as pull requests.

## Development

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan privacy:key:generate
php artisan migrate --seed
composer run dev
```

`composer run dev` starts the development processes, Vite included. With `MAIL_MAILER=log`, emails are written to `storage/logs/laravel.log`.

Run the checks (code style, static analysis and tests):

```bash
composer test
```

## Built with

[Laravel](https://laravel.com), [Livewire](https://livewire.laravel.com), [Flux](https://fluxui.dev), [Alpine.js](https://alpinejs.dev), [Tailwind CSS](https://tailwindcss.com) and [Pest](https://pestphp.com).

## License

MIT, see [LICENSE](LICENSE).

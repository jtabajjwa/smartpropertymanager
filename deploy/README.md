# Primora — server deployment

One VPS (Contabo, EL10: AlmaLinux / Rocky / RHEL 10) runs both environments side by side:

| Environment | Address | Database | Deployed from |
|---|---|---|---|
| staging | https://staging.primoraproptech.com | `primora_staging` | `main` (any branch, tag or commit on request) |
| production | https://app.primoraproptech.com | `primora` | the commit already tested on staging (`primora promote`) |

The marketing website (primoraproptech.com) stays on its own host; see `scripts/deploy-website.sh`.

## How it fits together

```
nginx ──► PHP-FPM pool primora-staging    (user primora-staging)    ──► /var/www/primora/staging/current
      └─► PHP-FPM pool primora-production (user primora-production) ──► /var/www/primora/production/current
MariaDB (127.0.0.1 only)   one database + user per environment
systemd per environment:   primora-queue@<env>   primora-scheduler@<env>.timer (every minute)   primora-backup@<env>.timer (02:30)
```

Each environment runs as its own Linux user, so staging can't read production's `.env` or uploaded files.

Each deploy builds a new release folder, migrates the database, then switches the `current` link. That means there is no downtime, and a rollback is just switching the link back:

```
/var/www/primora/<env>/
├── releases/20261027-101500-ab12cd34e/   one per deploy, newest 5 kept
├── shared/.env                           secrets, owner root, group primora-<env>, mode 640
├── shared/storage/                       uploads, logs, cache (kept across deploys)
└── current -> releases/…                 what nginx and the workers run

/var/lib/primora/repo.git      bare clone of GitHub (read with a deploy key)
/etc/primora/                  primora.conf, staging.conf, production.conf
/var/backups/primora/<env>/    database + uploaded-file backups
```

## First-time setup

**0. Before you start**
- Commit and push your work to GitHub (`main`): the server deploys from GitHub, not from your PC.
- DNS (at your domain registrar): add an **A record** for `staging` pointing to the VPS IP. Add `app` later, when production goes live.

**1. Prepare the server** (about 10 minutes)

From your PC, in the project folder:

```bash
scp deploy/setup-server.sh root@SERVER_IP:/root/
ssh root@SERVER_IP
bash /root/setup-server.sh
```

This installs:
- nginx, PHP 8.4 (from the Remi repository), MariaDB, Node.js, Composer and Certbot;
- firewalld (allows only SSH, HTTP and HTTPS), fail2ban for SSH, and automatic security updates;
- SELinux rules, a 2 GB swap file if needed, and the `primora` command.

Partway through, the script shows a **deploy key**. Add it on GitHub under the repository's Settings → Deploy keys (read-only is enough), then press Enter. You can run the script again at any time; it skips steps that are already done.

**2. Deploy staging**

```bash
sudo primora deploy staging
```

The first run also:
- creates the database and its user with a generated password;
- writes `shared/.env` with a new `APP_KEY`, the database password and a `WEBSITE_API_KEY`;
- adds the nginx site, PHP pool and workers;
- once DNS points to the server, gets the HTTPS certificate (it asks for an email address once).

**3. Create your staff admin** (the seeders hold development accounts and are never run on the server):

```bash
sudo primora artisan staging staff:create-admin
```

**4. Fill in the services**

```bash
sudo primora env staging
```

This opens `.env` in nano. Save and exit, and the app picks up the changes. Values to set:
- `YO_API_USERNAME` / `YO_API_PASSWORD`. Staging uses `YO_MODE=sandbox`.
- SMTP: set `MAIL_MAILER=smtp`, `MAIL_HOST`, `MAIL_USERNAME`, `MAIL_PASSWORD`. Until then, email goes to the log. Test it with `sudo primora artisan <env> mail:test you@example.com`, which shows the settings in use and the mail server's error if sending fails.
- `MARZ_SMS_ENABLED=true` with the API key and secret.
- `WEBSITE_API_KEY`: generated already. For production, copy it into the website's `config.local.php`.
- The Yo! withdrawal signing key: put it in `/etc/primora/keys/<env>-yo.pem` (owner root, group `primora-<env>`, mode 640) and set `YO_PRIVATE_KEY_PATH`.

Yo! payment notifications arrive at `https://<domain>/payments/callback/yo/…`, which works as soon as HTTPS is on.

**Production** follows the same steps once staging looks right: add the `app` A record, then run:

```bash
sudo primora promote                              # deploys the commit live on staging
sudo primora artisan production staff:create-admin
sudo primora env production                       # live Yo! credentials, YO_MODE=production
```

## Everyday use

```bash
sudo primora deploy staging              # latest main → staging
sudo primora deploy staging my-branch    # or a branch / tag / commit
sudo primora promote                     # what's on staging → production (asks to confirm)
sudo primora rollback production         # back to the previous release (code only)
sudo primora status                      # what's live, workers, certificates, health, disk
sudo primora releases staging
sudo primora logs production             # follow the Laravel log
sudo primora backup production           # backup now
sudo primora down production             # maintenance page on (… up to turn it off)
sudo primora artisan staging tinker      # any artisan command, as the site's user
```

**What a deploy does**
1. Takes the commit from GitHub into a new release folder.
2. Runs `composer install --no-dev` and `npm ci && npm run build`.
3. Backs up the database.
4. Runs `migrate --force`, then caches config, routes, views and events.
5. Switches `current`, reloads PHP-FPM and restarts the queue worker.
6. Checks `/up`. If that fails, the previous release goes back live automatically and the deploy is reported as failed.

Production deploys show the commit and say whether it's the one tested on staging before asking to continue.

**Rollback** switches the code only. Database migrations stay applied. Primora's migrations add columns and tables, so older code keeps working with them. For a real data problem, restore the pre-deploy backup the deploy printed:

```bash
gunzip < /var/backups/primora/production/predeploy-….sql.gz | sudo mariadb primora
```

## Backups

Every night at 02:30, each environment's database and uploaded files (`storage/app`) are saved to `/var/backups/primora/<env>/`. The last 14 are kept, plus the last 10 pre-deploy database backups.

These copies are **on the same server**. Also copy them off it: turn on Contabo's snapshot / backup add-on, or regularly download them (`scp -r root@SERVER_IP:/var/backups/primora .`).

## Troubleshooting

| Problem | Look at |
|---|---|
| Error page / 500 | `sudo primora logs <env>` |
| 502 Bad Gateway | `systemctl status php-fpm`, `/var/log/php-fpm/error.log` |
| nginx | `/var/log/nginx/primora-<env>.error.log`, `nginx -t` |
| Jobs or reminders not running | `systemctl status primora-queue@<env>`, `systemctl list-timers 'primora*'` |
| "Permission denied" writing files | SELinux: `ausearch -m avc -ts recent`; fix labels with `restorecon -R /var/www/primora` |
| No certificate | `sudo primora ssl <env>` (DNS must point here first) |
| Deploy says another command is running | a deploy is in progress; each environment takes one at a time |

The scheduler runs in UTC (`config/app.php`), so the 09:00 SMS reminders go out at 12:00 Kampala time.

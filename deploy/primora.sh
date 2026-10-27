#!/bin/bash
# ================================================================
# Primora deployment tool. Run it through the `primora` command,
# which setup-server.sh installs (it fetches these scripts first):
#
#   sudo primora deploy  <staging|production> [branch|tag|commit]
#   sudo primora promote                 deploy what's live on staging to production
#   sudo primora rollback <env>          switch back to the previous release
#   sudo primora status                  releases, services, certificates
#   sudo primora releases <env>          list releases on the server
#   sudo primora env <env>               edit .env, then reload
#   sudo primora reload <env>            re-cache config, restart PHP and workers
#   sudo primora artisan <env> ...       run an artisan command (e.g. staff:create-admin)
#   sudo primora logs <env>              follow the application log
#   sudo primora ssl <env>               get the HTTPS certificate (needs DNS)
#   sudo primora backup <env>            back up the database and uploaded files now
#   sudo primora down|up <env>           maintenance mode on / off
#
# Layout per environment (/var/www/primora/<env>):
#   releases/<time>-<commit>/   one folder per deploy (last 5 kept)
#   shared/.env, shared/storage linked into every release
#   current -> releases/...     what nginx and the workers run
# ================================================================
set -euo pipefail
shopt -u patsub_replacement 2>/dev/null || true   # keep '&' literal in ${x//a/b} (bash 5.2)

TOOLS="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TEMPLATES="$TOOLS/templates"
[[ -f "$TEMPLATES/env.template" ]] || { echo "Run this through the 'primora' command (sudo primora ...), not as a copied file." >&2; exit 1; }
. /etc/primora/primora.conf

DEPLOY_USER="primora"
DEPLOY_HOME="/var/lib/primora"
REPO="$DEPLOY_HOME/repo.git"
WWW="/var/www/primora"
BACKUPS="/var/backups/primora"
PHP="/usr/bin/php"
COMPOSER="/usr/local/bin/composer"
KEEP_RELEASES=5
KEEP_DAILY_BACKUPS=14
KEEP_PREDEPLOY_BACKUPS=10
LAST_BACKUP="none"

# ── Output helpers ──────────────────────────────────────────────
step() { echo; echo -e "\033[1;34m==> $*\033[0m"; }
log()  { echo "    $*"; }
ok()   { echo -e "\033[1;32m    ✓ $*\033[0m"; }
warn() { echo -e "\033[1;33m    ! $*\033[0m"; }
die()  { echo -e "\033[1;31mERROR: $*\033[0m" >&2; exit 1; }
confirm() { local a; read -r -p "    $1 [y/N] " a; [[ "$a" == "y" || "$a" == "Y" ]]; }

as_deploy() { runuser -u "$DEPLOY_USER" -- env HOME="$DEPLOY_HOME" "$@"; }
git_repo()  { as_deploy git -C "$REPO" "$@"; }

# Fills {{KEY}} placeholders from the named shell variables.
render() {
    local text; text="$(cat "$1")"; shift
    local name
    for name in "$@"; do text="${text//\{\{$name\}\}/${!name}}"; done
    printf '%s\n' "$text"
}

# ── Environment ─────────────────────────────────────────────────
load_env() {
    ENV="${1:-}"
    case "$ENV" in
        staging)    DOMAIN="staging.primoraproptech.com"; DB_NAME="primora_staging"; DB_USER="primora_staging"; MAX_CHILDREN=6;  LOG_LEVEL="debug";   YO_MODE="sandbox" ;;
        production) DOMAIN="app.primoraproptech.com";     DB_NAME="primora";         DB_USER="primora";         MAX_CHILDREN=20; LOG_LEVEL="warning"; YO_MODE="production" ;;
        *) die "Environment must be 'staging' or 'production'." ;;
    esac
    BRANCH="main"
    ENV_CONF="/etc/primora/$ENV.conf"
    # Per-environment overrides (DOMAIN, BRANCH, MAX_CHILDREN), written on first deploy.
    [[ -f "$ENV_CONF" ]] && . "$ENV_CONF"
    APP_USER="primora-$ENV"
    BASE="$WWW/$ENV"
    RELEASES="$BASE/releases"
    SHARED="$BASE/shared"
    CURRENT="$BASE/current"
}

lock_env() {
    exec 9>"/run/primora-$ENV.lock"
    flock -n 9 || die "Another primora command is already running for $ENV."
}

artisan_in() { local dir="$1"; shift; (cd "$dir" && runuser -u "$APP_USER" -- "$PHP" artisan "$@"); }
artisan()    { [[ -L "$CURRENT" ]] || die "$ENV has no release yet: run 'primora deploy $ENV' first."; artisan_in "$(readlink -f "$CURRENT")" "$@"; }

db_has_tables() {
    [[ "$(mariadb -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$DB_NAME'" 2>/dev/null || echo 0)" -gt 0 ]]
}

# ── First-install pieces (each is skipped when already done) ────
init_env() {
    step "Preparing $ENV ($DOMAIN)"

    if [[ ! -f "$ENV_CONF" ]]; then
        cat > "$ENV_CONF" <<EOF
# Primora $ENV settings (read by the primora command)
DOMAIN="$DOMAIN"
# Branch deployed when 'primora deploy $ENV' is run without one
BRANCH="$BRANCH"
# PHP-FPM workers for this site
MAX_CHILDREN=$MAX_CHILDREN
EOF
        log "Wrote $ENV_CONF"
    fi

    id "$APP_USER" &>/dev/null || { useradd --system --no-create-home --home-dir "$BASE" --shell /sbin/nologin "$APP_USER"; log "Created user $APP_USER"; }

    install -d -m 755 "$BASE" "$SHARED"
    install -d -o "$DEPLOY_USER" -g "$DEPLOY_USER" -m 755 "$RELEASES"
    # storage: the app user writes, nginx (group) reads public uploads; nobody else.
    local d
    for d in storage storage/app storage/app/public storage/app/private storage/framework \
             storage/framework/cache storage/framework/cache/data storage/framework/sessions \
             storage/framework/views storage/logs; do
        install -d -o "$APP_USER" -g nginx -m 2770 "$SHARED/$d"
    done
    install -d -m 700 "$BACKUPS/$ENV"
    install -d -m 711 /etc/primora/keys   # Yo! signing keys (see .env)

    if [[ ! -s "$SHARED/.env" ]]; then   # missing or empty
        local DB_PASSWORD APP_KEY WEBSITE_API_KEY
        DB_PASSWORD="$(openssl rand -hex 24)"
        APP_KEY="base64:$(openssl rand -base64 32)"
        WEBSITE_API_KEY="$(openssl rand -hex 32)"
        mariadb -e "CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
                    CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASSWORD';
                    ALTER USER '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASSWORD';
                    GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';
                    FLUSH PRIVILEGES;"
        # Written to a temp file first, so a failure never leaves a half-written .env behind.
        ( umask 077
          render "$TEMPLATES/env.template" ENV DOMAIN APP_KEY LOG_LEVEL DB_NAME DB_USER DB_PASSWORD WEBSITE_API_KEY YO_MODE APP_USER > "$SHARED/.env.new" )
        mv -f "$SHARED/.env.new" "$SHARED/.env"
        chown "root:$APP_USER" "$SHARED/.env"
        chmod 640 "$SHARED/.env"
        ok "Database $DB_NAME, user $DB_USER and $SHARED/.env created (passwords generated)."
    fi

    # PHP-FPM pool (rewritten each deploy so template changes apply).
    local pool="/etc/php-fpm.d/primora-$ENV.conf" new
    new="$(render "$TEMPLATES/php-fpm-pool.conf" ENV APP_USER MAX_CHILDREN)"
    if [[ ! -f "$pool" || "$(cat "$pool")" != "$new" ]]; then
        printf '%s\n' "$new" > "$pool"
        php-fpm -t >/dev/null 2>&1 || { php-fpm -t; die "PHP-FPM config test failed."; }
        systemctl reload php-fpm
        log "PHP-FPM pool primora-$ENV ready."
    fi

    # nginx site (only written when missing, so Certbot's edits are kept).
    local site="/etc/nginx/conf.d/primora-$ENV.conf"
    if [[ ! -f "$site" ]]; then
        local EXTRA_HEADERS=""
        [[ "$ENV" == "staging" ]] && EXTRA_HEADERS='    add_header X-Robots-Tag "noindex, nofollow" always;'
        render "$TEMPLATES/nginx.conf" ENV DOMAIN EXTRA_HEADERS > "$site"
        nginx -t -q || die "nginx config test failed (see above)."
        systemctl reload nginx
        log "nginx site for $DOMAIN added."
    fi

    # systemd units (templated; copied each deploy).
    local unit changed=0
    for unit in primora-queue@.service primora-scheduler@.service primora-scheduler@.timer primora-backup@.service primora-backup@.timer; do
        if ! cmp -s "$TEMPLATES/$unit" "/etc/systemd/system/$unit"; then
            install -m 644 "$TEMPLATES/$unit" "/etc/systemd/system/$unit"; changed=1
        fi
    done
    (( changed )) && systemctl daemon-reload
    return 0
}

# ── Releases ────────────────────────────────────────────────────
health_check() {
    local url code i
    if [[ -d "/etc/letsencrypt/live/$DOMAIN" ]]; then
        url=(--resolve "$DOMAIN:443:127.0.0.1" "https://$DOMAIN/up")
    else
        url=(-H "Host: $DOMAIN" "http://127.0.0.1/up")
    fi
    for i in 1 2 3; do
        code=$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 "${url[@]}" || true)
        [[ "$code" == "200" ]] && return 0
        sleep 2
    done
    warn "Health check got HTTP $code from /up"
    return 1
}

activate() {
    local release="$1"
    ln -sfn "$release" "$BASE/current.new"
    mv -Tf "$BASE/current.new" "$CURRENT"
    systemctl reload php-fpm   # also clears OPcache
    artisan_in "$release" queue:restart >/dev/null 2>&1 || true
    systemctl enable --quiet primora-queue@"$ENV" primora-scheduler@"$ENV".timer primora-backup@"$ENV".timer
    systemctl restart primora-queue@"$ENV"
    systemctl start primora-scheduler@"$ENV".timer primora-backup@"$ENV".timer
}

release_info() { [[ -f "$1/RELEASE" ]] && cat "$1/RELEASE" || basename "$1"; }

cmd_deploy() {
    load_env "${1:-}"; lock_env
    local ref="${2:-$BRANCH}" sha short subject
    [[ -d "$REPO" ]] || die "No repository: run deploy/setup-server.sh first."

    sha="$(git_repo rev-parse --verify --quiet "$ref^{commit}")" || die "Can't find '$ref' in the repository (branch, tag or commit)."
    short="${sha:0:9}"
    subject="$(git_repo log -1 --format='%s' "$sha")"

    if [[ "$ENV" == "production" ]]; then
        local staged=""
        [[ -f "$WWW/staging/current/RELEASE" ]] && staged="$(grep -o 'commit [0-9a-f]*' "$WWW/staging/current/RELEASE" | cut -d' ' -f2)"
        echo
        log "Deploying to PRODUCTION ($DOMAIN):"
        log "  $short  $subject"
        if [[ -n "$staged" && "$staged" == "$sha" ]]; then
            ok "This is the commit live on staging."
        else
            warn "This is NOT what's live on staging${staged:+ (staging has ${staged:0:9})}. 'primora promote' deploys the staged commit."
        fi
        [[ "${PRIMORA_YES:-}" == "1" ]] || confirm "Continue?" || die "Cancelled."
    fi

    init_env

    local stamp release
    stamp="$(date +%Y%m%d-%H%M%S)"
    release="$RELEASES/$stamp-$short"

    step "1/7 Fetching $short ($subject)"
    install -d -o "$DEPLOY_USER" -g "$DEPLOY_USER" -m 755 "$release"
    # Until it goes live, a failed deploy removes its half-built release.
    trap "rm -rf '$release'" EXIT   # expanded now: $release is local and gone by exit time
    git_repo archive "$sha" | as_deploy tar -x -C "$release"
    rm -rf "$release/storage" "$release/.env" "$release/deploy" "$release/website" "$release/tests"
    ln -s "$SHARED/storage" "$release/storage"
    printf 'commit %s\nref %s\ndeployed %s\nsubject %s\n' "$sha" "$ref" "$(date '+%Y-%m-%d %H:%M:%S %Z')" "$subject" > "$release/RELEASE"

    step "2/7 Installing PHP packages"
    (cd "$release" && as_deploy env COMPOSER_HOME="$DEPLOY_HOME/.composer" "$COMPOSER" install \
        --no-dev --no-scripts --no-interaction --prefer-dist --optimize-autoloader --no-progress --quiet) \
        || die "composer install failed."
    ok "Done."

    step "3/7 Building CSS and JavaScript"
    (cd "$release" && as_deploy npm ci --no-audit --no-fund --loglevel=error >/dev/null && as_deploy npm run build --silent >/dev/null) \
        || die "Asset build failed (run: cd $release && sudo -u $DEPLOY_USER npm run build)."
    rm -rf "$release/node_modules"
    ok "Done."

    step "4/7 Preparing the release"
    # Linked only now: the build runs as the deploy user, which may not read the secrets.
    ln -s "$SHARED/.env" "$release/.env"
    ln -s ../storage/app/public "$release/public/storage"
    chown -R "$APP_USER:$APP_USER" "$release/bootstrap/cache"
    command -v restorecon >/dev/null && restorecon -R "$release" "$SHARED"
    artisan_in "$release" package:discover --no-interaction >/dev/null
    ok "Done."

    step "5/7 Database"
    if db_has_tables; then
        cmd_backup_inner predeploy
    fi
    artisan_in "$release" migrate --force --no-interaction
    ok "Migrations applied."

    step "6/7 Caching config, routes and views"
    artisan_in "$release" view:clear >/dev/null
    artisan_in "$release" optimize --no-interaction >/dev/null
    ok "Done."

    step "7/7 Switching to the new release"
    local previous=""
    [[ -L "$CURRENT" ]] && previous="$(readlink -f "$CURRENT")"
    trap - EXIT
    activate "$release"

    if ! health_check; then
        if [[ -n "$previous" && -d "$previous" ]]; then
            activate "$previous"
            die "The new release failed its health check, so the previous one is back live.
       Database migrations from this deploy stay applied (backup: $LAST_BACKUP).
       See: sudo primora logs $ENV   and   /var/log/nginx/primora-$ENV.error.log"
        fi
        die "The first release failed its health check. See: sudo primora logs $ENV"
    fi
    ok "Live: $short — $subject"

    # Keep the newest releases (never the live or previous one).
    local old
    while read -r old; do
        [[ -n "$old" && "$old" != "$release" && "$old" != "$previous" ]] && rm -rf "$old"
    done < <(ls -1d "$RELEASES"/*/ 2>/dev/null | sed 's:/$::' | sort -r | tail -n +$((KEEP_RELEASES + 1)))

    if [[ ! -d "/etc/letsencrypt/live/$DOMAIN" ]]; then
        cmd_ssl_inner || true
    fi

    first_install_notes
    echo
    echo -e "\033[1;32m Deployed $short to $ENV: https://$DOMAIN\033[0m"
}

first_install_notes() {
    local admins
    admins="$(mariadb -N -e "SELECT COUNT(*) FROM \`$DB_NAME\`.staff_users WHERE role='admin'" 2>/dev/null || echo 0)"
    if [[ "$admins" == "0" ]]; then
        echo
        warn "No staff admin yet. Create yours with:"
        log "  sudo primora artisan $ENV staff:create-admin"
    fi
    if grep -q '^YO_API_USERNAME=$' "$SHARED/.env" || grep -q '^MAIL_MAILER=log' "$SHARED/.env"; then
        warn "Still to fill in .env: Yo! Payments, SMTP and/or SMS details →  sudo primora env $ENV"
    fi
}

cmd_promote() {
    local staged
    [[ -f "$WWW/staging/current/RELEASE" ]] || die "Nothing is deployed to staging yet."
    staged="$(grep -o 'commit [0-9a-f]*' "$WWW/staging/current/RELEASE" | cut -d' ' -f2)"
    cmd_deploy production "$staged"
}

cmd_rollback() {
    load_env "${1:-}"; lock_env
    [[ -L "$CURRENT" ]] || die "$ENV has no release."
    local live target="" r
    live="$(readlink -f "$CURRENT")"
    # The newest release older than the live one.
    while read -r r; do
        [[ "$r" < "$live" ]] && { target="$r"; break; }
    done < <(ls -1d "$RELEASES"/*/ | sed 's:/$::' | sort -r)
    [[ -n "$target" ]] || die "No earlier release on the server."

    echo; log "Live now:"; release_info "$live" | sed 's/^/      /'
    echo; log "Roll back to:"; release_info "$target" | sed 's/^/      /'
    warn "Code only: database changes from later releases stay (backups in $BACKUPS/$ENV)."
    confirm "Roll back $ENV?" || die "Cancelled."
    activate "$target"
    health_check && ok "Rolled back." || warn "Rolled back, but the health check failed: sudo primora logs $ENV"
}

cmd_releases() {
    load_env "${1:-}"
    local live r; live="$(readlink -f "$CURRENT" 2>/dev/null || true)"
    for r in $(ls -1d "$RELEASES"/*/ 2>/dev/null | sed 's:/$::' | sort -r); do
        [[ "$r" == "$live" ]] && echo -e "\033[1;32m* $(basename "$r")  (live)\033[0m" || echo "  $(basename "$r")"
        release_info "$r" | sed -n 's/^subject /      /p'
    done
}

cmd_status() {
    local e
    for e in staging production; do
        load_env "$e"
        echo; echo -e "\033[1m$ENV — $DOMAIN\033[0m"
        if [[ ! -L "$CURRENT" ]]; then log "not installed"; continue; fi
        release_info "$(readlink -f "$CURRENT")" | sed 's/^/    /'
        log "queue worker: $(systemctl is-active primora-queue@"$ENV" || true)   scheduler: $(systemctl is-active primora-scheduler@"$ENV".timer || true)   backups: $(systemctl is-active primora-backup@"$ENV".timer || true)"
        if [[ -f "/etc/letsencrypt/live/$DOMAIN/cert.pem" ]]; then
            log "certificate: $(openssl x509 -enddate -noout -in "/etc/letsencrypt/live/$DOMAIN/cert.pem" | cut -d= -f2)"
        else
            log "certificate: none (sudo primora ssl $ENV)"
        fi
        health_check >/dev/null 2>&1 && log "health: OK" || log "health: FAILING"
    done
    echo; log "disk: $(df -h / | awk 'NR==2 {print $4 " free of " $2}')   memory: $(free -h | awk '/Mem:/ {print $7 " available of " $2}')"
}

cmd_ssl_inner() {
    step "HTTPS certificate for $DOMAIN"
    if [[ -d "/etc/letsencrypt/live/$DOMAIN" ]]; then ok "Already has one."; return 0; fi
    local server_ip dns_ip
    server_ip="$(curl -fsS4 --max-time 10 https://api.ipify.org 2>/dev/null || true)"
    dns_ip="$(dig +short A "$DOMAIN" @1.1.1.1 2>/dev/null | tail -1 || true)"
    if [[ -z "$dns_ip" || "$dns_ip" != "$server_ip" ]]; then
        warn "$DOMAIN points to '${dns_ip:-nothing}', but this server is ${server_ip:-unknown}."
        log "Add an A record for $DOMAIN → $server_ip, wait a few minutes, then: sudo primora ssl $ENV"
        return 1
    fi
    if [[ -z "${CERTBOT_EMAIL:-}" ]]; then
        read -r -p "    Email for Let's Encrypt expiry notices: " CERTBOT_EMAIL
        [[ "$CERTBOT_EMAIL" == *@* ]] || { warn "No email given; skipped."; return 1; }
        sed -i "s|^CERTBOT_EMAIL=.*|CERTBOT_EMAIL=\"$CERTBOT_EMAIL\"|" /etc/primora/primora.conf
    fi
    certbot --nginx -d "$DOMAIN" --non-interactive --agree-tos -m "$CERTBOT_EMAIL" --redirect --quiet \
        || { warn "Certbot failed (see /var/log/letsencrypt/letsencrypt.log)."; return 1; }
    ok "https://$DOMAIN is live; renewal is automatic."
}

cmd_ssl() { load_env "${1:-}"; cmd_ssl_inner; }

# Backs up the database and uploaded files. kind: manual | daily | predeploy
cmd_backup_inner() {
    local kind="${1:-manual}" stamp dir
    stamp="$(date +%Y%m%d-%H%M%S)"; dir="$BACKUPS/$ENV"
    install -d -m 700 "$dir"
    LAST_BACKUP="$dir/$kind-$stamp.sql.gz"
    mariadb-dump --single-transaction --quick --routines --triggers "$DB_NAME" | gzip > "$LAST_BACKUP"
    chmod 600 "$LAST_BACKUP"
    if [[ "$kind" != "predeploy" ]]; then
        tar -czf "$dir/$kind-$stamp-files.tar.gz" -C "$SHARED/storage" app
        chmod 600 "$dir/$kind-$stamp-files.tar.gz"
    fi
    local keep=$KEEP_DAILY_BACKUPS
    [[ "$kind" == "predeploy" ]] && keep=$KEEP_PREDEPLOY_BACKUPS
    # `|| true`: ls fails when there are none yet (predeploy has no file backups), which pipefail would make fatal.
    ls -1t "$dir/$kind-"*.sql.gz 2>/dev/null | tail -n +$((keep + 1)) | xargs -r rm -f || true
    ls -1t "$dir/$kind-"*-files.tar.gz 2>/dev/null | tail -n +$((keep + 1)) | xargs -r rm -f || true
    log "Backup: $LAST_BACKUP ($(du -h "$LAST_BACKUP" | cut -f1))"
}

cmd_backup() { load_env "${1:-}"; cmd_backup_inner "${2:-manual}"; }

cmd_reload() {
    load_env "${1:-}"
    artisan optimize:clear >/dev/null
    artisan optimize --no-interaction >/dev/null
    systemctl reload php-fpm
    artisan queue:restart >/dev/null 2>&1 || true
    systemctl restart primora-queue@"$ENV"
    ok "$ENV reloaded."
}

cmd_env() {
    load_env "${1:-}"
    [[ -f "$SHARED/.env" ]] || die "$ENV isn't installed yet."
    local before; before="$(sha256sum "$SHARED/.env")"
    "${EDITOR:-nano}" "$SHARED/.env"
    if [[ "$(sha256sum "$SHARED/.env")" != "$before" ]]; then
        chown "root:$APP_USER" "$SHARED/.env"; chmod 640 "$SHARED/.env"
        [[ -L "$CURRENT" ]] && cmd_reload "$ENV"
    else
        log "No changes."
    fi
}

cmd_logs() {
    load_env "${1:-}"
    local file; file="$(ls -1t "$SHARED/storage/logs/"laravel*.log 2>/dev/null | head -1 || true)"
    [[ -n "$file" ]] || die "No log file yet."
    tail -n 100 -f "$file"
}

usage() { sed -n '3,19p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'; }

command="${1:-}"; shift || true
case "$command" in
    deploy)   cmd_deploy "$@" ;;
    promote)  cmd_promote ;;
    rollback) cmd_rollback "$@" ;;
    releases) cmd_releases "$@" ;;
    status)   cmd_status ;;
    ssl)      cmd_ssl "$@" ;;
    backup)   cmd_backup "$@" ;;
    reload)   cmd_reload "$@" ;;
    env)      cmd_env "$@" ;;
    logs)     cmd_logs "$@" ;;
    artisan)  load_env "${1:-}"; shift; artisan "$@" ;;
    down)     load_env "${1:-}"; artisan down --retry=60 ;;
    up)       load_env "${1:-}"; artisan up ;;
    *)        usage; [[ -z "$command" ]] || exit 1 ;;
esac

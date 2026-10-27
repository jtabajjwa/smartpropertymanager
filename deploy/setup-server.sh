#!/bin/bash
# ================================================================
# Primora — one-time server setup (EL10: AlmaLinux / Rocky / RHEL 10)
#
#   Copy to the server and run as root:
#     scp deploy/setup-server.sh root@SERVER_IP:/root/
#     ssh root@SERVER_IP 'bash /root/setup-server.sh'
#
# Installs nginx, PHP 8.4 (Remi), MariaDB, Node.js, Composer and
# Certbot; sets up the firewall, SELinux, a GitHub deploy key and the
# `primora` command. Safe to run again: each step checks what's done.
#
# Then deploy with:  sudo primora deploy staging
# ================================================================
set -euo pipefail

REPO_URL_DEFAULT="git@github.com:jtabajjwa/smartpropertymanager.git"
DEPLOY_USER="primora"
DEPLOY_HOME="/var/lib/primora"
REPO="$DEPLOY_HOME/repo.git"
WWW="/var/www/primora"
CONF_DIR="/etc/primora"
# GitHub's published ed25519 host key fingerprint (docs.github.com, "GitHub's SSH key fingerprints").
GITHUB_ED25519_FP="SHA256:+DiY3wvvV6TuJJhbpZisF/zLDA0zPMSvHdkr4UvCOqU"

step() { echo; echo -e "\033[1;34m==> $*\033[0m"; }
log()  { echo "    $*"; }
warn() { echo -e "\033[1;33m    ! $*\033[0m"; }
die()  { echo -e "\033[1;31mERROR: $*\033[0m" >&2; exit 1; }

[[ $EUID -eq 0 ]] || die "Run as root (sudo bash $0)."
. /etc/os-release
[[ "${VERSION_ID%%.*}" == "10" && ( "$ID" == "rhel" || " ${ID_LIKE:-} " == *" rhel "* ) ]] \
    || die "This script is for Enterprise Linux 10 (found: $PRETTY_NAME)."

# ── 1. Base packages ────────────────────────────────────────────
step "1/11 Updating the system and installing base packages"
dnf -y -q upgrade --refresh
dnf -y -q install dnf-plugins-core
if [[ "$ID" == "rhel" ]]; then
    subscription-manager repos --enable "codeready-builder-for-rhel-10-$(arch)-rpms" || true
    rpm -q epel-release >/dev/null || dnf -y -q install https://dl.fedoraproject.org/pub/epel/epel-release-latest-10.noarch.rpm
else
    dnf config-manager --set-enabled crb
    rpm -q epel-release >/dev/null || dnf -y -q install epel-release
fi
dnf -y -q install git tar unzip curl nano bind-utils policycoreutils-python-utils \
    firewalld nginx mariadb-server nodejs npm certbot python3-certbot-nginx \
    fail2ban dnf-automatic openssl
log "Done."

# ── 2. PHP 8.4 from Remi ────────────────────────────────────────
step "2/11 Installing PHP 8.4 (Remi repository)"
rpm -q remi-release >/dev/null || dnf -y -q install https://rpms.remirepo.net/enterprise/remi-release-10.rpm
if ! php -r 'exit(version_compare(PHP_VERSION, "8.4.0", ">=") ? 0 : 1);' 2>/dev/null; then
    dnf -y -q module reset php || true
    dnf -y -q module enable php:remi-8.4 || die "Could not enable PHP 8.4 from Remi (dnf module enable php:remi-8.4)."
fi
dnf -y -q install php-cli php-fpm php-common php-mysqlnd php-pdo php-gd php-zip php-intl \
    php-mbstring php-bcmath php-xml php-opcache php-process php-sodium
php -r 'exit(version_compare(PHP_VERSION, "8.4.0", ">=") ? 0 : 1);' || die "php is $(php -r 'echo PHP_VERSION;'); 8.4 is required."
missing=()
# Read the list once: `php -m | grep -q` can fail at random under pipefail
# (grep exits early, php gets SIGPIPE).
loaded="$(php -m 2>/dev/null)"
for ext in pdo_mysql gd zip intl mbstring bcmath dom xml curl fileinfo openssl sodium pcntl posix; do
    grep -qix "$ext" <<<"$loaded" || missing+=("$ext")
done
if (( ${#missing[@]} )); then
    warn "PHP extensions not loading: ${missing[*]}. Details:"
    php -m 2>&1 >/dev/null | grep -i -E 'warning|unable|error' | head -5 | sed 's/^/      /' || true
    dnf list --installed 'php*' 2>/dev/null | sed 's/^/      /'
    die "Fix the extensions above (see deploy/README.md, Troubleshooting), then run this script again."
fi
cat > /etc/php.d/99-primora.ini <<'EOF'
; Primora (written by deploy/setup-server.sh)
expose_php = Off
date.timezone = UTC
memory_limit = 256M
upload_max_filesize = 20M
post_max_size = 25M
opcache.enable = 1
opcache.memory_consumption = 192
opcache.interned_strings_buffer = 16
opcache.max_accelerated_files = 20000
opcache.validate_timestamps = 1
opcache.revalidate_freq = 2
realpath_cache_size = 4096K
realpath_cache_ttl = 600
EOF
log "$(php -r 'echo "PHP ", PHP_VERSION;')"

# ── 3. Composer ─────────────────────────────────────────────────
step "3/11 Installing Composer"
if [[ ! -x /usr/local/bin/composer ]]; then
    tmp=$(mktemp -d)
    expected=$(curl -fsSL https://composer.github.io/installer.sig)
    curl -fsSL https://getcomposer.org/installer -o "$tmp/composer-setup.php"
    actual=$(php -r "echo hash_file('sha384', '$tmp/composer-setup.php');")
    [[ "$expected" == "$actual" ]] || die "Composer installer checksum mismatch."
    php "$tmp/composer-setup.php" --quiet --install-dir=/usr/local/bin --filename=composer
    rm -rf "$tmp"
else
    /usr/local/bin/composer self-update --quiet || true
fi
log "$(/usr/local/bin/composer --version 2>/dev/null | head -1)"
node -e 'const [a,b]=process.versions.node.split(".").map(Number); process.exit(a>22||(a===22&&b>=12)||(a===20&&b>=19)?0:1)' \
    || die "Node.js $(node -v) is too old for Vite 7 (needs 20.19+ or 22.12+)."
log "Node $(node -v)"

# ── 4. Swap (npm builds need memory) ────────────────────────────
step "4/11 Checking swap"
if [[ $(swapon --show --noheadings | wc -l) -eq 0 && $(awk '/MemTotal/ {print $2}' /proc/meminfo) -lt 8000000 ]]; then
    fallocate -l 2G /swapfile && chmod 600 /swapfile && mkswap -q /swapfile && swapon /swapfile
    grep -q '^/swapfile' /etc/fstab || echo '/swapfile none swap defaults 0 0' >> /etc/fstab
    log "Added a 2 GB swap file."
else
    log "Swap not needed or already present."
fi

# ── 5. MariaDB ──────────────────────────────────────────────────
step "5/11 Configuring MariaDB"
cat > /etc/my.cnf.d/primora.cnf <<'EOF'
# Primora (written by deploy/setup-server.sh)
[mysqld]
bind-address = 127.0.0.1
character-set-server = utf8mb4
collation-server = utf8mb4_unicode_ci
EOF
systemctl enable --now mariadb >/dev/null
systemctl restart mariadb
# Same clean-up as mysql_secure_installation. root signs in through the unix socket (sudo mariadb).
mariadb -e "DELETE FROM mysql.global_priv WHERE User=''; DELETE FROM mysql.global_priv WHERE User='root' AND Host NOT IN ('localhost','127.0.0.1','::1'); DROP DATABASE IF EXISTS test; FLUSH PRIVILEGES;"
log "MariaDB $(mariadb -N -e 'SELECT VERSION()') listening on 127.0.0.1 only."

# ── 6. nginx, PHP-FPM ───────────────────────────────────────────
step "6/11 Configuring nginx and PHP-FPM"
cat > /etc/nginx/conf.d/00-primora-base.conf <<'EOF'
# Primora (written by deploy/setup-server.sh)
server_tokens off;
gzip on;
gzip_types text/css application/javascript application/json image/svg+xml text/plain;
gzip_min_length 1024;
EOF
mkdir -p /var/log/php-fpm
systemctl enable --now php-fpm nginx >/dev/null
nginx -t -q && systemctl reload nginx
log "Done."

# ── 7. Firewall, fail2ban, automatic security updates ───────────
step "7/11 Firewall, fail2ban and security updates"
systemctl enable --now firewalld >/dev/null
firewall-cmd -q --permanent --add-service=ssh --add-service=http --add-service=https
# Cockpit (web admin on port 9090) comes open on Contabo images; close it.
# Re-open with: firewall-cmd --permanent --add-service=cockpit && firewall-cmd --reload
firewall-cmd -q --permanent --remove-service=cockpit 2>/dev/null || true
firewall-cmd -q --reload
cat > /etc/fail2ban/jail.d/primora-sshd.local <<'EOF'
[sshd]
enabled = true
maxretry = 5
bantime = 1h
EOF
systemctl enable --now fail2ban >/dev/null
systemctl restart fail2ban
sed -i 's/^upgrade_type.*/upgrade_type = security/; s/^apply_updates.*/apply_updates = yes/' /etc/dnf/automatic.conf
systemctl enable --now dnf-automatic.timer >/dev/null
systemctl enable --now certbot-renew.timer >/dev/null 2>&1 || true
log "Open: $(firewall-cmd --list-services)"

# ── 8. SELinux ──────────────────────────────────────────────────
step "8/11 SELinux"
if selinuxenabled 2>/dev/null; then
    # PHP calls Yo! Payments, the SMS gateway, SMTP and MariaDB over the network.
    setsebool -P httpd_can_network_connect=1 httpd_can_network_connect_db=1 httpd_can_sendmail=1
    add_fcontext() { semanage fcontext -a -t "$1" "$2" 2>/dev/null || semanage fcontext -m -t "$1" "$2"; }
    add_fcontext httpd_sys_rw_content_t "$WWW/[^/]+/shared/storage(/.*)?"
    add_fcontext httpd_sys_rw_content_t "$WWW/[^/]+/releases/[^/]+/bootstrap/cache(/.*)?"
    log "Mode: $(getenforce). Writable paths labelled for PHP."
else
    log "SELinux is off."
fi

# ── 9. Users and folders ────────────────────────────────────────
step "9/11 Deploy user and folders"
id "$DEPLOY_USER" &>/dev/null || useradd --system --home-dir "$DEPLOY_HOME" --create-home --shell /sbin/nologin "$DEPLOY_USER"
install -d -o "$DEPLOY_USER" -g "$DEPLOY_USER" -m 700 "$DEPLOY_HOME" "$DEPLOY_HOME/.ssh"
install -d -m 755 "$WWW"
install -d -m 700 /var/backups/primora
install -d -m 755 "$CONF_DIR"
if [[ ! -f "$CONF_DIR/primora.conf" ]]; then
    cat > "$CONF_DIR/primora.conf" <<EOF
# Primora deployment settings (read by the primora command)
REPO_URL="$REPO_URL_DEFAULT"
# Branch the primora command loads its own deploy scripts from
TOOLS_REF="main"
# Let's Encrypt account email (asked for the first time a certificate is requested)
CERTBOT_EMAIL=""
EOF
fi
. "$CONF_DIR/primora.conf"
log "Deploy user '$DEPLOY_USER' ($DEPLOY_HOME), sites in $WWW."

# ── 10. GitHub access ───────────────────────────────────────────
step "10/11 GitHub deploy key and repository"
as_deploy() { runuser -u "$DEPLOY_USER" -- env HOME="$DEPLOY_HOME" "$@"; }
key="$DEPLOY_HOME/.ssh/id_ed25519"
[[ -f "$key" ]] || as_deploy ssh-keygen -q -t ed25519 -N '' -C "primora-deploy@$(hostname -s)" -f "$key"
known="$DEPLOY_HOME/.ssh/known_hosts"
if ! grep -q '^github.com ssh-ed25519' "$known" 2>/dev/null; then
    scanned=$(ssh-keyscan -t ed25519 github.com 2>/dev/null)
    fp=$(ssh-keygen -lf - <<<"$scanned" | awk '{print $2}')
    [[ "$fp" == "$GITHUB_ED25519_FP" ]] || die "github.com host key fingerprint is $fp, expected $GITHUB_ED25519_FP."
    echo "$scanned" >> "$known"
    chown "$DEPLOY_USER:" "$known"; chmod 644 "$known"
fi
until as_deploy git ls-remote "$REPO_URL" HEAD >/dev/null 2>&1; do
    echo
    echo "    Add this server's deploy key to GitHub (read-only is enough):"
    echo "    github.com → the repository → Settings → Deploy keys → Add deploy key"
    echo
    echo "    $(cat "$key.pub")"
    echo
    read -r -p "    Press Enter once it's added (or type 'skip' to finish later): " answer
    [[ "$answer" == "skip" ]] && { warn "Skipped. Run this script again after adding the key."; break; }
    if ! err=$(as_deploy git ls-remote "$REPO_URL" HEAD 2>&1 >/dev/null); then
        warn "GitHub still refuses ($REPO_URL):"
        echo "$err" | sed 's/^/      /'
    fi
done
if as_deploy git ls-remote "$REPO_URL" HEAD >/dev/null 2>&1; then
    if [[ ! -d "$REPO" ]]; then
        as_deploy git clone --quiet --bare "$REPO_URL" "$REPO"
    fi
    as_deploy git -C "$REPO" remote set-url origin "$REPO_URL"
    as_deploy git -C "$REPO" fetch --quiet --prune origin '+refs/heads/*:refs/heads/*' '+refs/tags/*:refs/tags/*'
    log "Repository ready: $(as_deploy git -C "$REPO" log -1 --format='%h %s' "$TOOLS_REF")"
fi

# ── 11. The primora command ─────────────────────────────────────
step "11/11 Installing the 'primora' command"
cat > /usr/local/sbin/primora <<'EOF'
#!/bin/bash
# Installed by deploy/setup-server.sh. Fetches the latest deploy scripts
# from GitHub, then runs deploy/primora.sh from them.
set -euo pipefail
[[ $EUID -eq 0 ]] || exec sudo "$0" "$@"
. /etc/primora/primora.conf
REPO=/var/lib/primora/repo.git
as_deploy() { runuser -u primora -- env HOME=/var/lib/primora "$@"; }
[[ -d "$REPO" ]] || { echo "No repository yet: run deploy/setup-server.sh again." >&2; exit 1; }
if [[ -z "${PRIMORA_OFFLINE:-}" ]]; then
    as_deploy git -C "$REPO" fetch --quiet --prune origin '+refs/heads/*:refs/heads/*' '+refs/tags/*:refs/tags/*' \
        || echo "Warning: couldn't reach GitHub; using the last fetched code." >&2
fi
tools=$(mktemp -d /tmp/primora-tools.XXXXXX)
trap 'rm -rf "$tools"' EXIT
as_deploy git -C "$REPO" archive "${TOOLS_REF:-main}" deploy | tar -x -C "$tools"
bash "$tools/deploy/primora.sh" "$@"
EOF
chmod 755 /usr/local/sbin/primora
# sudo's secure_path on EL leaves out /usr/local/sbin, so link it where `sudo primora` finds it.
ln -sf /usr/local/sbin/primora /usr/bin/primora
log "Installed /usr/local/sbin/primora (linked as /usr/bin/primora)"

echo
echo -e "\033[1;32m================================================================\033[0m"
echo -e "\033[1;32m Server ready.\033[0m"
echo -e "\033[1;32m================================================================\033[0m"
echo
echo " Next:"
echo "   1. DNS: point staging.primoraproptech.com (A record) to $(curl -fsS4 --max-time 5 https://api.ipify.org 2>/dev/null || echo 'this server')"
echo "   2. sudo primora deploy staging"
echo
if [[ -f /var/run/reboot-required ]] || ! needs-restarting -r >/dev/null 2>&1; then
    warn "Updates installed a new kernel or core libraries: reboot when convenient (sudo reboot)."
fi

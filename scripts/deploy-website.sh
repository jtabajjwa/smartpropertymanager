#!/usr/bin/env bash
#
# Deploy the marketing website (./website) to cPanel over FTPS.
#
#   scripts/deploy-website.sh plan   # back up live files + show exactly what would change
#   scripts/deploy-website.sh push   # same, then upload new/changed files
#
# Credentials come from .deploy/website-ftp.env (git-ignored), read literally
# so passwords containing $ or other symbols are safe. The password is only
# passed to curl via stdin, never on the command line.
#
# Safety:
#   - TLS required, certificate verified.
#   - Never deletes anything on the server.
#   - Never uploads secrets/local-only files (config.local.php, cache, README, .gitignore).
#   - Every run first downloads the live versions of the managed files to
#     .deploy/backups/<timestamp>/ so a deploy can be rolled back.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SITE="$ROOT/website"
ENV_FILE="$ROOT/.deploy/website-ftp.env"
MODE="${1:-plan}"

[[ "$MODE" == "plan" || "$MODE" == "push" ]] || { echo "usage: $0 plan|push" >&2; exit 2; }
[[ -f "$ENV_FILE" ]] || { echo "Missing $ENV_FILE" >&2; exit 1; }

envval() { grep "^$1=" "$ENV_FILE" | head -1 | cut -d= -f2- | tr -d '\r'; }
HOST="$(envval FTP_HOST)"; PORT="$(envval FTP_PORT)"; USER_="$(envval FTP_USER)"; PASS="$(envval FTP_PASSWORD)"
REMOTE="$(envval FTP_REMOTE_DIR)"; REMOTE="/${REMOTE#/}"; REMOTE="${REMOTE%/}"
[[ -n "$HOST" && -n "$USER_" && -n "$PASS" ]] || { echo "FTP_HOST, FTP_USER and FTP_PASSWORD must be set in $ENV_FILE" >&2; exit 1; }
case "$PASS" in *'"'*|*'\'*) echo "Passwords containing \" or \\ aren't supported by this script." >&2; exit 1;; esac

BASE="ftp://$HOST:${PORT:-21}$REMOTE"

# curl with credentials fed through stdin (-K -), TLS mandatory.
ftp() { printf 'user = "%s:%s"\n' "$USER_" "$PASS" | curl -sS -K - --ssl-reqd --connect-timeout 15 --max-time 120 "$@"; }

# Files that make up the site (relative to ./website), minus local-only ones.
mapfile -t FILES < <(cd "$SITE" && find . -type f \
    ! -name 'config.local.php' ! -name 'config.local.example.php' \
    ! -name 'README.md' ! -name '.gitignore' \
    ! -path './cache/*.json' ! -name '*.tmp' \
    | sed 's#^\./##' | sort)

STAMP="$(date +%Y%m%d-%H%M%S)"
BACKUP="$ROOT/.deploy/backups/$STAMP"
mkdir -p "$BACKUP"

echo "Server: $HOST  folder: $REMOTE  ($([[ $MODE == push ]] && echo PUSH || echo 'plan only, nothing uploaded'))"
echo "Backing up live copies to .deploy/backups/$STAMP/"
echo

declare -a NEW=() CHANGED=() SAME=()
for f in "${FILES[@]}"; do
    mkdir -p "$BACKUP/$(dirname "$f")"
    if ftp -o "$BACKUP/$f" "$BASE/$f" 2>/dev/null; then
        if cmp -s "$SITE/$f" "$BACKUP/$f"; then SAME+=("$f"); else CHANGED+=("$f"); fi
    else
        rm -f "$BACKUP/$f"
        NEW+=("$f")
    fi
done
find "$BACKUP" -type d -empty -delete 2>/dev/null || true

printf '  %-9s %s\n' "SAME" "${#SAME[@]} file(s)"
for f in "${CHANGED[@]}"; do printf '  %-9s %s\n' "CHANGED" "$f"; done
for f in "${NEW[@]}"; do printf '  %-9s %s\n' "NEW" "$f"; done
echo

TO_UPLOAD=("${CHANGED[@]}" "${NEW[@]}")
if [[ ${#TO_UPLOAD[@]} -eq 0 ]]; then
    echo "Live site already matches. Nothing to upload."
    exit 0
fi

if [[ "$MODE" == "plan" ]]; then
    echo "${#TO_UPLOAD[@]} file(s) would be uploaded. Run with 'push' to deploy."
    exit 0
fi

echo "Uploading ${#TO_UPLOAD[@]} file(s)..."
for f in "${TO_UPLOAD[@]}"; do
    ftp --ftp-create-dirs -T "$SITE/$f" "$BASE/$f"
    printf '  uploaded  %s\n' "$f"
done

echo
echo "Done. To roll back, re-upload the files in .deploy/backups/$STAMP/"

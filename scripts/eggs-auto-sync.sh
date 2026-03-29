#!/usr/bin/env bash
set -Eeuo pipefail

SITE_DIR="/www/wwwroot/eggs.ryhnsolutions.shop"
CACHE_DIR="/www/git-cache/egg1.3"
RELEASE_DIR="/www/git-cache/egg1.3-release"
STATE_DIR="/www/git-cache/egg1.3-state"
LOCK_DIR="/tmp/eggs-auto-sync.lock"
REPO_URL="https://github.com/BBQ25/egg1.3.git"
BRANCH="main"
PHP_BIN="/www/server/php/83/bin/php"
COMPOSER_BIN="/usr/bin/composer"
NPM_BIN="/usr/bin/npm"

log() {
    printf '[eggs-auto-sync] %s\n' "$*"
}

fail() {
    log "ERROR: $*"
    exit 1
}

cleanup() {
    rm -rf "$LOCK_DIR" "$RELEASE_DIR"
}

if ! mkdir "$LOCK_DIR" 2>/dev/null; then
    log "Another deployment is already running; exiting."
    exit 0
fi

trap cleanup EXIT

[ -d "$SITE_DIR" ] || fail "Missing site directory: $SITE_DIR"
[ -f "$SITE_DIR/.env" ] || fail "Missing production .env in $SITE_DIR"

mkdir -p "$(dirname "$CACHE_DIR")" "$STATE_DIR"

if [ ! -d "$CACHE_DIR/.git" ]; then
    log "Cloning repository cache..."
    rm -rf "$CACHE_DIR"
    git clone --branch "$BRANCH" --single-branch "$REPO_URL" "$CACHE_DIR"
else
    log "Fetching latest GitHub changes..."
    git -C "$CACHE_DIR" fetch origin "$BRANCH" --prune
    git -C "$CACHE_DIR" checkout "$BRANCH"
    git -C "$CACHE_DIR" pull --ff-only origin "$BRANCH"
fi

new_rev="$(git -C "$CACHE_DIR" rev-parse HEAD)"
last_rev="$(cat "$STATE_DIR/last_deployed_rev" 2>/dev/null || true)"

if [ "$new_rev" = "$last_rev" ]; then
    log "No new commit to deploy."
    exit 0
fi

log "Preparing release $new_rev..."
rm -rf "$RELEASE_DIR"
mkdir -p "$RELEASE_DIR"
git -C "$CACHE_DIR" archive --format=tar "$new_rev" | tar -xf - -C "$RELEASE_DIR"
cp "$SITE_DIR/.env" "$RELEASE_DIR/.env"
mkdir -p "$RELEASE_DIR/storage" "$RELEASE_DIR/bootstrap/cache"

log "Installing PHP dependencies..."
"$PHP_BIN" "$COMPOSER_BIN" install \
    --working-dir="$RELEASE_DIR" \
    --no-dev \
    --optimize-autoloader \
    --no-interaction \
    --ignore-platform-req=ext-fileinfo

log "Building frontend assets..."
"$NPM_BIN" --prefix "$RELEASE_DIR" ci --no-audit --no-fund
"$NPM_BIN" --prefix "$RELEASE_DIR" run build

log "Publishing release files to the live site..."
rm -rf "$SITE_DIR/vendor" "$SITE_DIR/public/build"
tar \
    --exclude='.env' \
    --exclude='storage' \
    --exclude='node_modules' \
    -cf - \
    -C "$RELEASE_DIR" . | tar -xf - -C "$SITE_DIR"

log "Running Laravel maintenance tasks..."
cd "$SITE_DIR"
"$PHP_BIN" artisan migrate --force
"$PHP_BIN" artisan optimize:clear
"$PHP_BIN" artisan storage:link >/dev/null 2>&1 || true
chown -R www:www storage bootstrap/cache public/build vendor >/dev/null 2>&1 || true
chmod -R ug+rwx storage bootstrap/cache public/build >/dev/null 2>&1 || true

printf '%s' "$new_rev" > "$STATE_DIR/last_deployed_rev"
log "Deployment complete: $new_rev"

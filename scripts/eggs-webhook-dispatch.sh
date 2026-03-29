#!/usr/bin/env bash
set -Eeuo pipefail

SITE_DIR="/www/wwwroot/eggs.ryhnsolutions.shop"
TRIGGER_FILE="$SITE_DIR/storage/app/deploy/github-webhook-trigger.json"
PROCESSED_FILE="$SITE_DIR/storage/app/deploy/github-webhook-trigger.last"
DEPLOY_LOG="/www/wwwlogs/eggs-auto-sync.log"

log() {
    printf '[eggs-webhook-dispatch] %s\n' "$*"
}

mkdir -p "$(dirname "$TRIGGER_FILE")"

if [ ! -f "$TRIGGER_FILE" ]; then
    exit 0
fi

trigger_hash="$(sha256sum "$TRIGGER_FILE" | awk '{print $1}')"
last_processed_hash="$(cat "$PROCESSED_FILE" 2>/dev/null || true)"

if [ "$trigger_hash" = "$last_processed_hash" ]; then
    exit 0
fi

log "Queued trigger detected ($trigger_hash)." >> "$DEPLOY_LOG"

if /bin/bash "$SITE_DIR/scripts/eggs-auto-sync.sh" >> "$DEPLOY_LOG" 2>&1; then
    printf '%s' "$trigger_hash" > "$PROCESSED_FILE"
    log "Queued trigger processed ($trigger_hash)." >> "$DEPLOY_LOG"
    exit 0
fi

log "Queued trigger failed ($trigger_hash)." >> "$DEPLOY_LOG"
exit 1

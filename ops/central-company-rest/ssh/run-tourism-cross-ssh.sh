#!/usr/bin/env bash
# MAD4B Content Hub: host-mediated read-only WP-CLI Cross SSH transport.
# Runs only on MAD4B VPS host; no SSH keys inside WordPress.
set -euo pipefail
umask 077
MODE="--import"
if test "$#" -gt 0;then MODE="$1";fi
case "$MODE" in --import|--verify-only) ;; *) echo 'Use --import or --verify-only' >&2;exit 79;; esac
KEY_DIR=/opt/hostinger-shell-mcp/external-targets/hosting-1
SRC=u338416126@147.93.49.130
SRC_WP=/home/u338416126/domains/almallahgroup-mg.com/public_html/tourism.almallahgroup-mg.com
CENTRAL_EXPORT=/opt/hostinger-migrations/hosting-3/etg-commerce-staging/runtime/source-providers/tourism/source-export.php
STAGING=etg-commerce-staging-php
WORK=/opt/mad4b-company-release-prep/20261030/tourism-cross-ssh-runs
if test "$(id -u)" != 0; then echo 'VPS root runner required' >&2;exit 80;fi
for f in "$KEY_DIR/id_ed25519" "$KEY_DIR/known_hosts_verified";do
  test -s "$f" && test "$(stat -c %a "$f")" = 600 || { echo 'SSH trust file invalid' >&2;exit 81; }
done
mkdir -p "$WORK";chmod 700 "$WORK"
exec 9>/run/lock/mad4b-tourism-cross-ssh.lock
flock -n 9 || { echo 'Import already running' >&2; exit 82; }
FILE=$(mktemp "$WORK/import.XXXXXXXX.json")
cleanup_import_artifacts() {
  rm -f "$FILE"
  # Manifest is a short-lived private staging artifact, even on failed import.
  docker exec -u root "$STAGING" rm -f /tmp/mad4b-hub-cross-ssh/manifest.json >/dev/null 2>&1 || true
}
trap cleanup_import_artifacts EXIT
test -s "$CENTRAL_EXPORT" && test "$(stat -c %a "$CENTRAL_EXPORT")" = 600 || {
  echo 'Central Staging exporter missing or not private' >&2;exit 86;
}
CENTRAL_HOME=$(docker exec -u www-data "$STAGING" wp --path=/var/www/html --skip-themes --skip-plugins option get home 2>/dev/null)
test "$CENTRAL_HOME" = https://tours.dev.mad4b.com || { echo 'Central hostname mismatch' >&2;exit 83; }
SSH="ssh -i $KEY_DIR/id_ed25519 -o BatchMode=yes -o IdentitiesOnly=yes -o StrictHostKeyChecking=yes -o UserKnownHostsFile=$KEY_DIR/known_hosts_verified -o ConnectTimeout=10 -o PasswordAuthentication=no -p 65002"
SOURCE_HOME=$($SSH "$SRC" "wp --path=$SRC_WP --skip-themes --skip-plugins option get home 2>/dev/null")
test "$SOURCE_HOME" = https://tourism.almallahgroup-mg.com || { echo 'Source hostname mismatch' >&2;exit 84; }
cat "$CENTRAL_EXPORT" | $SSH "$SRC" "wp --path=$SRC_WP --skip-themes eval-file - 2>/dev/null" > "$FILE"
python3 - "$FILE" <<'PY'
import json,sys,os
p=sys.argv[1]
assert 0<os.stat(p).st_size<=27000000, 'Invalid export size'
with open(p,encoding='utf8') as f: m=json.load(f)
assert m.get('contract')=='mad4b.hub.wp-cli-cross-ssh.v1'
assert m.get('source_company')=='tourism-almallahgroup'
assert m.get('canonical_host')=='tourism.almallahgroup-mg.com'
types=['tours-and-activities','properties','transportations']
assert m.get('types')==types and list(m.get('counts',{}))==types
assert all(type(m['counts'][t]) is int and 0<=m['counts'][t]<=2000 for t in types)
assert sum(m['counts'].values())==len(m['items'])
print('SOURCE_EXPORT_VERIFIED='+str(len(m['items'])))
PY
if test "$MODE" = --verify-only; then
  echo 'CENTRAL_CODE_REMOTE_EXECUTION_VERIFIED=YES'
  exit 0
fi
docker exec -u root "$STAGING" mkdir -p /tmp/mad4b-hub-cross-ssh
docker cp "$FILE" "$STAGING":/tmp/mad4b-hub-cross-ssh/manifest.json
docker exec -u root "$STAGING" sh -c 'chown root:www-data /tmp/mad4b-hub-cross-ssh/manifest.json;chmod 640 /tmp/mad4b-hub-cross-ssh/manifest.json;chmod 750 /tmp/mad4b-hub-cross-ssh'
HOST_HASH=$(sha256sum "$FILE" | cut -d' ' -f1)
CONTAINER_HASH=$(docker exec -u root "$STAGING" sha256sum /tmp/mad4b-hub-cross-ssh/manifest.json | cut -d' ' -f1)
test "$HOST_HASH" = "$CONTAINER_HASH" || { echo 'Transfer hash mismatch' >&2;exit 85; }
echo "TRANSFER_SHA256=$HOST_HASH"
docker exec -u www-data "$STAGING" wp --path=/var/www/html --skip-themes eval-file /tmp/mad4b-hub-cross-ssh/central-receive.php 2>/dev/null
docker exec -u root "$STAGING" rm -f /tmp/mad4b-hub-cross-ssh/manifest.json
echo 'CROSS_SSH_IMPORT_FINISHED=YES'

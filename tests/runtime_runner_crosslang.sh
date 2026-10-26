#!/usr/bin/env bash
# Hermetic PHP/OpenSSL -> Python/RSA worker -> Docker -> PHP signed receipt.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DIR="$(mktemp -d)"
trap 'rm -rf "$DIR"' EXIT
chmod 0700 "$DIR"
cp "$ROOT/tools/runtime-runner-worker.py" "$DIR/worker.py"
cp "$ROOT/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-runtime-runner-bridge.php" "$DIR/bridge.php"
openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:2048 -out "$DIR/site-private.pem" 2>/dev/null
openssl rsa -in "$DIR/site-private.pem" -pubout -out "$DIR/site-public.pem" 2>/dev/null
openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:2048 -out "$DIR/worker-private.pem" 2>/dev/null
openssl rsa -in "$DIR/worker-private.pem" -pubout -out "$DIR/worker-public.pem" 2>/dev/null
cat > "$DIR/request.php" <<'PHP'
<?php
define('ABSPATH',__DIR__);
require __DIR__.'/bridge.php';
$envelope=MAD4B_SCP_Runtime_Runner_Bridge::prepare('staging-site-proof',str_repeat('a',64),file_get_contents(__DIR__.'/site-private.pem'));
if (!$envelope) exit(2);
echo json_encode($envelope,JSON_UNESCAPED_SLASHES);
PHP
cat > "$DIR/verify.php" <<'PHP'
<?php
define('ABSPATH',__DIR__);
require __DIR__.'/bridge.php';
$envelope=json_decode(file_get_contents(__DIR__.'/request.json'),true);
$receipt=json_decode(file_get_contents(__DIR__.'/receipt.json'),true);
if (!MAD4B_SCP_Runtime_Runner_Bridge::verify_receipt($receipt,$envelope,file_get_contents(__DIR__.'/worker-public.pem'))) exit(3);
if ($receipt['receipt']['result']!=='ok') exit(4);
echo "PASS: PHP_VERIFIED_SIGNED_DOCKER_RECEIPT\n";
PHP
# No exposed ports or HTTP server, no WordPress live writes.
if command -v php >/dev/null 2>&1; then
  php "$DIR/request.php" > "$DIR/request.json"
else
  docker run --rm --network none --read-only --cap-drop ALL -v "$DIR:/work:ro" -w /work php:8.3-cli php request.php > "$DIR/request.json"
fi
python3 "$DIR/worker.py" --site-key "$DIR/site-public.pem" --worker-key "$DIR/worker-private.pem" --nonce-dir "$DIR/nonces" --enrolled-site staging-site-proof < "$DIR/request.json" > "$DIR/receipt.json"
if command -v php >/dev/null 2>&1; then
  php "$DIR/verify.php"
else
  docker run --rm --network none --read-only --cap-drop ALL -v "$DIR:/work:ro" -w /work php:8.3-cli php verify.php
fi
if python3 "$DIR/worker.py" --site-key "$DIR/site-public.pem" --worker-key "$DIR/worker-private.pem" --nonce-dir "$DIR/nonces" --enrolled-site staging-site-proof < "$DIR/request.json" >/dev/null 2>&1; then
  echo "FAIL: runner replay was accepted"
  exit 1
fi
echo "PASS: SIGNED_RUNNER_CROSS_LANGUAGE_REPLAY_DENIED"

#!/usr/bin/env bash
set -euo pipefail

ROOT="$(git rev-parse --show-toplevel)"
V6_SHA="${MAD4B_SCHEMA_V6_SHA:-97073e1c1a6a69c951d59c3282e56d1f107e76c2}"
SCHEMA_REL="wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-schema.php"
CURRENT_SCHEMA="$ROOT/$SCHEMA_REL"
WP_CLI="${WP_CLI:-wp}"

tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT

git -C "$ROOT" show "$V6_SHA:$SCHEMA_REL" > "$tmp/schema-v6.php"
grep -Fq "const VERSION = 6;" "$tmp/schema-v6.php"
grep -Fq "const VERSION = 9;" "$CURRENT_SCHEMA"

cat > "$tmp/install-v6.php" <<PHP
<?php
require '$tmp/schema-v6.php';
$result = MAD4B_SCP_Schema::install_or_upgrade();
if ( is_wp_error( $result ) ) {
    fwrite( STDERR, wp_json_encode( array(
        'stage' => 'install_v6',
        'code' => $result->get_error_code(),
        'message' => $result->get_error_message(),
        'data' => $result->get_error_data(),
    ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . PHP_EOL );
    exit( 11 );
}
$status = MAD4B_SCP_Schema::status( true );
echo wp_json_encode( array( 'stage' => 'install_v6', 'status' => $status ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . PHP_EOL;
if ( 6 !== (int) get_option( MAD4B_SCP_Schema::OPTION, 0 ) || empty( $status['ready'] ) ) exit( 12 );
PHP

cat > "$tmp/upgrade-v9.php" <<PHP
<?php
require '$CURRENT_SCHEMA';
$before = MAD4B_SCP_Schema::status( true );
$result = MAD4B_SCP_Schema::install_or_upgrade();
if ( is_wp_error( $result ) ) {
    fwrite( STDERR, wp_json_encode( array(
        'stage' => 'upgrade_v6_to_v9',
        'before' => $before,
        'code' => $result->get_error_code(),
        'message' => $result->get_error_message(),
        'data' => $result->get_error_data(),
    ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . PHP_EOL );
    exit( 21 );
}
$after = MAD4B_SCP_Schema::status( true );
echo wp_json_encode( array( 'stage' => 'upgrade_v6_to_v9', 'before' => $before, 'after' => $after ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . PHP_EOL;
if ( 9 !== (int) get_option( MAD4B_SCP_Schema::OPTION, 0 ) ) exit( 22 );
if ( empty( $after['ready'] ) || empty( $after['migration']['receipt_valid'] ) || empty( $after['physical_integrity']['ready'] ) ) exit( 23 );
PHP

cat > "$tmp/retry-v9.php" <<PHP
<?php
require '$CURRENT_SCHEMA';
$before = MAD4B_SCP_Schema::status( true );
$result = MAD4B_SCP_Schema::install_or_upgrade();
if ( is_wp_error( $result ) ) {
    fwrite( STDERR, wp_json_encode( array(
        'stage' => 'retry_v9',
        'code' => $result->get_error_code(),
        'message' => $result->get_error_message(),
        'data' => $result->get_error_data(),
    ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . PHP_EOL );
    exit( 31 );
}
$after = MAD4B_SCP_Schema::status( true );
echo wp_json_encode( array( 'stage' => 'retry_v9', 'before' => $before, 'after' => $after ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . PHP_EOL;
if ( empty( $after['ready'] ) || empty( $after['migration']['receipt_valid'] ) || empty( $after['physical_integrity']['ready'] ) ) exit( 32 );
PHP

common=(--path="$ROOT" --allow-root --skip-plugins --skip-themes)

echo "=== REAL MARIADB SCHEMA V6 INSTALL ==="
"$WP_CLI" "${common[@]}" eval-file "$tmp/install-v6.php"

echo "=== REAL MARIADB SCHEMA V6 -> V9 UPGRADE ==="
"$WP_CLI" "${common[@]}" eval-file "$tmp/upgrade-v9.php"

echo "=== REAL MARIADB SCHEMA V9 IDEMPOTENT RETRY ==="
"$WP_CLI" "${common[@]}" eval-file "$tmp/retry-v9.php"

echo "mad4b.schema-mariadb-v6-v9.integration.v1: PASS"

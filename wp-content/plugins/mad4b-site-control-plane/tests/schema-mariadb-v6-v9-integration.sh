#!/usr/bin/env bash
set -euo pipefail

ROOT="$(git rev-parse --show-toplevel)"
SCHEMA_REL="wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-schema.php"
CURRENT_SCHEMA="$ROOT/$SCHEMA_REL"
WP_CLI="${WP_CLI:-wp}"

tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT

export MAD4B_SCHEMA_V9_FILE="$CURRENT_SCHEMA"
grep -Fq "const VERSION = 9;" "$CURRENT_SCHEMA"

cat > "$tmp/install-v6.php" <<'PHP'
<?php
$schema = getenv( 'MAD4B_SCHEMA_FROM_FILE' );
$expected = (int) getenv( 'MAD4B_EXPECTED_FROM_VERSION' );
if ( ! is_string( $schema ) || '' === $schema || ! is_file( $schema ) ) {
    fwrite( STDERR, "Missing MAD4B_SCHEMA_FROM_FILE\n" );
    exit( 10 );
}
require $schema;
$result = MAD4B_SCP_Schema::install_or_upgrade();
if ( is_wp_error( $result ) ) {
    fwrite( STDERR, wp_json_encode( array(
        'stage' => 'install_source_schema',
        'expected_version' => $expected,
        'code' => $result->get_error_code(),
        'message' => $result->get_error_message(),
        'data' => $result->get_error_data(),
    ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . PHP_EOL );
    exit( 11 );
}
$status = MAD4B_SCP_Schema::status( true );
echo wp_json_encode( array(
    'stage' => 'install_source_schema',
    'expected_version' => $expected,
    'db_version' => $GLOBALS['wpdb']->db_version(),
    'status' => $status,
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . PHP_EOL;
if ( $expected !== (int) get_option( MAD4B_SCP_Schema::OPTION, 0 ) || empty( $status['ready'] ) ) exit( 12 );
PHP

cat > "$tmp/seed-sparse.php" <<'PHP'
<?php
$schema = getenv( 'MAD4B_SCHEMA_FROM_FILE' );
$expected = (int) getenv( 'MAD4B_EXPECTED_FROM_VERSION' );
if ( $expected < 7 ) {
    echo wp_json_encode( array( 'stage' => 'seed_sparse', 'skipped' => true, 'source_version' => $expected ) ) . PHP_EOL;
    return;
}
require $schema;
global $wpdb;
$t = MAD4B_SCP_Schema::tables();
$row = array(
    'scope_key' => str_repeat( 'a', 64 ),
    'idempotency_key' => 'existing-sparse-row',
    'request_sha256' => str_repeat( 'b', 64 ),
    'status' => 'pending',
    'result_sha256' => '',
    'expires_at' => '2030-01-01 00:00:00',
    'created_at' => '2026-09-24 00:00:00',
    'updated_at' => '2026-09-24 00:00:00',
);
if ( $expected >= 8 ) $row['reconciliation_ref'] = '';
$inserted = $wpdb->insert( $t['idempotency'], $row );
if ( false === $inserted ) {
    fwrite( STDERR, wp_json_encode( array(
        'stage' => 'seed_sparse',
        'source_version' => $expected,
        'table' => $t['idempotency'],
        'last_error' => $wpdb->last_error,
    ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . PHP_EOL );
    exit( 15 );
}
echo wp_json_encode( array(
    'stage' => 'seed_sparse',
    'source_version' => $expected,
    'table' => $t['idempotency'],
    'inserted' => (int) $inserted,
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . PHP_EOL;
PHP

cat > "$tmp/upgrade-v9.php" <<'PHP'
<?php
$schema = getenv( 'MAD4B_SCHEMA_V9_FILE' );
$from = (int) getenv( 'MAD4B_EXPECTED_FROM_VERSION' );
if ( ! is_string( $schema ) || '' === $schema || ! is_file( $schema ) ) {
    fwrite( STDERR, "Missing MAD4B_SCHEMA_V9_FILE\n" );
    exit( 20 );
}
require $schema;
$before = MAD4B_SCP_Schema::status( true );
$result = MAD4B_SCP_Schema::install_or_upgrade();
if ( is_wp_error( $result ) ) {
    fwrite( STDERR, wp_json_encode( array(
        'stage' => 'upgrade_to_v9',
        'source_version' => $from,
        'before' => $before,
        'code' => $result->get_error_code(),
        'message' => $result->get_error_message(),
        'data' => $result->get_error_data(),
    ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . PHP_EOL );
    exit( 21 );
}
$after = MAD4B_SCP_Schema::status( true );
$receipt = isset( $after['migration']['receipt'] ) && is_array( $after['migration']['receipt'] ) ? $after['migration']['receipt'] : array();
echo wp_json_encode( array(
    'stage' => 'upgrade_to_v9',
    'source_version' => $from,
    'db_version' => $GLOBALS['wpdb']->db_version(),
    'before' => $before,
    'after' => $after,
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . PHP_EOL;
if ( 9 !== (int) get_option( MAD4B_SCP_Schema::OPTION, 0 ) ) exit( 22 );
if ( empty( $after['ready'] ) || empty( $after['migration']['receipt_valid'] ) || empty( $after['physical_integrity']['ready'] ) ) exit( 23 );
if ( $from !== (int) ( $receipt['from_version'] ?? -1 ) || 'upgrade' !== ( $receipt['run_type'] ?? '' ) ) exit( 24 );
if ( $from >= 7 ) {
    global $wpdb;
    $t = MAD4B_SCP_Schema::tables();
    $row = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT claim_epoch,reconciliation_ref FROM {$t['idempotency']} WHERE idempotency_key=%s",
            'existing-sparse-row'
        ),
        ARRAY_A
    );
    if ( ! is_array( $row ) || 1 !== (int) ( $row['claim_epoch'] ?? 0 ) || '' !== (string) ( $row['reconciliation_ref'] ?? '' ) ) {
        fwrite( STDERR, wp_json_encode( array(
            'stage' => 'verify_sparse_row_after_upgrade',
            'source_version' => $from,
            'row' => $row,
            'last_error' => $wpdb->last_error,
        ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . PHP_EOL );
        exit( 25 );
    }
}
PHP

cat > "$tmp/retry-v9.php" <<'PHP'
<?php
$schema = getenv( 'MAD4B_SCHEMA_V9_FILE' );
$from = (int) getenv( 'MAD4B_EXPECTED_FROM_VERSION' );
if ( ! is_string( $schema ) || '' === $schema || ! is_file( $schema ) ) {
    fwrite( STDERR, "Missing MAD4B_SCHEMA_V9_FILE\n" );
    exit( 30 );
}
require $schema;
$before = MAD4B_SCP_Schema::status( true );
$result = MAD4B_SCP_Schema::install_or_upgrade();
if ( is_wp_error( $result ) ) {
    fwrite( STDERR, wp_json_encode( array(
        'stage' => 'retry_v9',
        'source_version' => $from,
        'code' => $result->get_error_code(),
        'message' => $result->get_error_message(),
        'data' => $result->get_error_data(),
    ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . PHP_EOL );
    exit( 31 );
}
$after = MAD4B_SCP_Schema::status( true );
echo wp_json_encode( array( 'stage' => 'retry_v9', 'source_version' => $from, 'before' => $before, 'after' => $after ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . PHP_EOL;
if ( empty( $after['ready'] ) || empty( $after['migration']['receipt_valid'] ) || empty( $after['physical_integrity']['ready'] ) ) exit( 32 );
PHP

common=(--path="$ROOT" --allow-root --skip-plugins --skip-themes)
scenarios=(
  "6:97073e1c1a6a69c951d59c3282e56d1f107e76c2"
  "7:b12ecb1fadf349479109b006764bc53f45274975"
  "8:ffceb0e4a761f038b355c78ffeb388ada225b2a0"
)

for scenario in "${scenarios[@]}"; do
    version="${scenario%%:*}"
    sha="${scenario#*:}"
    source_file="$tmp/schema-v${version}.php"

    echo
    echo "=== RESET DISPOSABLE WORDPRESS FOR SCHEMA v${version} ==="
    "$WP_CLI" "${common[@]}" db reset --yes
    "$WP_CLI" "${common[@]}" core install \
      --url=http://mad4b-schema.test \
      --title="MAD4B Schema Migration v${version}" \
      --admin_user=admin \
      --admin_password='mad4b-schema-ci-only' \
      --admin_email=ci@example.invalid \
      --skip-email

    git -C "$ROOT" show "$sha:$SCHEMA_REL" > "$source_file"
    grep -Fq "const VERSION = ${version};" "$source_file"

    export MAD4B_SCHEMA_FROM_FILE="$source_file"
    export MAD4B_EXPECTED_FROM_VERSION="$version"

    echo "=== REAL MARIADB SCHEMA v${version} INSTALL ==="
    "$WP_CLI" "${common[@]}" eval-file "$tmp/install-v6.php"

    echo "=== SEED SPARSE HISTORICAL DURABLE STATE v${version} ==="
    "$WP_CLI" "${common[@]}" eval-file "$tmp/seed-sparse.php"

    echo "=== REAL MARIADB SCHEMA v${version} -> v9 UPGRADE ==="
    "$WP_CLI" "${common[@]}" eval-file "$tmp/upgrade-v9.php"

    echo "=== REAL MARIADB SCHEMA v9 IDEMPOTENT RETRY (origin v${version}) ==="
    "$WP_CLI" "${common[@]}" eval-file "$tmp/retry-v9.php"
done

echo
echo "mad4b.schema-mariadb-v6-v7-v8-to-v9.integration.v1: PASS"

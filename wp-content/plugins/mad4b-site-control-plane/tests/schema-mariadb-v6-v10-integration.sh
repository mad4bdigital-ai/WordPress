#!/usr/bin/env bash
set -euo pipefail

ROOT="$(git rev-parse --show-toplevel)"
SCHEMA_REL="wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-schema.php"
CURRENT_SCHEMA="$ROOT/$SCHEMA_REL"
WP_CLI="${WP_CLI:-wp}"

tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT

export MAD4B_SCHEMA_V10_FILE="$CURRENT_SCHEMA"
grep -Fq "const VERSION = 10;" "$CURRENT_SCHEMA"

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

cat > "$tmp/upgrade-v10.php" <<'PHP'
<?php
$schema = getenv( 'MAD4B_SCHEMA_V10_FILE' );
$from = (int) getenv( 'MAD4B_EXPECTED_FROM_VERSION' );
if ( ! is_string( $schema ) || '' === $schema || ! is_file( $schema ) ) {
    fwrite( STDERR, "Missing MAD4B_SCHEMA_V10_FILE\n" );
    exit( 20 );
}
require $schema;
$before = MAD4B_SCP_Schema::status( true );
$result = MAD4B_SCP_Schema::install_or_upgrade();
if ( is_wp_error( $result ) ) {
    fwrite( STDERR, wp_json_encode( array(
        'stage' => 'upgrade_to_v10',
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
    'stage' => 'upgrade_to_v10',
    'source_version' => $from,
    'db_version' => $GLOBALS['wpdb']->db_version(),
    'before' => $before,
    'after' => $after,
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . PHP_EOL;
if ( 10 !== (int) get_option( MAD4B_SCP_Schema::OPTION, 0 ) ) exit( 22 );
if ( empty( $after['ready'] ) || empty( $after['migration']['receipt_valid'] ) || empty( $after['physical_integrity']['ready'] ) ) exit( 23 );
if ( $from !== (int) ( $receipt['from_version'] ?? -1 ) || 'upgrade' !== ( $receipt['run_type'] ?? '' ) ) exit( 24 );
global $wpdb;
$t = MAD4B_SCP_Schema::tables();
foreach ( array( 'artifacts', 'artifact_edges' ) as $artifact_table_key ) {
    $physical_name = $t[ $artifact_table_key ];
    if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $physical_name ) ) !== $physical_name ) {
        fwrite( STDERR, wp_json_encode( array(
            'stage' => 'verify_artifact_tables_after_upgrade',
            'source_version' => $from,
            'missing_table' => $artifact_table_key,
        ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . PHP_EOL );
        exit( 26 );
    }
}
if ( $from >= 7 ) {
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

cat > "$tmp/attempt-broken-v8.php" <<'PHP'
<?php
$schema = getenv( 'MAD4B_SCHEMA_BROKEN_V8_FILE' );
if ( ! is_string( $schema ) || '' === $schema || ! is_file( $schema ) ) {
    fwrite( STDERR, "Missing MAD4B_SCHEMA_BROKEN_V8_FILE\n" );
    exit( 40 );
}
require $schema;
$result = MAD4B_SCP_Schema::install_or_upgrade();
if ( ! is_wp_error( $result ) || 'mad4b_governance_schema_unavailable' !== $result->get_error_code() ) {
    fwrite( STDERR, wp_json_encode( array(
        'stage' => 'attempt_known_broken_v8',
        'unexpected_result' => $result,
    ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . PHP_EOL );
    exit( 41 );
}
$data = $result->get_error_data();
$missing = is_array( $data ) && isset( $data['missing_durable_columns'] ) && is_array( $data['missing_durable_columns'] )
    ? $data['missing_durable_columns']
    : array();
sort( $missing );
$expected = array(
    'content_job_events.job_revision',
    'content_job_events.payload_sha256',
    'content_jobs.current_artifact_ref',
    'content_jobs.revision',
);
sort( $expected );
echo wp_json_encode( array(
    'stage' => 'attempt_known_broken_v8',
    'code' => $result->get_error_code(),
    'installed_version_after_failure' => (int) get_option( MAD4B_SCP_Schema::OPTION, 0 ),
    'missing_durable_columns' => $missing,
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . PHP_EOL;
if ( 6 !== (int) get_option( MAD4B_SCP_Schema::OPTION, 0 ) ) exit( 42 );
if ( $expected !== $missing ) exit( 43 );
PHP

cat > "$tmp/retry-v10.php" <<'PHP'
<?php
$schema = getenv( 'MAD4B_SCHEMA_V10_FILE' );
$from = (int) getenv( 'MAD4B_EXPECTED_FROM_VERSION' );
if ( ! is_string( $schema ) || '' === $schema || ! is_file( $schema ) ) {
    fwrite( STDERR, "Missing MAD4B_SCHEMA_V10_FILE\n" );
    exit( 30 );
}
require $schema;
$before = MAD4B_SCP_Schema::status( true );
$result = MAD4B_SCP_Schema::install_or_upgrade();
if ( is_wp_error( $result ) ) {
    fwrite( STDERR, wp_json_encode( array(
        'stage' => 'retry_v10',
        'source_version' => $from,
        'code' => $result->get_error_code(),
        'message' => $result->get_error_message(),
        'data' => $result->get_error_data(),
    ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . PHP_EOL );
    exit( 31 );
}
$after = MAD4B_SCP_Schema::status( true );
echo wp_json_encode( array( 'stage' => 'retry_v10', 'source_version' => $from, 'before' => $before, 'after' => $after ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . PHP_EOL;
if ( empty( $after['ready'] ) || empty( $after['migration']['receipt_valid'] ) || empty( $after['physical_integrity']['ready'] ) ) exit( 32 );
PHP

common=(--path="$ROOT" --allow-root --skip-plugins --skip-themes)
scenarios=(
  "6:97073e1c1a6a69c951d59c3282e56d1f107e76c2"
  "7:b12ecb1fadf349479109b006764bc53f45274975"
  "8:72328ad1896ac43cb5293f793fe4b4fd3a27c253"
  "9:f6651572ca3cbcae2e215267886e93aa9948927f"
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

    echo "=== REAL MARIADB SCHEMA v${version} -> v10 UPGRADE ==="
    "$WP_CLI" "${common[@]}" eval-file "$tmp/upgrade-v10.php"

    echo "=== REAL MARIADB SCHEMA v10 IDEMPOTENT RETRY (origin v${version}) ==="
    "$WP_CLI" "${common[@]}" eval-file "$tmp/retry-v10.php"
done

echo
echo "=== HYBRID REPAIR: HEALTHY v6 -> KNOWN-BROKEN v8 PARTIAL STATE -> CURRENT v10 ==="
"$WP_CLI" "${common[@]}" db reset --yes
"$WP_CLI" "${common[@]}" core install \
  --url=http://mad4b-schema.test \
  --title="MAD4B Hybrid Schema Repair" \
  --admin_user=admin \
  --admin_password='mad4b-schema-ci-only' \
  --admin_email=ci@example.invalid \
  --skip-email

v6_file="$tmp/schema-hybrid-v6.php"
broken_v8_file="$tmp/schema-broken-v8.php"
git -C "$ROOT" show "97073e1c1a6a69c951d59c3282e56d1f107e76c2:$SCHEMA_REL" > "$v6_file"
git -C "$ROOT" show "ffceb0e4a761f038b355c78ffeb388ada225b2a0:$SCHEMA_REL" > "$broken_v8_file"
grep -Fq "const VERSION = 6;" "$v6_file"
grep -Fq "const VERSION = 8;" "$broken_v8_file"

export MAD4B_SCHEMA_FROM_FILE="$v6_file"
export MAD4B_EXPECTED_FROM_VERSION="6"
"$WP_CLI" "${common[@]}" eval-file "$tmp/install-v6.php"

export MAD4B_SCHEMA_BROKEN_V8_FILE="$broken_v8_file"
"$WP_CLI" "${common[@]}" eval-file "$tmp/attempt-broken-v8.php"

# The failed v8 attempt created durable tables but intentionally left the
# canonical schema marker at v6. Seed one durable row to prove repair preserves
# sparse historical data while v9 adds its missing fencing column.
export MAD4B_SCHEMA_FROM_FILE="$broken_v8_file"
export MAD4B_EXPECTED_FROM_VERSION="8"
"$WP_CLI" "${common[@]}" eval-file "$tmp/seed-sparse.php"

export MAD4B_EXPECTED_FROM_VERSION="6"
"$WP_CLI" "${common[@]}" eval-file "$tmp/upgrade-v10.php"
"$WP_CLI" "${common[@]}" eval-file "$tmp/retry-v10.php"

echo
echo "mad4b.schema-mariadb-v6-v7-v8-to-v10.integration.v1: PASS"
echo "mad4b.schema-broken-v8-partial-repair-to-v10.v1: PASS"

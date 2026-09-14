#!/usr/bin/env python3
from pathlib import Path

ROOT = Path('.')


def read(path):
    return (ROOT / path).read_text(encoding='utf-8')


def write(path, content):
    (ROOT / path).write_text(content, encoding='utf-8')


def replace(path, old, new, expected=1):
    content = read(path)
    count = content.count(old)
    if count != expected:
        raise SystemExit(f'{path}: expected {expected} occurrence(s), found {count}: {old!r}')
    write(path, content.replace(old, new, expected))


# 1) Runtime: the MU bootstrap must honor only the current v2 Site Profile.
# Legacy v1 records are migrated by Site_Profile with all authority/features disabled
# and reenrollment required, so early MU runtime must never treat v1 as governed.
mu = 'wp-content/plugins/mad4b-site-control-plane/bootstrap/mad4b-mcp-adapter-mu-bootstrap.php'
replace(mu, "get_option( 'mad4b_scp_site_profile_v1', array() )", "get_option( 'mad4b_scp_site_profile_v2', array() )")
replace(mu, "'mad4b.site-profile.v1' === (string) $mad4b_mcp_mu_profile['contract']", "'mad4b.site-profile.v2' === (string) $mad4b_mcp_mu_profile['contract']")

# 2) Web lifecycle test follows the generic enrolled fixture instead of ETG identity.
replace(
    'wp-content/plugins/mad4b-site-control-plane/tests/runtime-mcp-web-lifecycle.php',
    "$_SERVER['HTTP_HOST'] = 'staging.egypttourgates.com';",
    "$_SERVER['HTTP_HOST'] = 'mad4b-web.test';",
)

# 3) Metadata bridge runtime assertions follow governed non-production semantics.
meta = 'wp-content/plugins/mad4b-site-control-plane/tests/runtime-mcp-adapter-metadata-bridge.php'
replace(meta, "$_SERVER['HTTP_HOST'] = 'staging.egypttourgates.com';", "$_SERVER['HTTP_HOST'] = 'mad4b-metadata.test';")
replace(meta, "exact governed Staging admin", "governed non-production admin")
replace(meta, "exact governed Staging:", "governed non-production site:")
replace(meta, "Mirror only missing nested values on exact Staging", "Mirror only missing nested values on the governed non-production site")
replace(meta, "empty( $status['rank_math_meta_staging_only'] )", "empty( $status['rank_math_meta_governed_nonproduction_only'] )")

# 4) Dynamic Skills certification marker was renamed with Site Profile v2.
replace(
    'wp-content/plugins/mad4b-site-control-plane/tests/skill-runtime-certification-contract.py',
    "'staging_app_mapping_mismatch'",
    "'profile_app_mapping_mismatch'",
)

# 5) Live Acceptance reconciler standalone fixture models the enrolled acceptance target.
reconciler = 'wp-content/plugins/mad4b-site-control-plane/tests/live-acceptance-reconciler-runtime.php'
marker = "class MAD4B_SCP_Skill_Snapshot_Identity {"
site_profile = """class MAD4B_SCP_Site_Profile {
\tpublic static function current_environment() { return 'staging'; }
\tpublic static function site_origin() { return 'https://reconciler.test'; }
\tpublic static function site_host() { return 'reconciler.test'; }
\tpublic static function nonproduction_governed( $feature = '' ) { return '' === (string) $feature || 'acceptance' === (string) $feature; }
\tpublic static function site_urls_match_enrollment() { return true; }
}

"""
content = read(reconciler)
if content.count(marker) != 1 or 'class MAD4B_SCP_Site_Profile {' in content:
    raise SystemExit(f'{reconciler}: unexpected Site Profile fixture shape')
content = content.replace(marker, site_profile + marker, 1)
content = content.replace("'host' => 'staging.egypttourgates.com'", "'host' => 'reconciler.test'")
if "staging.egypttourgates.com" in content:
    raise SystemExit(f'{reconciler}: stale ETG host remains after fixture migration')
write(reconciler, content)

# 6) Connection smoke adapts the HTTPS blocker assertion to the actual fixture scheme.
conn_smoke = 'wp-content/plugins/mad4b-site-control-plane/tests/runtime-connection-readiness-smoke.php'
replace(
    conn_smoke,
    "$check( in_array( 'https_required_for_remote_mcp', $status['remote_preflight_blockers'], true ), 'HTTP CI target did not report HTTPS remote blocker.' );",
    "$is_https_target = 'https' === strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_SCHEME ) );\n$check( $is_https_target ? ! in_array( 'https_required_for_remote_mcp', $status['remote_preflight_blockers'], true ) : in_array( 'https_required_for_remote_mcp', $status['remote_preflight_blockers'], true ), 'HTTPS remote blocker did not match the disposable target scheme.' );",
)

# 7) Registration bridge static contract is profile-driven, not tenant-host-driven.
bridge_contract = 'wp-content/plugins/mad4b-site-control-plane/tests/mcp-registration-bridge-contract.py'
replace(
    bridge_contract,
    "    \"const STAGING_HOST = 'staging.egypttourgates.com'\",",
    "    \"MAD4B_SCP_Site_Profile::origin_enrolled()\",\n    \"MAD4B_SCP_Site_Profile::managed_runtime_enabled()\",",
)

# 8) Exact ETG acceptance fixture explicitly enrolls Site Profile v2 after installation.
live_workflow = '.github/workflows/mad4b-live-acceptance-evidence.yml'
old = """          php /tmp/wp-cli.phar eval '
            $profile = MAD4B_SCP_Site_Profile::status();
            if ( empty( $profile[\"configured\"] ) || empty( $profile[\"origin_match\"] ) || empty( $profile[\"environment_match\"] ) || empty( $profile[\"skills_enabled\"] ) || ! hash_equals( getenv( \"TARGET_ORIGIN\" ), (string) $profile[\"canonical_origin\"] ) ) { fwrite( STDERR, \"site profile fixture mismatch: \" . wp_json_encode( $profile ) ); exit( 1 ); }
"""
new = """          php /tmp/wp-cli.phar eval '
            $profile = MAD4B_SCP_Site_Profile::status();
            if ( empty( $profile[\"configured\"] ) ) {
              $saved = MAD4B_SCP_Site_Profile::save_current_site( array(
                \"expected_revision\" => 0,
                \"display_name\" => \"MAD4B ETG Live Acceptance\",
                \"chatgpt_app_id\" => \"plugin_asdk_app_6aa05fa2f97481919c24b99855fadba2\",
                \"oauth_user_ids\" => array( get_current_user_id() ),
                \"oauth_enabled\" => false,
                \"skills_enabled\" => true,
                \"write_enabled\" => false,
                \"production_write_confirmed\" => false,
                \"provider_isolation_enabled\" => false,
                \"managed_runtime_enabled\" => false,
                \"acceptance_enabled\" => true,
              ) );
              if ( is_wp_error( $saved ) ) { fwrite( STDERR, $saved->get_error_code() . \": \" . $saved->get_error_message() ); exit( 1 ); }
              $profile = MAD4B_SCP_Site_Profile::status();
            }
            if ( empty( $profile[\"configured\"] ) || empty( $profile[\"origin_match\"] ) || empty( $profile[\"environment_match\"] ) || empty( $profile[\"skills_enabled\"] ) || ! hash_equals( getenv( \"TARGET_ORIGIN\" ), (string) $profile[\"canonical_origin\"] ) ) { fwrite( STDERR, \"site profile fixture mismatch: \" . wp_json_encode( $profile ) ); exit( 1 ); }
"""
replace(live_workflow, old, new)

print('RC33 v2 closure migration applied')

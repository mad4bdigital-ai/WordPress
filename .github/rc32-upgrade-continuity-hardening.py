from pathlib import Path

root = Path('wp-content/plugins/mad4b-site-control-plane')
source_path = root / 'includes/class-mad4b-scp-upgrade-continuity.php'
test_path = root / 'tests/upgrade-continuity-runtime.php'

def replace_method(source: str, signature: str, replacement: str) -> str:
    start = source.find(signature)
    if start < 0:
        raise SystemExit(f'method signature not found: {signature}')
    brace = source.find('{', start)
    if brace < 0:
        raise SystemExit(f'opening brace not found: {signature}')
    depth = 0
    for i in range(brace, len(source)):
        c = source[i]
        if c == '{':
            depth += 1
        elif c == '}':
            depth -= 1
            if depth == 0:
                return source[:start] + replacement + source[i + 1:]
    raise SystemExit(f'unbalanced method: {signature}')

s = source_path.read_text()
const_anchor = "\tconst PRIOR_OAUTH_OPTION = 'mad4b_scp_staging_oauth_autoconfig_v1';\n"
if s.count(const_anchor) != 1:
    raise SystemExit('PRIOR_OAUTH_OPTION anchor drifted')
s = s.replace(
    const_anchor,
    const_anchor + "\tconst RECOVERY_MARKER_OPTION = 'mad4b_scp_upgrade_continuity_recovery_v1';\n",
    1,
)

old_current = "\t\tif ( ! self::valid_v2_migration_pending_record( $current ) ) {\n\t\t\tif ( $has_current ) return self::recovery_result( 'not_applicable', false, '' );\n\t\t\treturn self::recover_snapshot_only_read_continuity();\n\t\t}\n"
new_current = "\t\tif ( ! self::valid_v2_migration_pending_record( $current ) ) {\n\t\t\tif ( $has_current ) return self::recovery_result( 'blocked', false, 'current_site_profile_invalid' );\n\t\t\treturn self::recover_snapshot_only_read_continuity();\n\t\t}\n"
if s.count(old_current) != 1:
    raise SystemExit('current profile guard drifted')
s = s.replace(old_current, new_current, 1)

replacement = r'''\tprivate static function recover_snapshot_only_read_continuity() {
\t\t$snapshot = get_option( self::PRIOR_OAUTH_OPTION, array() );
\t\tif ( ! is_array( $snapshot ) || empty( $snapshot ) ) return self::recovery_result( 'not_applicable', false, '' );

\t\t$marker = get_option( self::RECOVERY_MARKER_OPTION, array() );
\t\tif ( is_array( $marker ) && 'consumed' === ( isset( $marker['state'] ) ? sanitize_key( (string) $marker['state'] ) : '' ) ) {
\t\t\treturn self::recovery_result( 'blocked', false, 'snapshot_recovery_already_consumed' );
\t\t}

\t\t$environment = self::current_environment();
\t\tif ( ! in_array( $environment, array( 'local', 'development', 'staging' ), true ) ) return self::recovery_result( 'blocked', false, 'nonproduction_only' );
\t\t$origin = self::current_origin();
\t\tif ( '' === $origin ) return self::recovery_result( 'blocked', false, 'current_origin_unavailable' );

\t\t$snapshot_issuer = isset( $snapshot['issuer'] ) ? untrailingslashit( trim( (string) $snapshot['issuer'] ) ) : '';
\t\t$expected_issuer = untrailingslashit( home_url( '/oauth/mcp' ) );
\t\tif ( '' === $snapshot_issuer || ! hash_equals( $expected_issuer, $snapshot_issuer ) ) return self::recovery_result( 'blocked', false, 'prior_oauth_issuer_mismatch' );

\t\t$owner_user_id = isset( $snapshot['primary_owner_user_id'] ) ? absint( $snapshot['primary_owner_user_id'] ) : ( isset( $snapshot['wp_user_id'] ) ? absint( $snapshot['wp_user_id'] ) : 0 );
\t\tif ( $owner_user_id < 1 ) return self::recovery_result( 'blocked', false, 'prior_oauth_owner_missing' );
\t\t$user = get_userdata( $owner_user_id );
\t\tif ( ! $user || ! user_can( $user, 'manage_options' ) ) return self::recovery_result( 'blocked', false, 'prior_oauth_owner_not_administrator' );

\t\t$format = self::snapshot_format( $snapshot );
\t\tif ( 'unsupported' === $format ) return self::recovery_result( 'blocked', false, 'prior_oauth_snapshot_format_unsupported' );

\t\t$snapshot_revision = 0;
\t\t$snapshot_uuid = '';
\t\tif ( 'profile_bound' === $format ) {
\t\t\t$snapshot_environment = isset( $snapshot['environment'] ) ? sanitize_key( (string) $snapshot['environment'] ) : '';
\t\t\t$snapshot_origin = isset( $snapshot['canonical_origin'] ) ? self::normalize_origin( $snapshot['canonical_origin'] ) : '';
\t\t\t$snapshot_uuid = isset( $snapshot['site_uuid'] ) ? strtolower( trim( (string) $snapshot['site_uuid'] ) ) : '';
\t\t\t$snapshot_revision = isset( $snapshot['profile_revision'] ) ? absint( $snapshot['profile_revision'] ) : 0;
\t\t\tif ( ! self::valid_uuid( $snapshot_uuid ) ) return self::recovery_result( 'blocked', false, 'prior_oauth_site_uuid_invalid' );
\t\t\tif ( ! hash_equals( $environment, $snapshot_environment ) || ! hash_equals( $origin, $snapshot_origin ) ) return self::recovery_result( 'blocked', false, 'prior_oauth_identity_mismatch' );
\t\t\tif ( isset( $snapshot['profile_digest'] ) && '' !== trim( (string) $snapshot['profile_digest'] ) && ! self::valid_sha256( $snapshot['profile_digest'] ) ) {
\t\t\t\treturn self::recovery_result( 'blocked', false, 'prior_oauth_profile_digest_invalid' );
\t\t\t}
\t\t\tif ( isset( $snapshot['oauth_user_ids'] ) && is_array( $snapshot['oauth_user_ids'] ) && ! empty( $snapshot['oauth_user_ids'] ) ) {
\t\t\t\t$prior_users = array_values( array_unique( array_filter( array_map( 'absint', $snapshot['oauth_user_ids'] ) ) ) );
\t\t\t\tif ( ! in_array( $owner_user_id, $prior_users, true ) ) return self::recovery_result( 'blocked', false, 'prior_oauth_owner_not_in_snapshot' );
\t\t\t}
\t\t} else {
\t\t\t$snapshot_uuid = self::legacy_snapshot_site_uuid( $origin, $owner_user_id, $snapshot_issuer );
\t\t\tif ( ! self::valid_uuid( $snapshot_uuid ) ) return self::recovery_result( 'blocked', false, 'legacy_snapshot_site_uuid_derivation_failed' );
\t\t}

\t\t$evidence_sha256 = hash( 'sha256', implode( "\n", array( self::CONTRACT, $format, $environment, $origin, $snapshot_uuid, (string) $owner_user_id, $snapshot_issuer ) ) );
\t\t$now = gmdate( 'c' );
\t\t$next = array(
\t\t\t'contract' => MAD4B_SCP_Site_Profile::CONTRACT,
\t\t\t'version' => MAD4B_SCP_Site_Profile::VERSION,
\t\t\t'site_uuid' => $snapshot_uuid,
\t\t\t'revision' => max( 1, $snapshot_revision + 1 ),
\t\t\t'environment' => $environment,
\t\t\t'canonical_origin' => $origin,
\t\t\t'display_name' => function_exists( 'get_bloginfo' ) ? substr( sanitize_text_field( (string) get_bloginfo( 'name' ) ), 0, 191 ) : '',
\t\t\t'chatgpt_app_id' => '',
\t\t\t'oauth_user_ids' => array( $owner_user_id ),
\t\t\t'related_origins' => array( $environment => $origin ),
\t\t\t'features' => self::read_only_features(),
\t\t\t'legacy_agent_slug' => '',
\t\t\t'legacy_zero_touch' => false,
\t\t\t'migration_requires_reenrollment' => false,
\t\t\t'migration_read_continuity_recovered' => true,
\t\t\t'migration_read_continuity_source' => 'legacy_zero_touch' === $format ? 'legacy_zero_touch_oauth_snapshot_v1' : 'prior_oauth_snapshot',
\t\t\t'migration_snapshot_format' => $format,
\t\t\t'migration_write_reenrollment_required' => true,
\t\t\t'migration_read_continuity_contract' => self::CONTRACT,
\t\t\t'migration_read_continuity_evidence_sha256' => $evidence_sha256,
\t\t\t'created_at' => $now,
\t\t\t'updated_at' => $now,
\t\t);
\t\tif ( false === update_option( MAD4B_SCP_Site_Profile::OPTION, $next, false ) ) return self::recovery_result( 'blocked', false, 'read_continuity_persist_failed' );

\t\t$marker = array(
\t\t\t'contract' => self::CONTRACT,
\t\t\t'state' => 'consumed',
\t\t\t'source' => (string) $next['migration_read_continuity_source'],
\t\t\t'site_uuid' => $snapshot_uuid,
\t\t\t'environment' => $environment,
\t\t\t'canonical_origin' => $origin,
\t\t\t'evidence_sha256' => $evidence_sha256,
\t\t\t'consumed_at' => $now,
\t\t);
\t\tif ( false === update_option( self::RECOVERY_MARKER_OPTION, $marker, false ) ) {
\t\t\tdelete_option( MAD4B_SCP_Site_Profile::OPTION );
\t\t\treturn self::recovery_result( 'blocked', false, 'recovery_marker_persist_failed' );
\t\t}

\t\treturn self::recovery_result( 'recovered', true, '', array(
\t\t\t'site_uuid' => $snapshot_uuid,
\t\t\t'environment' => $environment,
\t\t\t'canonical_origin' => $origin,
\t\t\t'owner_user_id' => $owner_user_id,
\t\t\t'previous_revision' => $snapshot_revision,
\t\t\t'revision' => absint( $next['revision'] ),
\t\t\t'recovery_source' => (string) $next['migration_read_continuity_source'],
\t\t\t'snapshot_format' => $format,
\t\t\t'write_restored' => false,
\t\t\t'production_authority_restored' => false,
\t\t) );
\t}'''
s = replace_method(s, '\tprivate static function recover_snapshot_only_read_continuity()', replacement)

helper_anchor = "\tprivate static function read_only_features() {\n"
helpers = r'''\tprivate static function snapshot_format( array $snapshot ) {
\t\t$version = isset( $snapshot['version'] ) ? absint( $snapshot['version'] ) : 0;
\t\t$issuer = isset( $snapshot['issuer'] ) ? trim( (string) $snapshot['issuer'] ) : '';
\t\t$owner = isset( $snapshot['primary_owner_user_id'] ) ? absint( $snapshot['primary_owner_user_id'] ) : ( isset( $snapshot['wp_user_id'] ) ? absint( $snapshot['wp_user_id'] ) : 0 );
\t\t$has_profile_identity = ! empty( $snapshot['site_uuid'] ) || ! empty( $snapshot['canonical_origin'] ) || ! empty( $snapshot['environment'] ) || ! empty( $snapshot['profile_revision'] ) || ! empty( $snapshot['profile_digest'] );
\t\tif ( $has_profile_identity ) return 'profile_bound';
\t\tif ( 1 === $version && $owner > 0 && '' !== $issuer && ! empty( $snapshot['updated_at'] ) ) return 'legacy_zero_touch';
\t\treturn 'unsupported';
\t}

\tprivate static function legacy_snapshot_site_uuid( $origin, $owner_user_id, $issuer ) {
\t\t$hex = hash( 'sha256', implode( "\n", array( self::CONTRACT, 'legacy-zero-touch-site', (string) $origin, (string) absint( $owner_user_id ), (string) $issuer ) ) );
\t\tif ( ! is_string( $hex ) || strlen( $hex ) < 32 ) return '';
\t\t$hex = substr( strtolower( $hex ), 0, 32 );
\t\t$hex[12] = '5';
\t\t$variant = hexdec( $hex[16] );
\t\t$hex[16] = dechex( ( $variant & 0x3 ) | 0x8 );
\t\treturn substr( $hex, 0, 8 ) . '-' . substr( $hex, 8, 4 ) . '-' . substr( $hex, 12, 4 ) . '-' . substr( $hex, 16, 4 ) . '-' . substr( $hex, 20, 12 );
\t}

\tprivate static function valid_sha256( $value ) {
\t\treturn 1 === preg_match( '/^[a-f0-9]{64}$/', strtolower( trim( (string) $value ) ) );
\t}

'''
if s.count(helper_anchor) != 1:
    raise SystemExit('read_only_features anchor drifted')
s = s.replace(helper_anchor, helpers + helper_anchor, 1)
source_path.write_text(s)

t = test_path.read_text()
old_snapshot = "function snapshot($env='staging',$origin='https://staging.example.test'){return array('environment'=>$env,'site_uuid'=>'123e4567-e89b-42d3-a456-426614174000','profile_revision'=>4,'canonical_origin'=>$origin,'wp_user_id'=>7,'primary_owner_user_id'=>7,'oauth_user_ids'=>array(7),'issuer'=>rtrim($origin,'/').'/oauth/mcp');}\n"
new_snapshot = "function snapshot($env='staging',$origin='https://staging.example.test'){return array('version'=>3,'environment'=>$env,'site_uuid'=>'123e4567-e89b-42d3-a456-426614174000','profile_revision'=>4,'profile_digest'=>str_repeat('a',64),'canonical_origin'=>$origin,'wp_user_id'=>7,'primary_owner_user_id'=>7,'oauth_user_ids'=>array(7),'issuer'=>rtrim($origin,'/').'/oauth/mcp','updated_at'=>'2026-09-14T00:00:00Z');}\nfunction legacy_snapshot($origin='https://staging.example.test'){return array('version'=>1,'wp_user_id'=>7,'issuer'=>rtrim($origin,'/').'/oauth/mcp','updated_at'=>'2026-09-10T00:00:00Z');}\n"
if t.count(old_snapshot) != 1:
    raise SystemExit('snapshot helper drifted')
t = t.replace(old_snapshot, new_snapshot, 1)

modern_assert = "ok(empty($r['write_restored'])&&empty($r['production_authority_restored'])&&empty($r['breakglass_restored']),'snapshot-only recovery never restores write, Production or Breakglass authority');\n"
modern_extra = modern_assert + "ok('consumed'===get_option(MAD4B_SCP_Upgrade_Continuity::RECOVERY_MARKER_OPTION,array())['state'],'snapshot-only recovery records one-time consumption marker');\ndelete_option(MAD4B_SCP_Site_Profile::OPTION);\nMAD4B_SCP_Site_Profile::reset_cache();\n$r=MAD4B_SCP_Upgrade_Continuity::recover_verified_read_continuity();\nok(!$r['recovered']&&'snapshot_recovery_already_consumed'===$r['blocker'],'consumed snapshot must not resurrect a deliberately absent profile');\nok(false===get_option(MAD4B_SCP_Site_Profile::OPTION,false),'consumed recovery marker keeps a removed profile absent');\n"
if t.count(modern_assert) != 1:
    raise SystemExit('modern snapshot assertion anchor drifted')
t = t.replace(modern_assert, modern_extra, 1)

insert_anchor = "// Snapshot-only origin drift fails closed and must not synthesize a Site Profile.\n"
extra_tests = r'''// The real pre-Site-Profile zero-touch snapshot shape can recover read continuity once.
resetx();
$GLOBALS['opts'][MAD4B_SCP_Upgrade_Continuity::PRIOR_OAUTH_OPTION]=legacy_snapshot();
$r=MAD4B_SCP_Upgrade_Continuity::recover_verified_read_continuity();
ok(!empty($r['recovered'])&&'legacy_zero_touch_oauth_snapshot_v1'===$r['recovery_source'],'legacy zero-touch snapshot recovers bounded read continuity');
MAD4B_SCP_Site_Profile::reset_cache();
$legacy_v2=get_option(MAD4B_SCP_Site_Profile::OPTION,array());
ok(!empty($legacy_v2['site_uuid'])&&preg_match('/^[a-f0-9-]{36}$/',$legacy_v2['site_uuid']),'legacy zero-touch recovery derives a stable site UUID');
ok(!empty($legacy_v2['features']['oauth'])&&empty($legacy_v2['features']['write']),'legacy zero-touch recovery restores read OAuth only');
$legacy_uuid=$legacy_v2['site_uuid'];
resetx();
$GLOBALS['opts'][MAD4B_SCP_Upgrade_Continuity::PRIOR_OAUTH_OPTION]=legacy_snapshot();
$r=MAD4B_SCP_Upgrade_Continuity::recover_verified_read_continuity();
$legacy_v2_repeat=get_option(MAD4B_SCP_Site_Profile::OPTION,array());
ok($legacy_uuid===$legacy_v2_repeat['site_uuid'],'legacy zero-touch site UUID derivation is deterministic');

// Legacy zero-touch evidence remains exact-issuer bound.
resetx();
$GLOBALS['opts'][MAD4B_SCP_Upgrade_Continuity::PRIOR_OAUTH_OPTION]=legacy_snapshot('https://evil.example.test');
$r=MAD4B_SCP_Upgrade_Continuity::recover_verified_read_continuity();
ok(!$r['recovered']&&'prior_oauth_issuer_mismatch'===$r['blocker'],'legacy zero-touch issuer drift fails closed');
ok(false===get_option(MAD4B_SCP_Site_Profile::OPTION,false),'legacy zero-touch issuer drift cannot synthesize a profile');

// A stored-but-invalid v2 record is never replaced by snapshot recovery.
resetx();
$GLOBALS['opts'][MAD4B_SCP_Site_Profile::OPTION]=array('contract'=>MAD4B_SCP_Site_Profile::CONTRACT,'version'=>2,'site_uuid'=>'broken');
$GLOBALS['opts'][MAD4B_SCP_Upgrade_Continuity::PRIOR_OAUTH_OPTION]=snapshot();
$r=MAD4B_SCP_Upgrade_Continuity::recover_verified_read_continuity();
ok(!$r['recovered']&&'current_site_profile_invalid'===$r['blocker'],'stored invalid v2 profile fails closed instead of being overwritten');
ok('broken'===$GLOBALS['opts'][MAD4B_SCP_Site_Profile::OPTION]['site_uuid'],'stored invalid v2 bytes remain untouched');

// Profile-bound snapshots reject malformed profile digests.
resetx();
$bad=snapshot();$bad['profile_digest']='not-a-digest';
$GLOBALS['opts'][MAD4B_SCP_Upgrade_Continuity::PRIOR_OAUTH_OPTION]=$bad;
$r=MAD4B_SCP_Upgrade_Continuity::recover_verified_read_continuity();
ok(!$r['recovered']&&'prior_oauth_profile_digest_invalid'===$r['blocker'],'malformed profile-bound digest fails closed');

'''
if t.count(insert_anchor) != 1:
    raise SystemExit('test insert anchor drifted')
t = t.replace(insert_anchor, extra_tests + insert_anchor, 1)
test_path.write_text(t)

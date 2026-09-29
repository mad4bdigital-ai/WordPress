<?php
if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', __DIR__ . '/' );

$scenario = isset( $argv[1] ) ? (string) $argv[1] : 'foreign';
$GLOBALS['mad4b_portable_scenario'] = $scenario;
$GLOBALS['mad4b_portable_environment'] = 'staging';
$GLOBALS['mad4b_portable_home'] = 'https://tenant-b.test/';

function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_\-]/','',(string)$v));}
function absint($v){return abs((int)$v);}
function wp_parse_url($u,$c=-1){return parse_url($u,$c);}
function wp_json_encode($v,$f=0){return json_encode($v,$f);}
function home_url($p=''){return rtrim($GLOBALS['mad4b_portable_home'],'/').(''===$p?'':'/'.ltrim($p,'/'));}
function wp_get_environment_type(){return $GLOBALS['mad4b_portable_environment'];}
function get_users($args=array()){return array(11,19);}

final class MAD4B_SCP_Site_Profile {
    public static function status() {
        $scenario=$GLOBALS['mad4b_portable_scenario'];
        if ('invalid'===$scenario) return array(
            'configured'=>false,
            'source'=>'stored_invalid',
            'binding_state'=>'unconfigured',
            'origin_match'=>false,
            'environment_match'=>false,
            'canonical_origin'=>'',
            'configured_environment'=>'',
        );
        if ('fresh'===$scenario) return array(
            'configured'=>false,
            'source'=>'none',
            'binding_state'=>'unconfigured',
            'origin_match'=>false,
            'environment_match'=>false,
            'canonical_origin'=>'',
            'configured_environment'=>'',
        );
        if ('exact'===$scenario) return array(
            'configured'=>true,
            'source'=>'stored',
            'binding_state'=>'exact',
            'origin_match'=>true,
            'environment_match'=>true,
            'canonical_origin'=>'https://tenant-b.test',
            'configured_environment'=>'staging',
        );
        if ('environment_drift'===$scenario) return array(
            'configured'=>true,
            'source'=>'stored',
            'binding_state'=>'environment_drift',
            'origin_match'=>true,
            'environment_match'=>false,
            'canonical_origin'=>'https://tenant-b.test',
            'configured_environment'=>'production',
        );
        return array(
            'configured'=>true,
            'source'=>'stored',
            'binding_state'=>'foreign_origin',
            'origin_match'=>false,
            'environment_match'=>true,
            'canonical_origin'=>'https://tenant-a.test',
            'configured_environment'=>'staging',
        );
    }
}

final class MAD4B_SCP_Upgrade_Continuity {
    public static function recovery_status() {
        return array(
            'state'=>'blocked',
            'blocker'=>'prior_oauth_identity_mismatch',
            'recovered'=>false,
        );
    }
}

require dirname(__DIR__).'/includes/class-mad4b-scp-portable-readonly-connection.php';

function ok($c,$m){if(!$c){fwrite(STDERR,"FAIL [$GLOBALS[mad4b_portable_scenario]]: $m\n");exit(1);}}

$status=MAD4B_SCP_Portable_Readonly_Connection::bootstrap();

if ('invalid'===$scenario) {
    ok(empty($status['effective']),'invalid stored profile must fail closed');
    ok('site_profile_invalid_fail_closed'===($status['blocker']??''),'invalid profile exposes exact fail-closed blocker');
    exit(0);
}

if ('exact'===$scenario) {
    ok(empty($status['effective']),'exact governed profile must keep ownership of OAuth configuration');
    ok('site_profile_present'===($status['blocker']??''),'exact profile blocks portable fallback');
    exit(0);
}

ok(!empty($status['effective']),'fresh/foreign tenant must receive portable read-only OAuth');
ok(empty($status['write_enabled'])&&empty($status['developer_enabled'])&&empty($status['breakglass_enabled'])&&empty($status['skills_enabled']),'portable path exposes no governed mutation authority');
ok(empty($status['profile_authority_inherited']),'portable path never inherits stored Site Profile authority');
ok('https://tenant-b.test/oauth/mcp'===($status['issuer']??''),'issuer is derived from the current tenant origin');
ok(defined('MAD4B_MCP_OAUTH_ISSUER')&&'https://tenant-b.test/oauth/mcp'===MAD4B_MCP_OAUTH_ISSUER,'runtime OAuth issuer is bound to current tenant');
ok(defined('MAD4B_MCP_OAUTH_ALLOWED_SUBJECT_BINDINGS'),'current-tenant subject binding is configured');
$bindings=MAD4B_MCP_OAUTH_ALLOWED_SUBJECT_BINDINGS;
ok(isset($bindings['https://tenant-b.test/oauth/mcp']),'subject binding is keyed by current tenant issuer');
ok($bindings['https://tenant-b.test/oauth/mcp']===array('user:11','user:19'),'portable subjects come from current tenant administrators');
ok('blocked'===($status['upgrade_continuity_state']??'')&&'prior_oauth_identity_mismatch'===($status['upgrade_continuity_blocker']??''),'stale continuity evidence remains diagnostic without importing old identity');

if ('fresh'===$scenario) {
    ok(empty($status['profile_present'])&&!empty($status['configured']),'fresh site auto-connects read-only without creating governed profile authority');
    ok(empty($status['foreign_profile_quarantined'])&&empty($status['requires_site_enrollment']),'fresh site has no foreign profile to quarantine');
} else {
    ok(!empty($status['profile_present']),'cloned tenant retains stored profile as diagnostic evidence');
    ok(!empty($status['foreign_profile_quarantined'])&&!empty($status['requires_site_enrollment']),'mismatched profile is quarantined until explicit enrollment');
    ok(in_array($status['profile_binding_state'],array('foreign_origin','environment_drift'),true),'mismatched profile state remains explicit');
}

fwrite(STDOUT,"mad4b.portable-readonly.cross-tenant.$scenario: PASS\n");

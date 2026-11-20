<?php
/** Bounded cross-company CWV admission: synthetic state only, no WordPress/DB. */
define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['profile'] = array();
$GLOBALS['opts'] = array();
$GLOBALS['scheduled'] = false;
$GLOBALS['option_reads'] = 0;
class MAD4B_SCP_Site_Profile {
    public static function origin_enrolled() { return ! empty( $GLOBALS['profile']['enrolled'] ); }
    public static function site_urls_match_enrollment() { return ! empty( $GLOBALS['profile']['urls'] ); }
    public static function status() { return $GLOBALS['profile']['status'] ?? array(); }
    public static function feature_enabled( $f ) {
        return $f === 'core_web_vitals' && ! empty( $GLOBALS['profile']['feature'] );
    }
    public static function site_origin() { return $GLOBALS['profile']['origin'] ?? ''; }
}
function get_option( $key, $fallback = null ) { ++$GLOBALS['option_reads']; return $GLOBALS['opts'][$key] ?? $fallback; }
function wp_next_scheduled( $hook ) { return $hook === 'mad4b_core_web_vitals_daily' ? $GLOBALS['scheduled'] : false; }
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-cwv-site-admission.php';
function check( $value, $why ) { if ( ! $value ) throw new RuntimeException( 'CWV SITE ADMISSION: ' . $why ); }
function reset_fixture() {
    $GLOBALS['profile'] = array(
        'enrolled' => true, 'urls' => true, 'feature' => false,
        'origin' => 'https://tours.example.invalid',
        'status' => array( 'authority_ready' => true, 'deployment_binding_match' => true ),
    );
    $GLOBALS['opts'] = array();
    $GLOBALS['scheduled'] = false;
    $GLOBALS['option_reads'] = 0;
}
function legacy_fixture() {
    $GLOBALS['opts']['mad4b_scp_cwv_settings_v1'] = array( 'page_url' => 'https://tours.example.invalid/tour' );
    $GLOBALS['opts']['mad4b_scp_core_web_vitals_v1'] = array( 'devices' => array( 'mobile' => array( 'lcp' => 1200 ) ) );
    $GLOBALS['scheduled'] = time() + 240;
}
$count = 0;
reset_fixture();
check( MAD4B_SCP_CWV_Site_Admission::admitted() === false, 'Fresh company without opt-in denied' ); ++$count;
$GLOBALS['profile']['enrolled'] = false;
check( ! MAD4B_SCP_CWV_Site_Admission::admitted(), 'Unenrolled site denied' ); ++$count;
reset_fixture(); $GLOBALS['profile']['urls'] = false;
check( ! MAD4B_SCP_CWV_Site_Admission::admitted(), 'Mismatched URL denied' ); ++$count;
reset_fixture(); $GLOBALS['profile']['status']['deployment_binding_match'] = false;
check( ! MAD4B_SCP_CWV_Site_Admission::admitted(), 'Cloned deployment denied' ); ++$count;
reset_fixture(); $GLOBALS['profile']['status']['authority_ready'] = false;
check( ! MAD4B_SCP_CWV_Site_Admission::admitted(), 'Unqualified binding denied' ); ++$count;
reset_fixture(); $GLOBALS['profile']['feature'] = true;
check( MAD4B_SCP_CWV_Site_Admission::admitted(), 'Explicit qualified opt-in admitted' ); ++$count;
check( $GLOBALS['option_reads'] === 0, 'Explicit feature has no legacy option reads' ); ++$count;
reset_fixture(); legacy_fixture();
check( MAD4B_SCP_CWV_Site_Admission::admitted(), 'Existing exact-bound active Tours instance admitted' ); ++$count;
$GLOBALS['opts']['mad4b_scp_cwv_settings_v1']['page_url'] = 'https://wayfa.example.invalid/tour';
check( ! MAD4B_SCP_CWV_Site_Admission::admitted(), 'Cross-company copied settings denied' ); ++$count;
$GLOBALS['opts']['mad4b_scp_cwv_settings_v1']['page_url'] = 'https://tours.example.invalid.attacker.tld/tour';
check( ! MAD4B_SCP_CWV_Site_Admission::admitted(), 'Host-prefix impersonation denied' ); ++$count;
$GLOBALS['opts']['mad4b_scp_cwv_settings_v1']['page_url'] = 'http://tours.example.invalid/tour';
check( ! MAD4B_SCP_CWV_Site_Admission::admitted(), 'Scheme downgrade denied' ); ++$count;
$GLOBALS['opts']['mad4b_scp_cwv_settings_v1']['page_url'] = 'https://user:pass@tours.example.invalid/tour';
check( ! MAD4B_SCP_CWV_Site_Admission::admitted(), 'Credential-embedded URL denied' ); ++$count;
$GLOBALS['opts']['mad4b_scp_cwv_settings_v1']['page_url'] = 'https://tours.example.invalid:8080/tour';
check( ! MAD4B_SCP_CWV_Site_Admission::admitted(), 'Port difference denied' ); ++$count;
reset_fixture(); legacy_fixture(); $GLOBALS['scheduled'] = false;
check( ! MAD4B_SCP_CWV_Site_Admission::admitted(), 'Stale config without Cron not auto-activated' ); ++$count;
reset_fixture(); legacy_fixture(); unset($GLOBALS['opts']['mad4b_scp_core_web_vitals_v1']);
check( ! MAD4B_SCP_CWV_Site_Admission::admitted(), 'Cron alone cannot activate without report' ); ++$count;
reset_fixture(); legacy_fixture(); unset($GLOBALS['opts']['mad4b_scp_cwv_settings_v1']);
check( ! MAD4B_SCP_CWV_Site_Admission::admitted(), 'Cron alone cannot activate without settings' ); ++$count;
reset_fixture(); legacy_fixture(); $GLOBALS['profile']['feature'] = true; $GLOBALS['profile']['status']['authority_ready'] = false;
check( ! MAD4B_SCP_CWV_Site_Admission::admitted(), 'Explicit opt-in cannot override authority mismatch' ); ++$count;
echo 'PASS: CWV_SITE_SCOPED_ADMISSION ' . $count . ' legacy/tenant/security cases; no writes' . PHP_EOL;

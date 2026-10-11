<?php
define( 'ABSPATH', __DIR__ );
class WP_Error { public $code; public function __construct( $code, $message='', $data=null ) { $this->code=$code; } }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
final class MAD4B_SCP_Site_Profile {
    public static $configured = true;
    public static $ready = true;
    public static $urls = true;
    public static $environment = 'local';
    public static $origin = 'https://example.test';
    public static function configured() { return self::$configured; }
    public static function status() { return array(
        'authority_ready'=>self::$ready, 'origin_match'=>self::$ready,
        'environment_match'=>self::$ready, 'deployment_binding_match'=>self::$ready,
        'profile_authority_quarantined'=>!self::$ready,
    ); }
    public static function site_urls_match_enrollment() { return self::$urls; }
    public static function site_uuid() { return '11111111-1111-4111-8111-111111111111'; }
    public static function site_origin() { return self::$origin; }
    public static function current_environment() { return self::$environment; }
    public static function profile_digest() { return str_repeat( 'a', 64 ); }
}
final class MAD4B_SCP_Adaptive_Operations_Context {
    public static $epoch = 2;
    public static $generation = 'b';
    public static $fault = '';
    public static function current() {
        if ( self::$fault === 'provider' ) return new WP_Error( 'provider_not_ready' );
        return array(
            'contract'=>'mad4b.adaptive-operations-context.v1',
            'site_uuid'=>MAD4B_SCP_Site_Profile::site_uuid(),
            'environment'=>MAD4B_SCP_Site_Profile::current_environment(),
            'profile_digest'=>MAD4B_SCP_Site_Profile::profile_digest(),
            'runtime_generation'=>str_repeat( self::$generation, 64 ),
            'restore_epoch'=>self::$epoch,
            'artifact_sha256'=>str_repeat( 'c', 64 ),
            'external_record_sha256'=>str_repeat( 'd', 64 ),
            'origin_sha256'=>hash( 'sha256', MAD4B_SCP_Site_Profile::site_origin() ),
        );
    }
}
final class MAD4B_SCP_Content_Experience_Profiles {
    public static function profile_status( $input=array() ) { return array(
        'contract'=>'mad4b.content-experience-profiles.v1',
        'profiles'=>array(
            array( 'slug'=>'tour-guide', 'post_type'=>'excursion', 'enabled'=>true,
                   'runtime_post_type_ready'=>true, 'helper_catalog_match'=>true,
                   'authority_current'=>true, 'migration_required'=>false,
                   'revision'=>3, 'authority_sha256'=>str_repeat( 'f', 64 ) ),
        ) );
    }
}
require_once __DIR__ . '/../includes/class-mad4b-scp-aci01-runtime-binding.php';
require_once __DIR__ . '/../includes/class-mad4b-scp-aci01-semantic-recipe.php';
function check( $ok, $msg ) { if( !$ok ) { fwrite( STDERR, 'FAIL: '.$msg."\n" ); exit( 1 ); } }
$cls='MAD4B_SCP_ACI01_Runtime_Binding';
$bound=$cls::current();
check( is_array( $bound ), 'exact local identity accepted' );
check( $bound['environment'] === 'local' && $bound['restore_epoch'] === 2, 'local + epoch' );
check( $cls::same( $bound, $bound ), 'same binding' );
check( $cls::is_valid( $bound ), 'valid exact binding' );
check( $cls::same( $bound, array_merge( $bound, array( 'restore_epoch'=>3 ) ) ) === false, 'restore epoch drift' );
check( $cls::same( $bound, array_merge( $bound, array( 'runtime_generation'=>str_repeat( 'e', 64 ) ) ) ) === false, 'generation drift' );
check( $cls::same( $bound, array_merge( $bound, array( 'profile_digest'=>str_repeat( 'e', 64 ) ) ) ) === false, 'policy drift' );
check( $cls::is_valid( array_merge( $bound, array( 'origin'=>'https://cloned.test' ) ) ) === false, 'origin clone' );
check( $cls::is_valid( array_merge( $bound, array( 'origin'=>'https://a:password@example.test' ) ) ) === false, 'origin creds' );
check( $cls::is_valid( array_merge( $bound, array( 'environment'=>'unknown' ) ) ) === false, 'unknown environment' );
MAD4B_SCP_Site_Profile::$ready=false;
check( is_wp_error( $cls::current() ), 'configured-but-quarantined denied' );
MAD4B_SCP_Site_Profile::$ready=true;
MAD4B_SCP_Site_Profile::$urls=false;
check( is_wp_error( $cls::current() ), 'site urls no longer bound denied' );
MAD4B_SCP_Site_Profile::$urls=true;
MAD4B_SCP_Adaptive_Operations_Context::$fault='provider';
check( is_wp_error( $cls::current() ), 'generation provider unavailable denied' );
MAD4B_SCP_Adaptive_Operations_Context::$fault='';
MAD4B_SCP_Adaptive_Operations_Context::$epoch=0;
check( is_wp_error( $cls::current() ), 'invalid epoch denied' );
MAD4B_SCP_Adaptive_Operations_Context::$epoch=2;
check( is_array( $cls::current() ), 'healthy restored' );

$semantic='MAD4B_SCP_ACI01_Semantic_Recipe';
$job=array( 'content_type'=>'tour-guide' );
$intake=array( 'scope'=>$bound, 'candidate'=>array( 'post_type'=>'excursion',
    'requires_native_relation_review'=>true ) );
$inventory=MAD4B_SCP_Content_Experience_Profiles::profile_status();
$s=$semantic::resolve( $job, $intake, $inventory );
check( $s['status']==='NEEDS_EVIDENCE' && $s['mapping']['post_type']==='excursion', 'certified mapping still lacks recipe facts' );
check( in_array( 'native_relation_identity', $s['obligations'], true ), 'WPML/post relation required' );
check( in_array( 'native_language_identity', $s['obligations'], true ), 'language identity required' );
check( $s['authorizing']===false && $s['mutation_performed']===false, 'semantic is inert' );
check( $s['semantic_fingerprint_sha256']===$semantic::resolve( $job, $intake, $inventory )['semantic_fingerprint_sha256'], 'deterministic' );
$wrong=$intake; $wrong['candidate']['post_type']='post';
check( $semantic::resolve( $job, $wrong, $inventory )['status']==='DENIED', 'cross-type denied' );
$unknown=array( 'content_type'=>'article' );
check( $semantic::resolve( $unknown, $intake, $inventory )['status']==='DENIED', 'no name guessing' );
$stale=$inventory; $stale['profiles'][0]['authority_current']=false;
check( $semantic::resolve( $job, $intake, $stale )['status']==='DENIED', 'stale profile denied' );
$disabled=$inventory; $disabled['profiles'][0]['enabled']=false;
check( $semantic::resolve( $job, $intake, $disabled )['status']==='DENIED', 'disabled profile denied' );
$dupe=$inventory; $dupe['profiles'][]=$dupe['profiles'][0];
check( $semantic::resolve( $job, $intake, $dupe )['status']==='DENIED', 'ambiguous mapping denied' );
$empty=$inventory; $empty['profiles']=array();
check( $semantic::resolve( $job, $intake, $empty )['status']==='DENIED', 'unconfigured recipe denied' );
echo "ACI01_P0_GOVERNED_SEMANTIC: PASS\n";

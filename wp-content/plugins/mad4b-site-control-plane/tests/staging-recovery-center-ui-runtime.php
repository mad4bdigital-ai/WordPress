<?php
/** Passive admin GET journey must not create tickets, mutate, or invent OAuth completion. */
define( 'ABSPATH', __DIR__ );
function current_user_can($c){return 'manage_options' === $c;}
function is_wp_error($v){return $v instanceof WP_Error;}
function esc_html($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function esc_url($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function admin_url($v=''){return 'https://staging.example.test/wp-admin/'.$v;}
class WP_Error { private $code; function __construct($c,$m=''){$this->code=$c;} function get_error_code(){return $this->code;} }
function check($good,$label){if(!$good){fwrite(STDERR,"FAIL:$label\n");exit(1);}}
$GLOBALS['operations']=array('site'=>0,'binding_status'=>0,'binding_readback'=>0,'write'=>0,'google'=>0,'context'=>0,'mutation'=>0);
class MAD4B_SCP_Site_Profile {
    static $environment='staging';
    static function status(){++$GLOBALS['operations']['site'];return array(
        'configured_environment'=>self::$environment,'wordpress_environment'=>self::$environment,
        'origin_match'=>true,'environment_match'=>true,'authority_ready'=>true,
        'site_uuid'=>'e70d1e1a-d60f-426a-b250-484e24fc5d6d','revision'=>9);}
}
class MAD4B_SCP_Deployment_Binding_Native {
    static function status(){++$GLOBALS['operations']['binding_status']; return array('state'=>'ALREADY_HAS_PROFILE_BINDING');}
    static function readback($in){++$GLOBALS['operations']['binding_readback'];return array('functional_scope_ready_for_census'=>true,
        'site_identity_preserved'=>true,'site_uuid'=>$in['expected_site_uuid'],
        'profile_revision'=>$in['minimum_profile_revision']);}
    static function apply($in){++$GLOBALS['operations']['mutation'];throw new Exception('UNSAFE');}
}
class MAD4B_SCP_Staging_Write_Authority_Convergence {
    static function status(){++$GLOBALS['operations']['write'];return array('write_ready'=>true,
        'candidate_binding'=>array('match'=>true));}
    static function apply($in){++$GLOBALS['operations']['mutation'];throw new Exception('UNSAFE');}
}
class MAD4B_SCP_Google_Drive_Context {
    static $revocation_pending=true;
    static function connection_status(){++$GLOBALS['operations']['google'];return array(
        'connected'=>true,'read_available'=>!self::$revocation_pending,
        'revocation_pending'=>self::$revocation_pending);}
}
class MAD4B_SCP_Context_Authority {
    static function status(){++$GLOBALS['operations']['context'];return array(
        'quarantined_source_record_count'=>1,'quarantined_asset_record_count'=>4);}
}
require dirname(__DIR__).'/includes/class-mad4b-scp-staging-recovery-center.php';
ob_start();
MAD4B_SCP_Staging_Recovery_Center::render();
$rendered=ob_get_clean();
check(strpos($rendered,'GOOGLE_REVOCATION_PENDING')!==false,'Google pending visible');
check(strpos($rendered,'tab=google-drive')!==false,'Real Google destination rendered');
check($GLOBALS['operations']['binding_readback']===1,'new-request binding readback observed');
check($GLOBALS['operations']['mutation']===0,'Passive GET never invoked protected writer');
MAD4B_SCP_Site_Profile::$environment='production';
ob_start();
MAD4B_SCP_Staging_Recovery_Center::render();
$denied=ob_get_clean();
check(strpos($denied,'SITE_NOT_EXACT_STAGING')!==false,'Production read UI denies recovery');
check($GLOBALS['operations']['mutation']===0,'Production no mutations');
check($GLOBALS['operations']['write']===1,'Production never reads staging write authority');
echo "STAGING_RECOVERY_CENTER_PASSIVE_UI: PASS\n";

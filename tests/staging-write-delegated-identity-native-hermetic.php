<?php
/** Original staged write authority delegated-user recognition, no grants or WordPress. */
define('ABSPATH',__DIR__.'/');
function absint($value) {return abs((int)$value);}
class WP_Error {private $code;function __construct($code){$this->code=$code;}}
final class MAD4B_SCP_Environment {
    public static $environment='staging';
    public static function effective(){return self::$environment;}
}
final class MAD4B_SCP_Policy {
    public static $response=true;
    public static function can_connect_user($user){return self::$response;}
}
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-staging-write-authority.php';
function delegated_check($ok,$message) {if(!$ok){fwrite(STDERR,"FAIL: $message\n");exit(1);}}
$user=128;
$record=array('slug'=>'mad4b-delegated-user-'.$user,'status'=>'enabled',
    'wp_user_id'=>$user,'environment'=>'staging');
$eligible=static function($row,$uid) {
    return MAD4B_SCP_Staging_Write_Authority::is_exact_delegated_oauth_agent($row,$uid)===true;
};
delegated_check($eligible($record,$user),'Already explicitly delegated exact user recognised without grant');
foreach(array(false,null,new WP_Error('runtime_policy_unavailable'),1) as $decision) {
    MAD4B_SCP_Policy::$response=$decision;
    delegated_check(!$eligible($record,$user),'Non-boolean exact policy admission denied');
}
MAD4B_SCP_Policy::$response=true;
$alterations=array(
    'bad_slug'=>array('slug'=>'mad4b-governed-write'),
    'other_user'=>array('wp_user_id'=>129),
    'different_environment'=>array('environment'=>'production'),
    'disabled'=>array('status'=>'disabled'),
    'wrong_name_prefix'=>array('slug'=>'mad4b-delegated-user-0128'),
);
foreach($alterations as $name=>$change) {
    delegated_check(!$eligible(array_replace($record,$change),$user),
        'Other delegated principal or environment rejected: '.$name);
}
MAD4B_SCP_Environment::$environment='production';
delegated_check(!$eligible($record,$user),'Staging source cannot be reused across Production');
delegated_check(!$eligible($record,0),'Anonymous caller never recognized');
delegated_check(!$eligible(array(),$user),'Missing original Registry owner never recognized');
echo "PASS: STAGING_DELEGATED_EXACT_POLICY 13 original-source positive and denial cases\n";

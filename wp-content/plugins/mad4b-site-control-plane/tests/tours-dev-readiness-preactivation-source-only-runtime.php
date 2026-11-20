<?php
/** Exact source/compatibility and no-external-send contract; no WP boot. */
error_reporting(E_ALL);
define('ABSPATH', __DIR__ . '/');
class WP_Error {
    private $code;
    public function __construct($code,$message='',$data=null){$this->code=$code;}
    public function get_error_code(){return $this->code;}
}
function assert_is($ok,$reason){if(!$ok){fwrite(STDERR,'FAIL: '.$reason.PHP_EOL);exit(1);}}
$GLOBALS['side_effects']=0;
function add_action(){ $GLOBALS['side_effects']++; throw new RuntimeException('Boot not permitted'); }
function register_rest_route(){ $GLOBALS['side_effects']++; throw new RuntimeException('REST registration not permitted'); }
function update_option(){ $GLOBALS['side_effects']++; throw new RuntimeException('WP write forbidden'); }
function wp_mail(){ $GLOBALS['side_effects']++; throw new RuntimeException('Mail forbidden'); }
$root=dirname(__DIR__);
$main=file_get_contents($root.'/mad4b-site-control-plane.php');
$parent=file_get_contents($root.'/includes/class-mad4b-scp-readiness-preactivation.php');
$team=file_get_contents($root.'/includes/class-mad4b-scp-readiness-team-preparation.php');
$events=file_get_contents($root.'/includes/class-mad4b-scp-readiness-event-automation.php');
assert_is(is_string($main)&&is_string($parent)&&is_string($team)&&is_string($events),
 'Original source-only files must all exist');
$sha=array(
 'parent'=>'6042798ef0eaa09578ae9e9b014373554af36bab0dcf56c40d4b25ef0847c97b','team'=>'32ef4b54ad67d541fe1de2cd827b14dca76300cc005bc5df77164987df752615','events'=>'5a18636dda2e1ec5df8ee677f8869c61ccf980c8e1b0d75cd075a6106e1ffba5',
);
assert_is(hash('sha256',$parent)===$sha['parent'],'Reviewed parent source digest');
assert_is(hash('sha256',$team)===$sha['team'],'Original frozen team source digest');
assert_is(hash('sha256',$events)===$sha['events'],'Reviewed automation source digest');
foreach(array(
 'class-mad4b-scp-readiness-preactivation.php',
 'class-mad4b-scp-readiness-team-preparation.php',
 'class-mad4b-scp-readiness-event-automation.php') as $name){
 assert_is(strpos($main,$name)===false,'Main bootstrap cannot load source-only '.$name);
}
assert_is(strpos($parent,"require_once __DIR__.'/class-mad4b-scp-readiness-team-preparation.php';")!==false,
 'Parent requires original team dependency');
assert_is(strpos($parent,"require_once __DIR__.'/class-mad4b-scp-readiness-event-automation.php';")!==false,
 'Parent requires original event dependency');
// The only parent deviations: route-registry scheduling and narrower admin
// navigation permission. Reconstruct exact installed original from the code.
$rewritten=<<<'CODE'
        // Keep the company page under the shared parent-before-child route policy.
        // If the Admin Route Registry is absent, do not install a fallback menu.
        if(class_exists('MAD4B_SCP_Admin_Route_Registry',false)){
            MAD4B_SCP_Admin_Route_Registry::register(self::PAGE,'manage_options');
            MAD4B_SCP_Admin_Route_Registry::schedule_submenu(array(__CLASS__,'menu'),36);
        }
CODE;
$installed="        add_action('admin_menu',array(__CLASS__,'menu'),36);";
assert_is(substr_count($parent,$rewritten)===1,'Exact parent route successor patch');
$restored=str_replace($rewritten,$installed,$parent);
assert_is(substr_count($restored,"'manage_options',self::PAGE,array(__CLASS__,'screen'));")===1,
 'Exactly one narrowed admin menu capability');
$restored=str_replace("'manage_options',self::PAGE,array(__CLASS__,'screen'));",
    "self::CAP,self::PAGE,array(__CLASS__,'screen'));",$restored);
assert_is(hash('sha256',$restored)==='d9a07f0e9d9105e24cd2a27ab6ef1ce8c086c8f4e8e3688724e1d23bfd74f915',
 'Rebuilt source equals exact installed Tours.dev source');
assert_is(strpos($parent,"MAD4B_SCP_Admin_Route_Registry::schedule_submenu(")!==false,
 'Parent submenu must wait for shared parent');
assert_is(strpos($parent,"MAD4B_SCP_Admin_Route_Registry::register(self::PAGE,'manage_options');")!==false,
 'Parent admin route requires manage_options');
// Only one compatibility edit is allowed in the event source.
$compatible='if(0===strpos($type,\'term:\')){';
$original='if(str_starts_with($type,\'term:\')){';
assert_is(substr_count($events,$compatible)===1,'Exactly one PHP 7.4 prefix guard');
assert_is(hash('sha256',str_replace($compatible,$original,$events))==='ad8eac90e66ac695ea2ee6aab0c06ed5cb90fea2ffb7415ee6bc45d85b53a01d',
 'Restored event source equals frozen original');
foreach(array('term:x'=>true,'term:'=>true,'terminus'=>false,'xterm:a'=>false,'TERM:a'=>false) as $v=>$expected){
 assert_is((0===strpos($v,'term:'))===$expected,'PHP 7.4 prefix semantics: '.$v);
}
require_once $root.'/includes/class-mad4b-scp-readiness-preactivation.php';
assert_is(class_exists('MAD4B_SCP_Readiness_Team_Preparation',false) &&
 class_exists('MAD4B_SCP_Readiness_Event_Automation',false) &&
 class_exists('MAD4B_SCP_Readiness_PreActivation',false),
 'Three-source class closure');
assert_is(MAD4B_SCP_Readiness_PreActivation::execution_locked()===true,
 'No provider task or digest execution can be enabled');
assert_is(MAD4B_SCP_Readiness_Event_Automation::disabled()===true,
 'External dispatch is permanently disabled in this source slice');
$denied=MAD4B_SCP_Readiness_Event_Automation::rest_dispatch();
assert_is($denied instanceof WP_Error && $denied->get_error_code()==='readiness_external_mail_locked',
 'External SMTP/digest dispatch is denied');
assert_is($GLOBALS['side_effects']===0,'No hooks, WordPress writes, REST routes or mail at load time');
echo "PASS: three source modules, exact frozen roots, locked mail/provider execution, no bootstrap".PHP_EOL;

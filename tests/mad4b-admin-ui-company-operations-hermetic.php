<?php
/** Live Admin Experience AJAX authority: both administrator and scoped
 * Master Admin, no capability grants, no content/orders/payment writes. */
declare(strict_types=1);
define('ABSPATH','/');define('DAY_IN_SECONDS',86400);
class AjaxResponse extends RuntimeException {
 public array $body;
 public function __construct(int $status,array $data){parent::__construct('ajax', $status);$this->body=$data;}
}
$GLOBALS['user_caps']=[];$GLOBALS['options']=[];$GLOBALS['checks']=0;
function add_action(...$args){}
function current_user_can($cap):bool{return !empty($GLOBALS['user_caps'][$cap]);}
function check_ajax_referer($action,$field):void{$GLOBALS['checks']++;if($action!=='mad4b_admin_ui_auto_scan_v1'||($_POST[$field]??null)!=='valid')throw new AjaxResponse(403,['code'=>'bad_nonce']);}
function wp_send_json_error($data,$code=400):never{throw new AjaxResponse($code,$data);}
function wp_send_json_success($data):never{throw new AjaxResponse(200,$data);}
function wp_unslash($value){return $value;}
function get_option($name,$default=[]){return $GLOBALS['options'][$name]??$default;}
function update_option($name,$value,$autoload=false){$GLOBALS['options'][$name]=$value;return true;}
require dirname(__DIR__).'/wp-content/plugins/mad4b-commerce/modules/presentation/company-admin-ui-auto-scan-v1.php';
$pass=0;$assert=function(bool $value,string $name)use(&$pass):void{if(!$value)throw new RuntimeException('FAILED '.$name);$pass++;};
$payload=['contract'=>MAD4B_Admin_UI_Auto_Scan_V1::CONTRACT,'route'=>'admin.php?page=company','mode'=>'dark','viewport'=>1366,
 'metrics'=>['examined'=>3,'auto_text_fixed'=>1,'auto_surfaces_fixed'=>0,'remaining_issues'=>1,'horizontal_overflow_px'=>0,'uninspectable_frames'=>0,'scan_duration_ms'=>70],
 'families'=>['wordpress'=>3], 'issues'=>[['type'=>'text_contrast','family'=>'wordpress','region'=>'native_admin_panel','count'=>1]]];
$_POST=['nonce'=>'valid','payload'=>json_encode($payload)];
$call=function():AjaxResponse {try{MAD4B_Admin_UI_Auto_Scan_V1::receive();throw new RuntimeException('AJAX should terminate');}catch(AjaxResponse $x){return $x;}};
$GLOBALS['user_caps']=[];$x=$call();$assert($x->getCode()===403,'anonymous cannot submit');
$assert(($x->body['code']??'')==='not_authorized','anonymous reason verified');
$GLOBALS['user_caps']=['edit_posts'=>true];$assert($call()->getCode()===403,'ordinary editor cannot submit');
$GLOBALS['user_caps']=['mad4b_manage_company_operations'=>true];$x=$call();$assert($x->getCode()===200,'scoped Master Admin can submit private diagnostic');
$assert(($x->body['screens']??null)===1,'first scan stored');
$assert(count($GLOBALS['options'])===1,'only bounded scanner option written');
$GLOBALS['user_caps']=['manage_options'=>true];$assert($call()->getCode()===200,'WordPress Administrator remains allowed');
$_POST['nonce']='invalid';$assert($call()->getCode()===403,'nonce still mandatory for administrator');
$_POST['nonce']='valid';$payload['route']='admin.php?nonce=secret';$_POST['payload']=json_encode($payload);
$assert($call()->getCode()===400,'URL carrying nonce not persisted');
$payload['route']='admin.php?page=company';$_POST['payload']=json_encode($payload);$assert($call()->getCode()===200,'original safe route accepted');
$assert($GLOBALS['checks']>=4,'nonce verification was exercised on permitted roles');
$assert(count($GLOBALS['options'])===1,'no other WordPress settings modified');
echo json_encode(['contract'=>'mad4b.admin-ui.company-operations-v1','passed'=>$pass,'grants_created'=>0,'orders_created'=>0,'user_roles_modified'=>false]),"\n";

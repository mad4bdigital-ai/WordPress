<?php
define('ABSPATH','/');
$GLOBALS['revoked']=false;
$GLOBALS['logged']=true;
$GLOBALS['nonce']='ok';
function add_action($name,$fn,$priority=10){}
function is_user_logged_in(){return $GLOBALS['logged'];}
function is_admin(){return true;}
function current_user_can($cap){return !$GLOBALS['revoked'];}
function get_current_user_id(){return 123;}
function sanitize_key($s){return strtolower(preg_replace('/[^a-z0-9_\\-]/i','',(string)$s));}
function sanitize_text_field($s){return trim((string)$s);}
function wp_unslash($s){return $s;}
function wp_verify_nonce($s,$action){return $s===$GLOBALS['nonce']&&$action==='mad4b_company_content_job_draft_create';}
class MAD4B_Admin_Surface_Registry_V1 {
  public static function allowed($slug,$op){
    return $slug==='mad4b-company-content-job-draft' && $op==='draft'
        && !$GLOBALS['revoked'];
  }
}
class MAD4B_SCP_Policy {
  public static function mutation_gate_status(){return ['effective'=>$GLOBALS['gate_effective']??true];}
}
class MAD4B_SCP_Site_Profile {
  public static function status(){return [
    'configured'=>true,'origin_match'=>true,'deployment_binding_match'=>true,
    'site_uuid'=>'11111111-1111-4111-8111-111111111111'];
  }
}
$path=getenv('MAD4B_COMPANY_DRAFT_SOURCE');
if(!$path||!is_file($path))exit(90);
require $path;
$class='MAD4B_Company_Content_Job_Draft_V1';
$_SERVER['REQUEST_METHOD']='POST';
$_POST=['action'=>$class::ACTION,'_wpnonce'=>'ok','subject'=>'One approved title',
       'language'=>'en','content_type'=>'article'];
$scope=['site_uuid'=>'11111111-1111-4111-8111-111111111111',
        'tenant_ref'=>'trusted-company','brand_ref'=>'trusted-brand'];
$input=['brand_id'=>'trusted-brand','subject'=>'One approved title','language'=>'en',
        'country'=>'eg','content_type'=>'article','research_depth'=>'standard',
        'automation_level'=>'strict','reason'=>$class::REASON,'desired_publish_at'=>''];
function check($yes,$msg){if(!$yes){fwrite(STDERR,$msg."\n");exit(98);}}
check($class::admitted($input,$scope),'POSITIVE_DENIED');
$bad=$input;$bad['automation_level']='autonomous';
check(!$class::admitted($bad,$scope),'AUTO_MODE_ACCEPTED');
$bad=$input;$bad['brand_id']='other-brand';
check(!$class::admitted($bad,$scope),'BRAND_MISMATCH_ACCEPTED');
$GLOBALS['nonce']='not-ok';
check(!$class::admitted($input,$scope),'BAD_NONCE_ACCEPTED');
$GLOBALS['nonce']='ok';$GLOBALS['gate_effective']=false;
check(!$class::admitted($input,$scope),'DISABLED_RUNTIME_GATE_ACCEPTED');
$GLOBALS['gate_effective']=true;
$GLOBALS['revoked']=true;
check(!$class::admitted($input,$scope),'REVOKED_ROLE_ACCEPTED');
$GLOBALS['revoked']=false;$_SERVER['REQUEST_METHOD']='GET';
check(!$class::admitted($input,$scope),'GET_ACCEPTED');
echo "PASS_COMPANY_DRAFT_EXACT_POST_NONCE_SCOPE_STRICT_AND_REVOCATION\n";

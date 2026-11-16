<?php
/**
 * Original signed Company Code page comparison and invalid binding regressions.
 * Receipt signature validator is a stub; no real cryptography is claimed.
 * No files, providers, runtime writes, OAuth or WordPress DB.
 */
define('ABSPATH',__DIR__.'/');
$GLOBALS['code_abilities']=array();
$GLOBALS['code_receipt_calls']=0;
function wp_register_ability($name,$cfg){$GLOBALS['code_abilities'][$name]=$cfg;}
function wp_has_ability($name){return isset($GLOBALS['code_abilities'][$name]);}
function wp_json_encode($value,$flags=0){return json_encode($value,$flags);}
class WP_Error {
 private $code;
 public function __construct($code,$description=''){$this->code=$code;}
 public function get_error_code(){return $this->code;}
}
function is_wp_error($r){return $r instanceof WP_Error;}
class MAD4B_SCP_Execution_Receipt {
 public static function verify($receipt) {
    ++$GLOBALS['code_receipt_calls'];
    $signed=($receipt['test_signature_valid']??false)===true;
    return array('valid'=>$signed,'cryptographic_signature_verified'=>$signed);
 }
}
function test_code($cond,$text){
 if(!$cond)throw new RuntimeException('FAIL COMPANY CODE: '.$text);
}
function canonical_code($value) {
 if(!is_array($value))return $value;
 if($value && array_keys($value)!==range(0,count($value)-1))ksort($value,SORT_STRING);
 foreach($value as $key=>$item)$value[$key]=canonical_code($item);
 return $value;
}
function code_hash($value){return hash('sha256',json_encode(canonical_code($value),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));}
function site_page($company,$uuid,$origin,$files) {
 $manifest=array(
    'contract'=>'mad4b.company-code-inventory-page.v1','company_id'=>$company,
    'site_uuid'=>$uuid,'origin'=>$origin,'environment'=>'staging',
    'page_index'=>0,'page_count'=>1,'inventory_sha256'=>str_repeat('a',64),
    'files'=>$files,
 );
 $receipt=array(
  'ability'=>'mad4b/company-code-inventory-page',
  'provider_id'=>'central_company_management',
  'approval_required'=>false,
  'resource_set_sha256'=>code_hash($manifest),
  'target_fingerprint'=>hash('sha256',$company.'|'.$uuid.'|'.$origin),
  'test_signature_valid'=>true,
 );
 return array('manifest'=>$manifest,'receipt'=>$receipt);
}
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-company-code-reconciliation.php';
$c='MAD4B_SCP_Company_Code_Reconciliation';
$c::register_abilities();
test_code(count($GLOBALS['code_abilities'])===2
 && isset($GLOBALS['code_abilities'][$c::PLAN])
 && isset($GLOBALS['code_abilities'][$c::COMPARE]),
 'Only original read-only signed-page compare and plan tools can register');
foreach($GLOBALS['code_abilities'] as $ability=>$definition)
 test_code(($definition['permission_callback']??null)===array('MAD4B_SCP_Policy','can_read')
 && ($definition['input_schema']['additionalProperties']??null)===false
 && ($definition['meta']['mcp']['surface']??null)==='read'
 && ($definition['meta']['annotations']['readonly']??false)===true
 && ($definition['meta']['annotations']['destructive']??true)===false
 && ($definition['input_schema']['required']??null)===array('company_id','source','target'),
 'Read-only provider path/schema/permission mismatch: '.$ability);

$company='company-eg';
$src=site_page($company,'aaaa-1111','https://src.example.invalid',array(
 'plugins/mad4b/plugin.php'=>array('sha256'=>str_repeat('b',64),'owner'=>'protected'),
));
$tgt=site_page($company,'bbbb-2222','https://target.example.invalid',array(
 'plugins/mad4b/plugin.php'=>array('sha256'=>str_repeat('c',64),'owner'=>'protected'),
));
$input=array('company_id'=>$company,'source'=>$src,'target'=>$tgt);
$plan=$c::plan($input);
test_code(is_array($plan)
 && $plan['state']==='ORIGINAL_CORE_SIGNED_PAGES_VALIDATED'
 && $plan['complete_inventory_verified']===false
 && $plan['authorizing']===false && $plan['mutation_performed']===false,
 'A verified page must never be represented as a full approved inventory');
$compared=$c::compare($input);
test_code(is_array($compared)
 && $compared['diff_rows'][0]['decision']==='PROTECT_READ_ONLY'
 && $compared['presence']['different']===1
 && $compared['native_apply_authorized']===false
 && $compared['writes_to_sites']===false
 && $compared['production_mutation']===false,
 'Protected current file must not be overwritten from page-only evidence');
$invalid=$input;$invalid['source']['receipt']['test_signature_valid']=false;
test_code($c::plan($invalid)->get_error_code()==='mad4b_company_code_original_core_signature_invalid',
 'Unsigned receipt must be rejected');
$invalid=$input;$invalid['target']['receipt']['resource_set_sha256']=str_repeat('0',64);
test_code($c::plan($invalid)->get_error_code()==='mad4b_company_code_receipt_evidence_binding_mismatch',
 'Receipt must match its exact manifest digest');
$invalid=$input;$invalid['target']['manifest']['company_id']='foreign-company';
test_code($c::plan($invalid) instanceof WP_Error,
 'Original signed site cannot be relabeled into a different company');
$invalid=$input;
$invalid['source']['manifest']['files']=array(
 'plugins/../../wp-config.php'=>array('sha256'=>str_repeat('b',64),'owner'=>'protected'));
test_code($c::compare($invalid) instanceof WP_Error,
 'Source traversal must fail before planning');
$invalid=$input;$invalid['target']['manifest']['site_uuid']=$src['manifest']['site_uuid'];
$invalid['target']['receipt']['resource_set_sha256']=code_hash($invalid['target']['manifest']);
$invalid['target']['receipt']['target_fingerprint']=hash('sha256',$company.'|'.$src['manifest']['site_uuid'].'|'.$tgt['manifest']['origin']);
test_code($c::plan($invalid)->get_error_code()==='mad4b_company_code_cross_site_or_page_scope_mismatch',
 'Distinct company site IDs are mandatory for source-to-target comparison');
test_code($GLOBALS['code_receipt_calls']>=5,
 'Original receipt verifier must be consulted for positive and negative evidence');
echo "PASS: COMPANY_CODE_SIGNED_PAGE_RECONCILE private plan/compare, no apply\n";

<?php
if(!defined('ABSPATH')||!defined('WP_CLI'))exit(1);
wp_set_current_user(1);
$cls='MAD4B_Guide_Taxonomy_Pricing_V1';
$term=143; // English canonical, offered by approved Tour 60451.
$old=[];
foreach($cls::FIELDS as $field)$old[$field]='';
$new=$old;
$new['mad4b_guide_cost_mode']='override';
$new['mad4b_guide_base_cost']='100.00';
$new['mad4b_guide_cost_currency']='USD';
$new['mad4b_guide_supplier']='self';
$new['mad4b_guide_cost_unit']='per_guide_day';
$new['mad4b_guide_cost_status']='approved';
$new['mad4b_guide_cost_valid_from']='';
$new['mad4b_guide_cost_valid_to']='';
$new['mad4b_guide_cost_note']='Documented and approved company self supplier guide rate for English language.';
$approved=$cls::validate($new);
if(is_wp_error($approved))WP_CLI::error('USD draft validation failed '.$approved->get_error_code());
if(!function_exists('pricing_public_fx_rate')||
   !is_numeric(pricing_public_fx_rate('USD','EGP')))
 WP_CLI::error('Fresh FX rate unavailable for safe multicurrency test');
$mockFields=$old;$mockCert=null;$mockAudit=[];
$get=static function($value,$id,$key,$single)use(&$mockFields,&$mockCert,$term,$cls){
 if((int)$id!==$term)return $value;
 if(in_array((string)$key,$cls::FIELDS,true))
    return [$mockFields[$key]??''];
 if($key===$cls::CERT)return [$mockCert??''];
 return $value;
};
$update=static function($value,$id,$key,$v)use(&$mockFields,&$mockCert,$term,$cls){
 if((int)$id!==$term)return $value;
 if(in_array((string)$key,$cls::FIELDS,true)){$mockFields[$key]=(string)$v;return true;}
 if($key===$cls::CERT){$mockCert=$v;return true;}
 return $value;
};
$add=static function($value,$id,$key,$v,$unique)use(&$mockAudit,$term,$cls){
 if((int)$id===$term && $key===$cls::AUDIT){$mockAudit[]=$v;return true;}
 return $value;
};
add_filter('get_term_metadata',$get,30000,4);
add_filter('update_term_metadata',$update,30000,4);
add_filter('add_term_metadata',$add,30000,5);
$actual=$cls::raw($term);
if($actual!==$old)WP_CLI::error('Initial test term metadata not blank');
$save=$cls::save(['scope'=>'term','term_id'=>$term,
 'revision'=>0,'raw_hash'=>$cls::rawHash($actual),'fields'=>$new]);
if(is_wp_error($save))WP_CLI::error('Mock USD Guide term approval failed '.$save->get_error_code());
$rate=$cls::resolved($term);
$expected=round(100*(float)pricing_public_fx_rate('USD','EGP'),2);
if(is_wp_error($rate)||$rate['source_currency']!=='USD' ||
   $rate['source_unit_cost']!=100 || $rate['unit_cost']!=$expected ||
   $rate['policy_source']!=='approved_taxonomy_term' || count($mockAudit)!==1)
 WP_CLI::error('Converted EGP receipt or audited approval mismatch');
WP_CLI::log('PASS 100 USD supplier rate converts to '.$expected.' EGP using verified FX');
$package=MAD4B_Guide_Service_Composition_V1::guide(60451,'english');
if(is_wp_error($package)||($package['cost']??0)!=$expected ||
   ($package['source_currency']??'')!=='USD' ||
   ($package['source_unit_cost']??null)!=100 ||
   ($package['cost_policy_source']??'')!=='approved_taxonomy_term')
 WP_CLI::error('Package Guide component did not consume Taxonomy USD price');
WP_CLI::log('PASS Package Cost consumes normalized EGP with original USD supplier receipt');
$preview=MAD4B_Guide_Daily_Base_Cost_V1::preview('english',2,2);
if(is_wp_error($preview)||$preview['base_cost']!==round($expected*4,2))
 WP_CLI::error('Generic Guide price preview did not consume Taxonomy currency');
WP_CLI::log('PASS Daily Guide preview consumes Taxonomy currency and count');
$mockFields['mad4b_guide_base_cost']='125.00';
$stale=$cls::resolved($term);
if(!is_wp_error($stale)||$stale->get_error_code()!=='guide_cost_term_changed_since_approval')
 WP_CLI::error('Unreviewed native JetEngine field edit changed approved cost');
WP_CLI::log('PASS unreviewed term change blocks cost');
$mockFields['mad4b_guide_base_cost']='100.00';
$conflict=$cls::save(['scope'=>'term','term_id'=>$term,
 'revision'=>0,'raw_hash'=>$cls::rawHash($mockFields),'fields'=>$new]);
if(!is_wp_error($conflict)||$conflict->get_error_code()!=='guide_cost_approval_revision_conflict')
 WP_CLI::error('Stale supervisor review not blocked');
WP_CLI::log('PASS stale source revision rejected');
remove_filter('get_term_metadata',$get,30000);
remove_filter('update_term_metadata',$update,30000);
remove_filter('add_term_metadata',$add,30000);
foreach($cls::FIELDS as $field)
 if(get_term_meta($term,$field,true)!=='')
  WP_CLI::error('Mock modified live Guide Language term');
if(get_term_meta($term,$cls::CERT,true)!=='')
 WP_CLI::error('Mock wrote approval to production term');
WP_CLI::log('PASS no live term values modified');
WP_CLI::success('GUIDE_MULTICURRENCY_MOCK_APPROVAL_ACCEPTED');

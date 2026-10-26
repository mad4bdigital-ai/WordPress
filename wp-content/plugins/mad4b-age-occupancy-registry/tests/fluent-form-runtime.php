<?php
require '/var/www/html/wp-load.php';
$checks=[];
$assert=function($key,$ok)use(&$checks){$checks[$key]=(bool)$ok;};
foreach([5,6,7,8] as $id){
 $html=do_shortcode('[fluentform id="'.$id.'"]');
 $assert('form_'.$id.'_renders_age_contract',strpos($html,'mad4b-age-collector')!==false&&strpos($html,'name="mad4b_age_contract"')!==false);
 $cfg=MAD4B_Age_Fluent_Forms::config($id);
 $assert('form_'.$id.'_configured',$cfg!==null);
}
foreach([3,11] as $id){
 $html=do_shortcode('[fluentform id="'.$id.'"]');
 $assert('form_'.$id.'_unchanged',strpos($html,'mad4b-age-collector')===false);
}
$ctx=['no_of_children'=>'2','no_of_infants'=>'1'];
$raw=$ctx+['mad4b_age_contract'=>MAD4B_Age_Fluent_Forms::VERSION,'mad4b_child_ages'=>['7','11'],'mad4b_infant_ages'=>['1']];
$receipt=MAD4B_Age_Fluent_Forms::receipt(6,$ctx,$raw);
$assert('valid_booking_ages',is_array($receipt)&&$receipt['child_ages']===[7,11,1]&&$receipt['infant_ages']===[1]);
$errors=MAD4B_Age_Fluent_Forms::receipt(6,$ctx,['mad4b_age_contract'=>MAD4B_Age_Fluent_Forms::VERSION,'mad4b_child_ages'=>['7'],'mad4b_infant_ages'=>['1']]);
$assert('missing_child_age_rejected',is_wp_error($errors)&&$errors->get_error_code()==='age_count_mismatch');
$errors=MAD4B_Age_Fluent_Forms::receipt(6,$ctx,$raw+['untrusted_price'=>'1']);
$assert('receipt_excludes_price',is_array($errors)&&!isset($errors['untrusted_price']));
$errors=MAD4B_Age_Fluent_Forms::receipt(6,$ctx,[]);
$assert('missing_contract_rejected',is_wp_error($errors)&&$errors->get_error_code()==='age_form_contract_missing');
$legacy=MAD4B_Age_Fluent_Forms::receipt(5,['numeric_field_1'=>'1','numeric_field_2'=>'0'],[]);
$assert('inquiry_legacy_compatible',$legacy===null);
$persist=MAD4B_Age_Fluent_Forms::persist($ctx+['mad4b_age_contract'=>MAD4B_Age_Fluent_Forms::VERSION,'mad4b_child_ages'=>['7','11'],'mad4b_infant_ages'=>['1']],6,[]);
$assert('persists_age_receipt',isset($persist['mad4b_age_context']['child_ages'])&&$persist['mad4b_age_context']['child_ages']===[7,11,1]);
$_POST['data']=http_build_query($raw);
$hooked=apply_filters('fluentform/validation_errors',[],$ctx,(object)['id'=>6],[]);
$assert('fluentform_ajax_validated',empty($hooked));
$saved=apply_filters('fluentform/insert_response_data',$ctx,6,[]);
$assert('fluentform_ajax_persisted',isset($saved['mad4b_age_context']['stay_children'])&&$saved['mad4b_age_context']['stay_children']===3);
$_POST['data']=http_build_query(['no_of_children'=>'2','no_of_infants'=>'1','mad4b_age_contract'=>MAD4B_Age_Fluent_Forms::VERSION,'mad4b_child_ages'=>['7'],'mad4b_infant_ages'=>['1']]);
$rejected=apply_filters('fluentform/validation_errors',[],$ctx,(object)['id'=>6],[]);
$assert('fluentform_ajax_rejects_missing_age',!empty($rejected['mad4b_age_contract']));
unset($_POST['data']);
$assert('site_registry_mapping_used',count(MAD4B_Age_Fluent_Forms::forms())===4);
foreach($checks as $n=>$ok)echo $n.'='.($ok?'PASS':'FAIL').PHP_EOL;
echo 'SUMMARY='.count(array_filter($checks)).'/'.count($checks).PHP_EOL;
exit(in_array(false,$checks,true)?1:0);

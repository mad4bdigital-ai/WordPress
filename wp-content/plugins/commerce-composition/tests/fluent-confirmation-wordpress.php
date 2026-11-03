<?php
$_SERVER['HTTP_HOST']='localhost:8094';require '/var/www/html/wp-load.php';
use Commerce\Composition\FluentSubmissionAdapter;
$checks=0;$fail=[];
$check=static function($v,$name)use(&$checks,&$fail){$checks++;if(!$v)$fail[]=$name;};
$base=['message'=>'Your form was received','redirectUrl'=>'https://example.invalid/next'];
$form=(object)['id'=>6];
$check(apply_filters('fluentform/submission_confirmation',$base,$form,[],99100001,[])===$base,
 'default_disabled_confirmation_untouched');
$allow=static fn($prev,$formId,$id)=>$formId===6&&$id===99100001;
add_filter('commerce_composition_enable_submission_to_request_basket',$allow,100,3);
try{
 FluentSubmissionAdapter::onSubmission(99100001,['dropdownitenary'=>'36474'],$form);
 $changed=apply_filters('fluentform/submission_confirmation',$base,$form,[],99100001,[]);
 $check($changed['message']!==$base['message'],'enabled_failure_explains_not_staged');
 $check(str_contains($changed['message'],'could not be added'),'failed_placement_explicit');
 $check(str_contains($changed['message'],'No payment has been taken'),'no_payment_explicit');
 $check(!isset($changed['redirectUrl']),'failed_request_never_redirects_away_from_message');
 $different=apply_filters('fluentform/submission_confirmation',$base,$form,[],99100002,[]);
 $check($different===$base,'different_submission_id_not_falsely_marked');
 $inquiry=apply_filters('fluentform/submission_confirmation',$base,(object)['id'=>3],[],99100001,[]);
 $check($inquiry===$base,'inquiry_form_original_confirmation');
}finally{remove_filter('commerce_composition_enable_submission_to_request_basket',$allow,100);}
echo wp_json_encode(['result'=>$fail?'FAIL':'PASS','assertions'=>$checks,'failed'=>$fail,
 'full_vendor_listener_chain'=>false,'no_payment_authority'=>true]).PHP_EOL;
if($fail)exit(2);

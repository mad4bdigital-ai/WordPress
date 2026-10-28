<?php
/* Read-only WP CLI field-contract release gate. */
if(PHP_SAPI!=='cli')exit(70);
require dirname(__DIR__,4).'/wp-load.php';
if(wp_get_environment_type()!=='local'||!class_exists('Shared_Product_Field_Shape_Contract_v1'))exit(71);
$c='Shared_Product_Field_Shape_Contract_v1';
$stat=['elementor'=>0,'empty_legacy'=>0,'invalid_json'=>0,'bad_bindings'=>0,'repeater'=>0,'bad_repeater'=>0];$examples=[];
$bad=[['id'=>'f1','settings'=>['__dynamic__'=>['jedv_field'=>'[elementor-tag id="x" name="jet-post-custom-image" settings="%7B%7D"]']],'elements'=>[]]];
$good=[['id'=>'f2','settings'=>['__dynamic__'=>['background_image'=>'[elementor-tag id="x" name="jet-post-custom-image" settings="%7B%7D"]']],'elements'=>[]]];
$fixtures=count($c::inspect_elementor($bad))===1 && $c::inspect_elementor($good)===[] && $c::inspect_repeater_value([['time'=>'09:00']])===[] && count($c::inspect_repeater_value('[{"time":"09:00"}]'))===1;
$hooks=has_filter('elementor/document/save/data')!==false && has_filter('update_post_metadata')!==false;
global $wpdb;
foreach($wpdb->get_results("SELECT post_id,meta_value FROM {$wpdb->postmeta} WHERE meta_key='_elementor_data'",ARRAY_A) as$r){$stat['elementor']++;$raw=$r['meta_value'];if($raw===''){$stat['empty_legacy']++;continue;}$nodes=json_decode($raw,true);if(!is_array($nodes)){$stat['invalid_json']++;continue;}$errors=$c::inspect_elementor($nodes);$stat['bad_bindings']+=count($errors);if($errors&&count($examples)<8)$examples[]=['id'=>(int)$r['post_id'],'error'=>$errors[0]['code']];}
foreach(['transportations'=>['trans_train_stops','trans_faq_item'],'tours-and-activities'=>['faq_item']]as$type=>$keys)foreach($keys as$key){$rs=$wpdb->get_results($wpdb->prepare("SELECT pm.post_id,pm.meta_value FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID=pm.post_id WHERE p.post_type=%s AND pm.meta_key=%s",$type,$key),ARRAY_A);foreach($rs as$r){$stat['repeater']++;$issues=$c::inspect_repeater_value(maybe_unserialize($r['meta_value']));if($issues){$stat['bad_repeater']++;if(count($examples)<8)$examples[]=['id'=>(int)$r['post_id'],'key'=>$key,'error'=>$issues[0]['code']];}}}
$pass=$fixtures&&$hooks&&!$stat['invalid_json']&&!$stat['bad_bindings']&&!$stat['bad_repeater'];
echo wp_json_encode(['version'=>$c::VERSION,'fixtures'=>$fixtures,'save_hooks'=>$hooks,'stats'=>$stat,'examples'=>$examples,'pass'=>$pass],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";exit($pass?0:1);

<?php
/** Read-only WP-CLI: intercept editor-time writes and assert stable-key CAS intent. */
defined('ABSPATH')||exit;
$id=60623;
if(get_post_type($id)!=='tours-and-activities')WP_CLI::error('Regression Tour missing');
if(!class_exists('MAD4B_Tour_Highlights_V1',false) ||
   !class_exists('MAD4B_Tour_Highlights_Presentation_V1',false))
    WP_CLI::error('Original native Journey owner absent');
$cards=MAD4B_Tour_Highlights_Presentation_V1::cards(
    (string)get_post_meta($id,'tour_highlights_content',true));
$keys=MAD4B_Tour_Highlights_Presentation_V1::card_keys($cards);
if(count($keys)<3)WP_CLI::error('Insufficient stable keys');

$fixture=[];$attempts=[];
$get=static function($check,$postId,$key,$single)use(&$fixture,$id){
    if((int)$postId===$id && $key==='mad4b_journey_place_bindings_v1')
        return [$fixture]; // get_metadata_raw() unwraps index 0 for single=true.
    return $check;
};
$block=static function($check,$postId,$key,$value,$previous)use(&$attempts,$id){
    if((int)$postId===$id && $key==='mad4b_journey_place_bindings_v1') {
        $attempts[]=['new'=>$value,'previous'=>$previous];
        return true; // WordPress skips the UPDATE: audit only.
    }
    return $check;
};
add_filter('get_post_metadata',$get,999,4);
add_filter('update_post_metadata',$block,999,5);
$run=static function(array $rows)use(&$fixture,&$attempts,$id){
    $fixture=$rows;$attempts=[];
    // WordPress unwraps index 0 of the get_post_metadata short-circuit for
    // single=true. Verify the fixture shape before invoking the real owner:
    // otherwise a malformed test can silently prevent all save writes.
    if(get_post_meta($id,'mad4b_journey_place_bindings_v1',true)!==$rows)
        WP_CLI::error('WP get_post_meta fixture does not preserve the repeater rows');
    MAD4B_Tour_Highlights_V1::on_binding_save($id,get_post($id),true);
    foreach($attempts as $attempt){
        if(($attempt['previous']??null)!==$rows)
            WP_CLI::error('Meta compare-and-swap preimage not preserved');
    }
    return $attempts;
};
try {
    $original=[['item_number'=>2,'item_key'=>$keys[0],
        'link_policy'=>'explicit','destination_term_ids'=>[2517]]];
    if($run($original))WP_CLI::error('Existing stable key silently rebound');

    $explicit=$original;
    $explicit[0]['rebind_on_save']='yes';
    $writes=$run($explicit);
    if(count($writes)!==1 ||
       ($writes[0]['new'][0]['item_key']??null)!==$keys[1] ||
       ($writes[0]['new'][0]['rebind_on_save']??null)!==false ||
       ($writes[0]['new'][0]['link_policy']??null)!=='explicit')
        WP_CLI::error('Explicit one-shot rebind did not preserve editor policy');

    $legacy=[['item_number'=>3,'link_policy'=>'auto']];
    $writes=$run($legacy);
    if(count($writes)!==1 ||
       ($writes[0]['new'][0]['item_key']??null)!==$keys[2])
        WP_CLI::error('Legacy numeric binding not pinned to stable key');

    $invalid=$explicit;
    $invalid[0]['item_number']=999;
    $writes=$run($invalid);
    if(count($writes)!==1 ||
       ($writes[0]['new'][0]['item_key']??null)!==$keys[0] ||
       ($writes[0]['new'][0]['rebind_on_save']??null)!==false)
        WP_CLI::error('Invalid position incorrectly changed existing link');
} finally {
    remove_filter('get_post_metadata',$get,999);
    remove_filter('update_post_metadata',$block,999);
}
WP_CLI::success(wp_json_encode([
    'site'=>home_url(),'cases'=>4,'write_attempts_intercepted'=>true,
    'database_mutated'=>false
],JSON_UNESCAPED_SLASHES));

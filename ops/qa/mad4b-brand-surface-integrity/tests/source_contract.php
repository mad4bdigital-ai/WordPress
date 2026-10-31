<?php
define('ABSPATH','/');
function add_action(...$args){}
function wp_get_environment_type(){return 'staging';}
function home_url(){return 'https://tours.dev.mad4b.com';}
function wp_parse_url($url,$component){return parse_url($url,$component);}
function get_option($name,$default=null){
    if($name==='mad4b_scp_brand_context_profile_v1')return ['site_uuid'=>'test-wov','brand_name'=>'Wo Vacation'];
    if($name==='mad4b_brand_surface_policy_v1')return [
        'site_uuid'=>'test-wov','brand_name'=>'Wo Vacation',
        'origin'=>'https://tours.dev.mad4b.com','blocked_source_tokens'=>[
            'tourism.almallahgroup-mg.com','201000958064','facebook.com/Tourism.MG']];
    return $default;
}
function wp_json_encode($v,$opts=0){return json_encode($v,$opts);}
function get_posts($args){return [26212,43484];}
$fake_header=[
  '26212'=>'{"elements":[]}',
  '43484'=>'{"elements":[{"settings":{"icon_list":[{"text":"info@tourism.almallahgroup-mg.com"},{"text":"+201000958064"}]}}]}',
];
function get_post_meta($id,$key,$single=true){
    global $fake_header;
    return $key==='_elementor_data'?($fake_header[$id]??''):'';
}
function apply_filters($name,$value,...$args){
    if($name==='wpml_default_language')return 'en';
    if($name==='wpml_post_language_details')return ['language_code'=>(int)($args[0]??0)===43484?'ar':'en'];
    if($name==='wpml_object_id')return 26212;
    return $value;
}
function get_post($id){return (object)['ID'=>$id];}
function get_post_status($id){return 'publish';}
function get_the_title($id){return 'Elementor Header';}
function get_current_blog_id(){return 1;}
require dirname(__DIR__,4).'/wp-content/mu-plugins/302-mad4b-brand-surface-integrity.php';
$assert=function($pass,$reason){if(!$pass){fwrite(STDERR,'FAIL '.$reason."\n");exit(1);}echo 'PASS '.$reason."\n";};
$assert(count(MAD4B_Brand_Surface_Integrity_V1::matches('info@tourism.almallahgroup-mg.com'))===1,'foreign contact matches bound policy');
$assert(!MAD4B_Brand_Surface_Integrity_V1::matches('hello@wovacation.com'),'owner site text not suppressed');
$bad=new class{function get_settings_for_display(){return ['icon_list'=>[['text'=>'info@tourism.almallahgroup-mg.com']]];}};
$good=new class{function get_settings_for_display(){return ['icon_list'=>[['text'=>'info@wovacation.com']]];}};
$assert(MAD4B_Brand_Surface_Integrity_V1::render_guard(true,$bad)===false,'render guard hides contaminated widget');
$assert(MAD4B_Brand_Surface_Integrity_V1::render_guard(true,$good)===true,'render guard allows clean widget');
$scan=MAD4B_Brand_Surface_Integrity_V1::scan();
$assert($scan['complete']&&$scan['detected']===1,'published Arabic header contamination creates issue');
$assert(($scan['issues'][0]['code']??'')==='loc_brand_ar'&&($scan['issues'][0]['object_id']??0)===26212,'issue linked to English source');
$fake_header[43484]='{"elements":[]}';
$assert(MAD4B_Brand_Surface_Integrity_V1::scan()['detected']===0,'issue auto-resolves after source repaired');

<?php
declare(strict_types=1);
define('ABSPATH','/');
$GLOBALS['o']=[];
$GLOBALS['m']=[];
$GLOBALS['lock']=[];
$GLOBALS['authorized']=true;
function add_action(...$x){}
function get_option($k,$d=false){return array_key_exists($k,$GLOBALS['o'])?$GLOBALS['o'][$k]:$d;}
function update_option($k,$v,$autoload=false){$changed=!array_key_exists($k,$GLOBALS['o'])||$GLOBALS['o'][$k]!==$v;$GLOBALS['o'][$k]=$v;return $changed;}
function home_url($path='/'){return 'https://example.test'.$path;}
function wp_parse_url($u,$p){return parse_url($u,$p);}
function wp_get_environment_type(){return 'staging';}
function wp_json_encode($v,$flags=0){return json_encode($v,$flags);}
function get_post_meta($id,$key,$single=true){return $key==='_elementor_data'?($GLOBALS['m'][$id]??''):[];}
function get_post_status($id){return 'publish';}
function get_post_type($id){return 'elementor_library';}
function current_user_can($c,...$args){return $GLOBALS['authorized'];}
function absint($n){return abs((int)$n);}
function wp_cache_add($key,$val,$group='',$expire=0){
 $key=$group.'|'.$key;if(isset($GLOBALS['lock'][$key]))return false;
 $GLOBALS['lock'][$key]=$val;return true;
}
function wp_cache_delete($key,$group=''){unset($GLOBALS['lock'][$group.'|'.$key]);return true;}
function apply_filters($x,$v){return $x==='wpml_current_language'?'en':$v;}
function get_locale(){return 'en_US';}
function get_woocommerce_currency(){return 'USD';}
function wp_is_mobile(){return false;}
function ok($cond,$label){if(!$cond)throw new RuntimeException('FAIL '.$label);echo 'PASS '.$label.PHP_EOL;}
require $argv[1];require $argv[2];
$make=function(string $heading='About Egypt',bool $unsafe=false):string {
 $public=['id'=>'abcdef0','elType'=>'container','settings'=>[],'elements'=>[
   ['id'=>'abcd111','elType'=>'widget','widgetType'=>'heading','settings'=>['title'=>$heading]],
   ['id'=>'abcd222','elType'=>'widget','widgetType'=>'heading','settings'=>['title'=>'Attractions']]
 ]];
 $native=['id'=>'a123456','elType'=>'container','elements'=>[
  ['id'=>'a234567','elType'=>'widget','widgetType'=>$unsafe?'fluent-form-widget':'button'],
  ['id'=>'6190bab','elType'=>'widget','widgetType'=>'template',
   'settings'=>['template_id'=>'48366']]
 ]];
 return wp_json_encode([$public,$native],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
};
$old=$make();
$GLOBALS['m'][24784]=$old;
$GLOBALS['m'][48366]='{"elementor":"public-terms-template"}';
$GLOBALS['o']['mad4b_compiled_tour_family_v1']=[
 'contract'=>'mad4b.template-family-public-snapshot.v1','family_document'=>24784,
 'family_sha256'=>hash('sha256',$old),'widget_id'=>'6190bab',
 'content_document'=>48366,'content_sha256'=>hash('sha256',$GLOBALS['m'][48366])
];
$GLOBALS['o']['mad4b_auto_public_family_v1']=[
 'contract'=>'mad4b.elementor-auto-public-family.v1','site_host'=>'example.test',
 'environment'=>'staging','status'=>'approved',
 'families'=>[['id'=>24784,'sha256'=>hash('sha256',$old)]]
];
$GLOBALS['o']['mad4b_auto_family_recert_mode_v1']='off';
$GLOBALS['o']['mad4b_auto_public_family_mode_v1']='on';
$c='MAD4B_Auto_Family_Revision_V1';
$a='MAD4B_Auto_Public_Family_V1';
$initial=$c::enroll();
ok($initial['status']==='enrolled' && $initial['roots']===1,'Baseline enrollment certifies original public root');
ok($c::enroll()['status']==='already_enrolled','Repeated enrollment cannot reset trusted baselines');
ok($c::status()['pending_count']===0,'No pending roots initially');
$GLOBALS['o']['mad4b_auto_family_recert_mode_v1']='on';
$GLOBALS['authorized']=false;
ok($c::reconcile(24784)['status']==='unauthorized_or_busy','Unprivileged writes cannot certify');
$GLOBALS['authorized']=true;
$GLOBALS['m'][24784]=$make('Revised tour description');
$updated=$c::reconcile(24784);
ok($updated['status']==='recertified' && $updated['pending_native_witness']===1,'Content-changing save recertifies family but requires native witnesses');
ok($GLOBALS['o']['mad4b_auto_public_family_v1']['families'][0]['sha256']===hash('sha256',$GLOBALS['m'][24784]),'New family hash persisted');
ok($c::status()['pending_count']===1,'Changed root cannot cache before witness');
ok($GLOBALS['o']['mad4b_compiled_tour_family_v1']['family_sha256']===hash('sha256',$GLOBALS['m'][24784]),
 'Related Destinations parent SHA renews when original child reference and child SHA are unchanged');
$newSha=hash('sha256',$GLOBALS['m'][24784]);
$pending=$GLOBALS['o']['mad4b_auto_family_pending_v1']['roots']['24784|abcdef0'];
ok($pending['family_sha']===$newSha && $pending['stage']==='native_witness','Bound pending root to new exact SHA');
ok($a::root_for(24784,'abcdef0')===null,'New root blocked from snapshot route');
$html='<div class="elementor-widget-container">'.str_repeat('Egypt excursions and public tour text. ',10).'</div>';
$unsafe='<div class="elementor-widget-container">'.str_repeat('Egypt public content. ',12).' Price 150 USD</div>';
ok($c::witness($pending,$unsafe,[],[])['status']==='native_unsafe','Selling price cannot be used as a native witness');
ok($c::status()['pending_count']===1,'Unsafe candidate still pending');
$r=$c::witness($pending,$html,[],[]);
ok($r['status']==='first_native_witness','First anonymous native rendering recorded');
ok($c::status()['pending_count']===1,'First rendering is not sufficient');
$repeat=$c::witness($pending,$html,[],[]);
ok($repeat['status']==='same_request_not_a_second_witness','Same request cannot count twice');
$ref=new ReflectionProperty('MAD4B_Auto_Family_Revision_V1','witnessed_this_request');
$ref->setValue([]);
$r=$c::witness($pending,$html,[],[]);
ok($r['status']==='approved_after_two_native_witnesses','Second byte-and-assets-identical native result promotes root');
ok($c::status()['pending_count']===0,'Promotion removes exact pending root');
ok(is_array($a::root_for(24784,'abcdef0')),'Approved root can now snapshot');
$reverted=$c::reconcile(24784);
ok($reverted['status']==='unchanged','No spurious recert when identical revision seen twice');
$root0=$GLOBALS['o']['mad4b_auto_public_family_v1']['families'][0]['sha256'];
$nodesNow=json_decode($GLOBALS['m'][24784],true);
$GLOBALS['o']['mad4b_mixed_family_public_islands_v1']=[
 'contract'=>'mad4b.mixed-elementor-family-public-island.v1',
 'status'=>'approved','roots'=>[[
  'family'=>24784,'id'=>'abcdef0','family_sha256'=>$root0,
  'root_sha256'=>hash('sha256',wp_json_encode($nodesNow[0],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)),
  'type'=>'public_tour_inclusion','listings'=>[]
 ]]
];
$GLOBALS['m'][24784]=$make('Revised tour description',true);
$unsafeSibling=$c::reconcile(24784);
ok($unsafeSibling['status']==='recertified' && $unsafeSibling['pending_native_witness']===0,'Unsafe sibling stays native without invalidating unchanged public root');
ok($c::status()['pending_count']===0,'Unrelated unsafe sibling does not queue public widget');
ok($GLOBALS['o']['mad4b_mixed_family_public_islands_v1']['roots'][0]['family_sha256']===hash('sha256',$GLOBALS['m'][24784]),
 'Existing manually certified root stays active after an unrelated Elementor edit');
ok($GLOBALS['o']['mad4b_compiled_tour_family_v1']['family_sha256']===hash('sha256',$GLOBALS['m'][24784]),
 'Existing Related Destinations snapshot keeps parent certificate after unrelated edit');
ok($GLOBALS['o']['mad4b_auto_public_family_v1']['families'][0]['sha256']!==$root0,'Unrelated edit updates family hash independently');
echo 'ALL_AUTOMATIC_FAMILY_RECERT_TESTS_PASS'.PHP_EOL;

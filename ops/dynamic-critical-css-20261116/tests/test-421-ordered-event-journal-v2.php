<?php
/* Native 421/422 CPU-only fixture: no WP site writes, no browser. */
define('ABSPATH','/fixture/');
$GLOBALS['mock']=[
 'options'=>['home'=>'https://tours.dev.mad4b.com','mad4b_ccss_public_dirty_v1'=>[
  'generation'=>1400,'ids'=>[],'types'=>[],'global_generation'=>680],
  'mad4b_ccss_listing_generation_v1'=>1400,'mad4b_ccss_global_generation_v1'=>680],
 'meta'=>['tour_mode'=>'tour','cruise_runs'=>'','tour_related_rate_plans'=>'',
   'tour_queried_rates'=>'','related_program_terms'=>'','tour_cruise_amenities'=>''],
 'db'=>0,'post_status'=>'publish','titles'=>[35952=>'King of Egypt',35906=>'Nile Queen']
];
class OriginalDb{
 public $prefix='wp_';
 public function prepare($sql,...$args){return sprintf($sql,...$args);}
 public function get_var($sql){return $GLOBALS['mock']['db'];}
}
$GLOBALS['wpdb']=new OriginalDb();
function add_action(...$args){}
function get_option($key,$fallback=null){return $GLOBALS['mock']['options'][$key]??$fallback;}
function update_option($key,$value,$autoload=false){$GLOBALS['mock']['options'][$key]=$value;return true;}
function get_post_meta($id,$key='',$single=false){
 $vals=$GLOBALS['mock']['meta'];
 if($key==='_mad4b_ccss_route_generation_v1')return $GLOBALS['mock']['route'][$id]??0;
 return $key!==''?($vals[$key]??''):array_map(fn($v)=>[$v],$vals);
}
function update_post_meta($id,$key,$value){$GLOBALS['mock']['route'][$id]=$value;}
function get_post_status($id){return $GLOBALS['mock']['post_status'];}
function get_the_title($id){return $GLOBALS['mock']['titles'][$id]??'';}
function wp_parse_url($url,$part=PHP_URL_HOST){return parse_url($url,$part);}
require '/tmp/mad4b-421-ordered-event-candidate-20261123.php';
require '/tmp/mad4b-422-ordered-event-candidate-20261123.php';
$mark=new ReflectionMethod('MAD4B_Public_Critical_CSS_Coverage_V1','mark');
$prove=new ReflectionMethod('MAD4B_Public_Critical_CSS_Consumer_V2','unrelated_property_events');
$approved=new ReflectionMethod('MAD4B_Public_Critical_CSS_Consumer_V2','approved_consumer_revision');
$ctx=['type'=>'tours-and-activities','object_id'=>60473];
$html='<body class="single-tours-and-activities"><main class="elementor-69052">Day tour by car</main></body>';
$tests=0;
function check($name,$yes){global $tests;if(!$yes)throw new Exception($name);$tests++;echo "PASS $name
";}
function proof($old,$now,$html2=null,$context=null){
 global $prove,$ctx,$html;
 return $prove->invoke(null,$context??$ctx,$old,$now,$html2??$html);
}
$mark->invoke(null,'post:properties',35952);
$mark->invoke(null,'post:properties',35952);
$mark->invoke(null,'post:properties',35906);
$j=get_option('mad4b_ccss_public_event_journal_v1');
$dirty=get_option('mad4b_ccss_public_dirty_v1');
check('all 3 true property writes recorded in monotonic journal',count($j['events'])===3
  &&array_column($j['events'],'generation')===[1401,1402,1403]);
check('original last-key map still coalesces duplicate property generations',count($dirty['ids'])===2
  &&$dirty['ids']['post:properties:35952']['generation']===1402);
check('old original changed-property source history restored for pure day tour',proof(1400,1403)===true);
check('journal cannot silently omit or rewrite original 421 event scopes',
 $j['events'][0]['scope']==='post:properties' &&$j['events'][0]['id']===35952);
check('original post ID revisions remain exact',($GLOBALS['mock']['route'][35952]??0)===1402);
$baseline=$GLOBALS['mock'];
$GLOBALS['mock']['options']['mad4b_ccss_public_event_journal_v1']['events'][1]['generation']=1404;
check('missing or out of order native generation fails closed',proof(1400,1403)===false);
$GLOBALS['mock']=$baseline;
$GLOBALS['mock']['options']['mad4b_ccss_public_event_journal_v1']['events'][0]['scope']='post:tours-and-activities';
check('foreign tour content event fails closed',proof(1400,1403)===false);
$GLOBALS['mock']=$baseline;
$GLOBALS['mock']['options']['mad4b_ccss_public_event_journal_v1']['events'][0]['id']=0;
check('invalid property ID fails closed',proof(1400,1403)===false);
$GLOBALS['mock']=$baseline;
unset($GLOBALS['mock']['options']['mad4b_ccss_public_event_journal_v1']);
check('missing journal is never invented',proof(1400,1403)===false);
$GLOBALS['mock']=$baseline;
check('related JetEngine Property reference remains forbidden',proof(1400,1403,
  $html.'<div data-id="35952"></div>')===false);
check('dynamic listing grid invalidates without knowing every queried Property',
  proof(1400,1403,$html.'<div class="jet-listing-grid"></div>')===false);
$GLOBALS['mock']['db']=1;
check('known JetEngine relation rejects auto proof',proof(1400,1403)===false);
$GLOBALS['mock']=$baseline;
$GLOBALS['mock']['meta']['tour_mode']='package';
check('packages and cruises do not inherit day-tour proof',proof(1400,1403)===false);
$GLOBALS['mock']=$baseline;
$GLOBALS['mock']['meta']['cruise_runs']=[1];
check('cruise program linked to tour rejects source bypass',proof(1400,1403)===false);
$GLOBALS['mock']=$baseline;
$GLOBALS['mock']['meta']['hotel_related']=['nested'=>['property'=>35952]];
check('unparsed nested JetEngine/ACF accommodation references must fail closed',
 proof(1400,1403)===false);
$GLOBALS['mock']=$baseline;
check('foreign context not permitted',proof(1400,1403,null,
 ['type'=>'properties','object_id'=>35952])===false);
$GLOBALS['mock']=$baseline;
$mark->invoke(null,'pricing',12);
check('mixed price events make journal proof false',proof(1400,1404)===false);
$GLOBALS['mock']=$baseline;
check('prior known good journal remains correct',proof(1400,1403)===true);
check('unknown companion sha rejected',
 $approved->invoke(null,str_repeat('a',64))===false);
check('old exact Git consumer revision accepted during source upgrade only',
 $approved->invoke(null,'249083d5aa0124ea1280a88f5062945cdff2885eca63b36ba5c66682b0faf3d1')===true);
check('installed new exact Git consumer revision also accepted',
 $approved->invoke(null,hash_file('sha256','/tmp/mad4b-422-ordered-event-candidate-20261123.php'))===true);
echo "PASS $tests/$tests bounded ordered original source events + strict 422 day tour fixtures
";

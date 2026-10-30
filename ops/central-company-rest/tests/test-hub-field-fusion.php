<?php
define('ABSPATH','/test/');
$GLOBALS['posts']=[];
$GLOBALS['metas']=[];
$GLOBALS['settings']=[];
class WP_Error { public $code; function __construct($c,$m){$this->code=$c;} }
function add_action(){}
function current_user_can($c){return true;}
function wp_get_environment_type(){return 'staging';}
function home_url($path='/'){return 'https://tours.dev.mad4b.com/';}
function wp_parse_url($url,$part){return parse_url($url,$part);}
function get_option($k,$d=[]){return $GLOBALS['settings'][$k]??$d;}
function update_option($k,$v,$auto=false){$GLOBALS['settings'][$k]=$v;return true;}
function get_post($id){return $GLOBALS['posts'][$id]??null;}
function get_post_meta($id,$key,$single=true){return $GLOBALS['metas'][$id][$key]??'';}
function wp_strip_all_tags($s){return strip_tags($s);}
function wp_json_encode($x,$flags=0){return json_encode($x,$flags);}
function is_wp_error($x){return $x instanceof WP_Error;}
function check($pass,$label){if(!$pass)throw new Exception('Failed: '.$label);}
require dirname(__DIR__).'/central/273-mad4b-hub-field-fusion.php';
foreach([11,12] as $id){
 $GLOBALS['posts'][$id]=(object)['post_title'=>'Example Hotel','post_content'=>$id===11?'':'A spacious hotel with a swimming pool and friendly staff.','post_excerpt'=>''];
 $GLOBALS['metas'][$id]=[
  '_mad4b_hub_import_owner'=>'tourism-almallahgroup','_mad4b_hub_import_type'=>'properties',
  '_mad4b_hub_import_language'=>'en','_mad4b_hub_import_source_id'=>$id
 ];
}
$r=MAD4B_Hub_Field_Fusion_V1::compare(11,12);
check(is_array($r),'proposal');
check($r['field_proposals']['description']['proposed_value_from_hub_id']===12,'richer text');
check($r['auto_merge']===false,'review only');
check(is_array(MAD4B_Hub_Field_Fusion_V1::set_policy('tourism-almallahgroup','properties','description','manual',95)),'field policy');
$r=MAD4B_Hub_Field_Fusion_V1::compare(11,12);
check($r['field_proposals']['description']['decision']==='review_conflict_or_gap','custom policy applied');
check(is_wp_error(MAD4B_Hub_Field_Fusion_V1::set_policy('tourism-almallahgroup','properties','source_wpml_trid','richest',95)),'WPML protected');
$unverified=MAD4B_Hub_Field_Fusion_V1::compare(11,12);
check($unverified['entity_resolution_state']==='identity_unverified_hold' &&
    $unverified['canonical_draft']['status']==='blocked_unverified_identity',
    'Hotels with missing place IDs are explicitly held, not review-ready');
$GLOBALS['settings'][MAD4B_Hub_Field_Fusion_V1::OPTION]['tourism-almallahgroup']['properties']['property_place_id']
    =['strategy'=>'richest','weight'=>0];
$policy=MAD4B_Hub_Field_Fusion_V1::policies('tourism-almallahgroup','properties');
check($policy['property_place_id']['strategy']==='exact',
    'Unsafe stored option cannot downgrade exact identity at read time');
$GLOBALS['metas'][11]['_mad4b_hub_import_identity_facts']=['property_place_id'=>'ABC'];
$GLOBALS['metas'][12]['_mad4b_hub_import_identity_facts']=['property_place_id'=>'DEF'];
$hold=MAD4B_Hub_Field_Fusion_V1::compare(11,12);
check($hold['entity_resolution_state']==='identity_conflict_hold','Conflicting property identity blocks canonical draft');
check($hold['canonical_draft']['can_apply']===false,'Preview is never published or applied');
check(is_wp_error(MAD4B_Hub_Field_Fusion_V1::set_policy('tourism-almallahgroup','properties','property_place_id','richest',80)),
    'Critical exact identity cannot be demoted by company policy');
$GLOBALS['metas'][12]['_mad4b_hub_import_identity_facts']=['property_place_id'=>'ABC'];
MAD4B_Hub_Field_Fusion_V1::set_policy('tourism-almallahgroup','properties','description','richest',95);
$draft=MAD4B_Hub_Field_Fusion_V1::compare(11,12);
check($draft['canonical_draft']['status']==='review_required','Verified identity still requires human review');
check($draft['canonical_draft']['field_source_selection']['description']['value_from_hub_id']===12,
    'Draft selects description and retains source provenance');
$GLOBALS['posts'][13]=(object)['post_title'=>'Example Hotel','post_content'=>'Excellent description',
    'post_excerpt'=>''];
$GLOBALS['metas'][13]=array_merge($GLOBALS['metas'][12],[
    '_mad4b_hub_import_owner'=>'another-company','_mad4b_hub_import_source_id'=>333]);
check(is_wp_error(MAD4B_Hub_Field_Fusion_V1::compare(11,13)),'Cross-company fusion is blocked');
echo "PASS 12 field fusion tests\n";

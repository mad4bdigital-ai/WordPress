<?php
define('ABSPATH','/');
class WP_Error {function __construct(public $code){}}
function is_wp_error($x){return $x instanceof WP_Error;}
function add_action(...$args){}
function get_post_type_object($slug){return in_array($slug,['properties','new-service','shop_order'])?(object)['show_ui'=>true,'labels'=>(object)['singular_name'=>$slug]]:null;}
function get_taxonomy($slug){return $slug==='guide-languages'?(object)['show_ui'=>true]:null;}
function get_option($key,$default){return $GLOBALS['registry']??$default;}
function current_user_can($cap,...$args){return ($GLOBALS['allow']??true)&&($args[0]??0)!==99;}
function get_post_type($id){return [11=>'properties',12=>'new-service',13=>'new-service',99=>'new-service'][$id]??null;}
function get_post_meta($id,$key,$single){return $key==='linked_services'?[12,12,13,99,'bad']:($id===13?'invalid':'12.50');}
function get_term_meta($id,$key,$single){return '2500';}
function is_object_in_taxonomy($type,$slug){return $type==='properties';}
function wp_get_object_terms($id,$slug,$args){return [21,21,99];}
function get_term($id,$slug){return (object)['name'=>'English'];}
function get_edit_term_link($id,$slug){return '/edit-term/'.$id;}
function get_edit_post_link($id,$context){return '/edit/'.$id;}
function get_the_title($id){return 'Service '.$id;}
function wp_strip_all_tags($s){return strip_tags($s);}
require __DIR__.'/../wp-content/mu-plugins/mad4b-cost-source-registry-v1.php';
$c='MAD4B_Cost_Source_Registry_V1';$n=0;
function ck($ok,$why){global $n;if(!$ok)throw new RuntimeException($why);$n++;}
$base=['kind'=>'post_type','slug'=>'new-service','amount_field'=>'cost','relation_field'=>'linked_services','currency'=>'USD','basis'=>'per_item'];
$tax=['kind'=>'taxonomy','slug'=>'guide-languages','amount_field'=>'guide_cost','relation_field'=>'','currency'=>'EGP','basis'=>'per_guide_day'];
ck(!is_wp_error($c::validate($base)),'new post type accepted');
ck(!is_wp_error($c::validate($tax)),'taxonomy accepted');
ck(is_wp_error($c::validate(array_replace($base,['slug'=>'shop_order']))),'order excluded');
ck(is_wp_error($c::validate(array_replace($tax,['relation_field'=>'id']))),'taxonomy uses native assignment');
ck(is_wp_error($c::validate(array_replace($base,['amount_field'=>'SELECT *']))),'no SQL');
ck(is_wp_error($c::validate(array_replace($base,['currency'=>'FAKE']))),'currency validation');
ck(is_wp_error($c::validate(array_replace($base,['basis'=>'retail']))),'basis validation');
ck($c::validate($base+['role'=>'approved'])['role']==='unverified_cost_reference','cannot grant commercial role');
foreach(['-1','0','NaN','1e9','secret',[],true] as $bad)ck($c::amount($bad)===null,'reject invalid amount');
$GLOBALS['registry']=[$base,$tax];
ck(isset($c::types(['properties'=>'Property'])['new-service']),'extend without replacing native type');
$r=$c::rows(11,'properties');
ck(count($r)===3,'dedup and skip unauthorized objects');
ck($r[0]['value']['entity_id']===12&&$r[0]['value']['amount']==='12.50','explicit linked source');
ck($r[1]['status']==='missing','invalid linked value missing');
ck($r[2]['value']['entity_kind']==='taxonomy'&&$r[2]['value']['amount']==='2500','assigned term cost');
ck($r[2]['value']['included_in_commercial_total']===false,'term never commercial by mapping');
ck($c::rows(12,'new-service')[0]['value']['entity_id']===12,'own cost source');
$GLOBALS['allow']=false;ck($c::rows(11,'properties')===[],'permission denied');
echo "PASS $n/$n dynamic cost source mappings\n";

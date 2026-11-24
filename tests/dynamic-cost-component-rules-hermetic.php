<?php
define('ABSPATH','/');
class WP_Error{public function __construct(public $code){}public function get_error_code(){return $this->code;}}
function is_wp_error($x){return $x instanceof WP_Error;}
function add_action(...$a){}
function get_post_type_object($s){return in_array($s,['properties','units','property-rates','shop_order'],true)?(object)['show_ui'=>true,'labels'=>(object)['singular_name'=>$s]]:null;}
function get_taxonomy($s){return null;}
function get_option($k,$d){return $GLOBALS['map']??$d;}
function current_user_can($cap,...$args){return ($GLOBALS['allowed']??true)&&($args[0]??0)!==99;}
function get_post_type($id){return [11=>'properties',12=>'units',13=>'units',99=>'units',14=>'property-rates'][$id]??null;}
function get_post_meta($id,$key,$single){return $key==='cost_rows'?[['key'=>'a','cost'=>'20','currency'=>'USD'],['key'=>'b','cost'=>'10','currency'=>'USD']]:($key==='cost'?'30':'');}
function get_the_title($id){return 'Object '.$id;}
function get_edit_post_link($id,$context){return '/edit/'.$id;}
function wp_strip_all_tags($s){return strip_tags($s);}
class Relation {
 function __construct(public $parent,public $child,public $ids){}
 function get_args(){return ['parent_object'=>'posts::'.$this->parent,'child_object'=>'posts::'.$this->child];}
 function get_children($id,$mode){return $this->ids;}
 function get_parents($id,$mode){return [11];}
}
class Manager{function get_active_relations(){return [223=>new Relation('properties','units',[12,12,13]),169=>new Relation('shop_order','units',[12]),359=>new Relation('properties','property-rates',[])];}}
function jet_engine(){return (object)['relations'=>new Manager()];}
require __DIR__.'/../wp-content/mu-plugins/mad4b-cost-source-registry-v1.php';
require __DIR__.'/../wp-content/mu-plugins/mad4b-cost-component-rules-v1.php';
$c='MAD4B_Cost_Source_Registry_V1';$r='MAD4B_Cost_Component_Rules_V1';$n=0;
function ck($b,$m){global $n;if(!$b)throw new RuntimeException($m);$n++;}
$m=['kind'=>'post_type','slug'=>'units','amount_field'=>'cost','relation_field'=>'','currency'=>'USD','basis'=>'per_room_night','relation_id'=>223,'relation_direction'=>'children','repeater_field'=>'cost_rows','row_key_field'=>'key','currency_field'=>'currency','component_key'=>'hotel','component_role'=>'required','selection_mode'=>'cheapest_eligible','weight'=>100,'priority'=>10];
$v=$c::validate($m);
ck(!is_wp_error($v)&&$v['relation_id']===223,'native relation mapping');
ck(is_wp_error($c::validate(array_replace($m,['relation_direction'=>'parents']))),'wrong endpoint denied');
ck(is_wp_error($c::validate(array_replace($m,['relation_id'=>169]))),'protected order denied');
ck(is_wp_error($c::validate(array_replace($m,['relation_field'=>'other']))),'ambiguous relation rejected');
ck(is_wp_error($c::validate(array_replace($m,['weight'=>0]))),'weight invalid');
ck(is_wp_error($c::validate(array_replace($m,['selection_mode'=>'auto_publish']))),'unknown policy');
$old=$c::validate(array_diff_key($m,array_flip(['relation_id','relation_direction','repeater_field','row_key_field','component_key','component_role','selection_mode','weight','priority'])));
ck(!is_wp_error($old)&&$old['selection_mode']==='manual','old mapping safe default');
ck($c::mappingKey($v)!==$c::mappingKey(array_replace($v,['repeater_field'=>'another'])),'multiple repeater mappings preserved');
$GLOBALS['map']=[$m];$rows=$c::rows(11,'properties');
ck(count($rows)===4,'two source posts each two rows and dedup');
ck($rows[0]['value']['row_key']==='a'&&$rows[1]['value']['row_key']==='b','repeat row identity preserved');
ck($rows[1]['value']['amount']==='10','repeat field amount');
ck($c::rows(12,'units')===[],'wrong direction not read');
$p=$r::preview($rows)[0];
ck($p['reference_order_first']['amount']==='10'&&count($p['reference_fallback_order'])===4,'lowest comparable and fallback order');
ck(!$p['eligible_selection_confirmed']&&!$p['fallback_authorized']&&$p['commercial_total']===null,'no admission');
$rows[1]['value']['currency']='EGP';ck($r::preview($rows)[0]['status']==='non_comparable_currency_or_basis','mixed currency not ranked');
$rows[1]['value']['currency']='USD';$rows[1]['status']='missing';ck($r::preview($rows)[0]['status']==='incomplete_candidate_set','missing fails closed');
$rows[1]['status']='reverify';foreach($rows as &$row)$row['value']['mapping']['selection_mode']='priority';unset($row);
$rows[0]['value']['mapping']['priority']=1;ck($r::preview($rows)[0]['reference_order_first']['amount']==='20','explicit priority');
$rows[1]['value']['mapping']['priority']=1;$rows[1]['value']['mapping']['weight']=200;ck($r::preview($rows)[0]['reference_order_first']['weight']===200,'tie weight');
$rows[1]['value']['mapping']['component_role']='optional';ck($r::preview($rows)[0]['status']==='conflicting_component_policies','conflicting roles not ranked');
$dup=$r::values([['key'=>'a','cost'=>'10'],['key'=>'a','cost'=>'11']],$v,fn()=>null);
ck($dup[0]['issue']==='repeater_row_key_invalid_or_duplicate','duplicate stable key');
$GLOBALS['map']=[array_replace($m,['slug'=>'property-rates','relation_id'=>359,'repeater_field'=>'','row_key_field'=>''])];
$empty=$c::rows(11,'properties');ck(count($empty)===1&&$empty[0]['status']==='missing','empty required source visible');
$GLOBALS['allowed']=false;ck($c::rows(11,'properties')===[],'unauthorized');
echo "PASS $n/$n relation, repeater and component rules\n";

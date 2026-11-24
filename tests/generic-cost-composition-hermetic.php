<?php
define('ABSPATH','/');
function is_wp_error($v){return false;}
function wp_json_encode($v){return json_encode($v);}
class MAD4B_Company_Global_Profit_V1 {
 static function preview($cost,$cur){return ['indicative_selling_price_minor'=>intdiv($cost*100+74,75),'total_cost_minor'=>$cost,'currency'=>$cur,'cost_certified'=>false];}
}
require __DIR__.'/../wp-content/mu-plugins/mad4b-generic-cost-composition-v1.php';
$c='MAD4B_Generic_Cost_Composition_V1';$n=0;
function ck($v,$m){global $n;if(!$v)throw new RuntimeException($m);$n++;}
$ctx=$c::context(['currency'=>'USD','adults'=>2,'children'=>0,'infants'=>0,'rooms'=>1,'nights'=>3,'days'=>4,'guides'=>1,'vehicles'=>2,'cabins'=>1,'guide_language_id'=>143]);
$fx=['base'=>'USD','fetched_at'=>gmdate('c'),'rates'=>['EGP'=>50,'EUR'=>0.9]];
foreach(['per_person'=>2,'per_person_night'=>6,'per_room_night'=>3,'per_cabin_night'=>3,'per_guide_day'=>4,'per_vehicle'=>2,'per_trip'=>1,'per_day'=>4,'per_cabin_trip'=>1] as $basis=>$q)ck($c::quantity([],$basis,$ctx)===$q,$basis);
ck($c::quantity([],'per_person',array_replace($ctx,['children'=>1]))===null,'mixed children fail closed');
ck($c::quantity(['quantity_source'=>'travelers'],'per_person_night',$ctx)===null,'incorrect unit override denied');
ck($c::quantity(['quantity_source'=>'fixed','fixed_quantity'=>4],'per_item',$ctx)===4,'explicit items');
ck($c::quantity([],'per_item',$ctx)===null,'unknown item quantity');
ck($c::line('x','0.1234','USD',3,'USD',$fx)['target_total_minor']===38,'exact quantity then ceil cents');
ck($c::line('x','7500','EGP',1,'USD',$fx)['target_total_minor']===15000,'FX whole line');
ck($c::line('x','5','EGP',1,'USD',null)['target_total_minor']===null,'missing FX');
ck($c::line('x','5','EGP',1,'USD',array_replace($fx,['fetched_at'=>'2020-01-01']))['target_total_minor']===null,'stale FX');
ck($c::line('x','-5','USD',1,'USD',$fx)['target_total_minor']===null,'negative cost');
function row($group,$id,$amount,$basis='per_person_night',$currency='USD',$mode='cheapest_eligible',$role='required',$inclusion='included'){
 return ['source'=>'registry:test','status'=>'reverify','value'=>['entity_kind'=>'post_type','entity_type'=>'properties','entity_id'=>$id,'amount'=>$amount,'currency'=>$currency,'basis'=>$basis,'mapping'=>['component_key'=>$group,'component_role'=>$role,'selection_mode'=>$mode,'inclusion'=>$inclusion,'quantity_source'=>'basis','fixed_quantity'=>1,'amount_field'=>'cost','priority'=>100,'weight'=>100]]];
}
$rows=[row('hotel',1,'100'),row('hotel',2,'90'),row('transport',3,'50','per_vehicle','USD','all_required'),row('extra',4,'10','per_person','USD','all_required','optional','extra')];
$r=$c::generic($rows,$ctx,$fx);
ck($r['converted_reference_subtotal_minor']===64000,'cheapest extended cost plus all required transport');
ck(count($r['lines'])===2&&count($r['optional_extra_conditional'])===1,'optional excluded');
ck($r['profit_preview']['indicative_selling_price_minor']===85334,'margin once');
ck($r['commercial_total_minor']===null,'no sale authority');
$duplicate=$rows;$duplicate[]=row('duplicate',2,'90');ck(in_array('duplicate:source_already_counted',$c::generic($duplicate,$ctx,$fx)['blockers']??[],true)||in_array('hotel:source_already_counted',$c::generic($duplicate,$ctx,$fx)['blockers']??[],true),'same source not double counted');
$bad=$rows;$bad[1]['status']='missing';ck($c::generic($bad,$ctx,$fx)['profit_preview']===null,'missing candidate no complete margin');
$bad=$rows;$bad[1]['value']['basis']='per_room_night';ck(in_array('hotel:incomparable_basis',$c::generic($bad,$ctx,$fx)['blockers'],true),'basis mismatch');
$bad=$rows;$bad[0]['value']['mapping']['selection_mode']='manual';$bad[1]['value']['mapping']['selection_mode']='manual';ck(in_array('hotel:manual_selection_required',$c::generic($bad,$ctx,$fx)['blockers'],true),'manual choice not guessed');
$native=['currency'=>'USD','stages'=>[['key'=>'cairo1','reference_cost'=>400],['key'=>'luxor','reference_cost'=>140],['key'=>'cairo2','reference_cost'=>200]],'missing_cost_groups'=>['transport_missing'],'native_guide_cost_references'=>['priced_language_options'=>[['language_id'=>143,'internal_cost'=>7500,'currency'=>'EGP']]]];
$x=$c::native($native,$ctx,$fx);
ck($x['converted_reference_subtotal_minor']===89000,'native stage cost not multiplied again');
ck($x['profit_preview']['indicative_selling_price_minor']===118667,'native partial margin once');
ck($x['commercial_total_minor']===null&&in_array('transport_missing',$x['blockers'],true),'partial cannot authorize');
ck($c::native($native,array_replace($ctx,['guide_language_id'=>null]),$fx)['converted_reference_subtotal_minor']===74000,'no guessed guide language');
$all=$c::inspect($rows,$ctx,$native);
ck($all['native_and_registry_not_summed']&&$all['approved_price']===null&&!$all['rate_generation_ready'],'scopes isolated, generator denied');
echo "PASS $n/$n generic cost composition\n";

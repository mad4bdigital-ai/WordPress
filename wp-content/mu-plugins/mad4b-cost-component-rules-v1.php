<?php
/** Plugin Name: MAD4B Cost Component Rules
 * Read-only relationship/repeater adapters and draft selection policy. No commercial admission.
 */
defined('ABSPATH') || exit;
final class MAD4B_Cost_Component_Rules_V1 {
 const MODES=['all_required','cheapest_eligible','priority','manual'];
 const ROLES=['required','optional','conditional'];
 const INCLUSIONS=['included','extra','upgrade'];
 const QUANTITIES=['basis','fixed','travelers','nights','days','rooms','vehicles','guides'];
 static function relation(int $id):?object {
  if(!$id||!function_exists('jet_engine'))return null;
  $manager=jet_engine()->relations??null;
  if(!is_object($manager)||!method_exists($manager,'get_active_relations'))return null;
  $r=$manager->get_active_relations()[$id]??null;
  return is_object($r)&&method_exists($r,'get_args')?$r:null;
 }
 static function relationEndpoints($r):?array {
  if(!is_object($r))return null;$a=$r->get_args();
  if(!is_array($a))return null;$out=[];
  foreach(['parent','child'] as $side){
   $v=$a[$side.'_object']??'';
   if(!is_string($v)||!preg_match('/^posts::([a-z0-9_-]+)$/D',$v,$m)||
      !MAD4B_Cost_Source_Registry_V1::eligible('post_type',$m[1]))return null;
   $out[$side]=$m[1];
  }
  return $out;
 }
 static function normalize(array $in,array $base):array|WP_Error {
  $rid=filter_var($in['relation_id']??0,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
  $dir=$in['relation_direction']??'children';
  $rep=$in['repeater_field']??'';
  $rowKey=$in['row_key_field']??'';
  $group=$in['component_key']??'';
  $role=$in['component_role']??'conditional';
  $mode=$in['selection_mode']??'manual';
  $inclusion=$in['inclusion']??'included';
  $quantity=$in['quantity_source']??'basis';
  $fixed=filter_var($in['fixed_quantity']??1,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>10000]]);
  $priority=filter_var($in['priority']??100,FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>10000]]);
  $weight=filter_var($in['weight']??100,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>10000]]);
  foreach([$rep,$rowKey,$group] as $v)if(!is_string($v)||($v!==''&&!MAD4B_Cost_Source_Registry_V1::validKey($v)))return new WP_Error('cost_component_field_invalid');
  if($rid===false||!in_array($dir,['children','parents'],true)||$fixed===false||$priority===false||$weight===false||
    !in_array($role,self::ROLES,true)||!in_array($mode,self::MODES,true)||
    !in_array($inclusion,self::INCLUSIONS,true)||!in_array($quantity,self::QUANTITIES,true)||
    ($rowKey!==''&&$rep==='')||($rid&&($base['kind']!=='post_type'||$base['relation_field']!=='')))
   return new WP_Error('cost_component_policy_invalid');
  if($rid){
   $ends=self::relationEndpoints(self::relation($rid));
   $sourceSide=$dir==='children'?'child':'parent';
   if(!$ends||$ends[$sourceSide]!==$base['slug'])return new WP_Error('cost_component_relation_endpoint_mismatch');
  }
  return ['relation_id'=>$rid,'relation_direction'=>$dir,'repeater_field'=>$rep,'row_key_field'=>$rowKey,
    'component_key'=>$group,'component_role'=>$role,'selection_mode'=>$mode,'inclusion'=>$inclusion,
    'quantity_source'=>$quantity,'fixed_quantity'=>$fixed,'priority'=>$priority,'weight'=>$weight];
 }
 static function linked(int $id,string $type,array $m):array|WP_Error {
  $r=self::relation((int)$m['relation_id']);$e=self::relationEndpoints($r);
  $child=$m['relation_direction']==='children';
  if(!$e||$e[$child?'parent':'child']!==$type||$e[$child?'child':'parent']!==$m['slug'])
   return new WP_Error('cost_relation_scope_mismatch');
  $method=$child?'get_children':'get_parents';
  if(!method_exists($r,$method))return new WP_Error('cost_relation_provider_missing');
  $ids=$r->$method($id,'ids');
  if(!is_array($ids))return new WP_Error('cost_relation_read_failed');
  if(count($ids)>50)return new WP_Error('cost_relation_candidate_limit');
  return $ids;
 }
 /** Each repeated row is a separate source instance. No silent collapse by source post. */
 static function values($raw,array $m,callable $read):array {
  if($m['repeater_field']==='')return [['row_key'=>null,'amount'=>$read($m['amount_field']),
    'currency'=>$m['currency_field']!==''?$read($m['currency_field']):$m['currency'],
    'basis'=>$m['basis_field']!==''?$read($m['basis_field']):$m['basis']]];
  if(!is_array($raw)||!$raw||count($raw)>100)return [['row_key'=>null,'amount'=>null,'currency'=>null,'basis'=>null,'issue'=>'repeater_missing_or_limit']];
  $out=[];$seen=[];
  foreach($raw as $i=>$row){
   $k=is_array($row)&&$m['row_key_field']!==''?($row[$m['row_key_field']]??null):$i;
   if(!is_scalar($k)||!preg_match('/^[a-zA-Z0-9_:-]{1,100}$/D',(string)$k)||isset($seen[(string)$k]))
    return [['row_key'=>null,'amount'=>null,'currency'=>null,'basis'=>null,'issue'=>'repeater_row_key_invalid_or_duplicate']];
   $seen[(string)$k]=true;
   if(!is_array($row))$row=[];
   $out[]=['row_key'=>(string)$k,'amount'=>$row[$m['amount_field']]??null,
    'currency'=>$m['currency_field']!==''?($row[$m['currency_field']]??null):$m['currency'],
    'basis'=>$m['basis_field']!==''?($row[$m['basis_field']]??null):$m['basis']];
  }
  return $out;
 }
 /** Rank only comparable reference candidates; native eligibility has not yet been established. */
 static function preview(array $rows):array {
  foreach($rows as $row)if(($row['component']??'')==='Registry candidate limit')
   return [['status'=>'partial_candidate_set','eligible_selection_confirmed'=>false,'commercial_total'=>null]];
  $groups=[];
  foreach($rows as $row){
   $v=$row['value']??[];$m=$v['mapping']??[];
   if(!is_array($m)||empty($m['component_key']))continue;
   $groups[$m['component_key']][]=$row;
  }
  $out=[];
  foreach($groups as $key=>$candidates){
   $policies=[];$units=[];$valid=[];$missing=0;
   foreach($candidates as $row){
    $v=$row['value'];$m=$v['mapping'];
    $policies[]=implode('|',[$m['component_role'],$m['selection_mode'],$m['inclusion'],$m['quantity_source'],$m['fixed_quantity']]);
    if(($row['status']??'')!=='reverify'||$v['amount']===null||$v['currency']===null||$v['basis']===null){$missing++;continue;}
    $units[]=$v['currency'].'|'.$v['basis'];$valid[]=$v;
   }
   $m=$candidates[0]['value']['mapping'];$mode=$m['selection_mode'];
   $reason='native_eligibility_and_cost_admission_required';$first=null;$ordered=[];
   if(count(array_unique($policies))!==1)$reason='conflicting_component_policies';
   elseif(count(array_unique($units))>1)$reason='non_comparable_currency_or_basis';
   elseif(!$valid)$reason='no_cost_reference';
   elseif($missing>0)$reason='incomplete_candidate_set';
   elseif(in_array($mode,['cheapest_eligible','priority'],true)){
    usort($valid,static function($a,$b)use($mode){
     $ma=$a['mapping'];$mb=$b['mapping'];
     return ($mode==='priority'?($ma['priority']<=>$mb['priority']):((float)$a['amount']<=>(float)$b['amount']))
      ?:($mb['weight']<=>$ma['weight'])?:((float)$a['amount']<=>(float)$b['amount'])
      ?:strcmp($a['entity_type'].'|'.$a['entity_id'].'|'.($a['row_key']??''),$b['entity_type'].'|'.$b['entity_id'].'|'.($b['row_key']??''));
    });
    $ordered=array_map(static fn($v)=>array_intersect_key($v,array_flip(['entity_kind','entity_type','entity_id','row_key','amount','currency','basis']))+
     ['priority'=>$v['mapping']['priority'],'weight'=>$v['mapping']['weight']],$valid);
    $first=$ordered[0];
   }
   $out[]=['component_key'=>$key,'policy'=>array_intersect_key($m,array_flip(['component_role','selection_mode','inclusion','quantity_source','fixed_quantity'])),
    'candidate_count'=>count($candidates),'missing_count'=>$missing,'reference_order_first'=>$first,'reference_fallback_order'=>$ordered,
    'status'=>$reason,'eligible_selection_confirmed'=>false,'fallback_authorized'=>false,'commercial_total'=>null];
  }
  return $out;
 }
 static function controls():void {
  if(!MAD4B_Cost_Source_Registry_V1::manageable()||!in_array($_GET['page']??'',['dynamic-pricing-controls','etg-dynamic-pricing-controls'],true))return;
  echo '<div class="notice notice-info"><h3>Dynamic Cost Components — Selection &amp; Weights</h3><p>Configure original relation IDs, repeaters and component rules. Draft reference order only; commercial eligibility and receipts remain independent.</p>';
  MAD4B_Cost_Source_Registry_V1::renderMappings();
  echo '<details><summary>Configure component source and policy</summary>';
  MAD4B_Cost_Source_Registry_V1::renderForm();
  echo '</details></div>';
 }
 static function boot():void {add_action('admin_notices',[self::class,'controls'],96);}
}
MAD4B_Cost_Component_Rules_V1::boot();

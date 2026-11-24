<?php
/** Plugin Name: MAD4B Generic Cost Composition
 * Version: 1.0.0
 * Reference-only cost math; original native evidence and commercial admission remain independent.
 */
defined('ABSPATH')||exit;
final class MAD4B_Generic_Cost_Composition_V1 {
 const CONTRACT='mad4b.generic-cost-composition.preview.v1';
 const CUR=['USD','EGP','EUR','GBP'];
 const LIMIT=1000000000;
 static function number($v,int $min,int $max):?int {
  if(!is_int($v)&&(!is_string($v)||!preg_match('/^[0-9]{1,6}$/D',$v)))return null;
  $n=(int)$v;return $n>=$min&&$n<=$max?$n:null;
 }
 static function context(array $o):array {
  $c=['currency'=>in_array($o['currency']??'USD',self::CUR,true)?($o['currency']??'USD'):null,
   'date'=>is_string($o['date']??null)?$o['date']:'',
   'tier_id'=>self::number($o['tier_id']??0,0,999999999)];
  foreach(['adults'=>[1,60],'children'=>[0,60],'infants'=>[0,30],'rooms'=>[1,60],
   'nights'=>[1,365],'days'=>[1,365],'vehicles'=>[1,60],'guides'=>[1,30],'cabins'=>[1,60],
   'guide_language_id'=>[1,999999999]] as $key=>$range)
   $c[$key]=self::number($o[$key]??null,$range[0],$range[1]);
  return $c;
 }
 /** Decimal major amount to ten-thousandths, exact and bounded. */
 static function decimal($raw):?int {
  if(is_float($raw)){if(!is_finite($raw))return null;$raw=number_format($raw,4,'.','');}
  if(!is_string($raw)&&!is_int($raw))return null;
  if(!preg_match('/^([0-9]{1,9})(?:\.([0-9]{1,4}))?$/D',(string)$raw,$m))return null;
  $v=(int)$m[1]*10000+(int)str_pad($m[2]??'',4,'0');return $v>0?$v:null;
 }
 static function quantity(array $m,string $basis,array $c):?int {
  $a=$c['adults'];$n=$c['nights'];$r=$c['rooms'];$d=$c['days'];$g=$c['guides'];$v=$c['vehicles'];$b=$c['cabins'];
  $src=$m['quantity_source']??'basis';$fixed=self::number($m['fixed_quantity']??1,1,10000);
  // A person price is not an adult-only price for a mixed party without policy.
  if(in_array($basis,['per_person','per_person_night','per_person_trip'],true)&&
   ($c['children']!==0||$c['infants']!==0))return null;
  $expected=match($basis){
   'per_person','per_person_trip'=>'travelers','per_person_night'=>'basis',
   'per_room_night','per_cabin_night','per_guide_day'=>'basis',
   'per_vehicle'=>'vehicles','per_day'=>'days','per_cabin_trip'=>'basis',
   'per_trip','per_group','per_stay','per_item'=>'fixed',default=>null};
  if($expected===null||($src!=='basis'&&$src!==$expected))return null;
  return match($basis){
   'per_person','per_person_trip'=>$a,
   'per_person_night'=>$a&&$n?$a*$n:null,
   'per_room_night'=>$r&&$n?$r*$n:null,
   'per_cabin_night'=>$b&&$n?$b*$n:null,
   'per_cabin_trip'=>$b,
   'per_guide_day'=>$g&&$d?$g*$d:null,
   'per_vehicle'=>$v,'per_day'=>$d,
   'per_trip','per_group','per_stay'=>($src==='fixed'?$fixed:1),
   'per_item'=>$src==='fixed'?$fixed:null,
   default=>null};
 }
 static function fxSnapshot():?array {
  if(!function_exists('pricing_public_fx_snapshot'))return null;
  $s=pricing_public_fx_snapshot();return is_array($s)?$s:null;
 }
 /** A single original public FX snapshot for the whole preview; never commercial FX approval. */
 static function conversion(string $from,string $to,?array $fx):?float {
  if(!in_array($from,self::CUR,true)||!in_array($to,self::CUR,true))return null;
  if($from===$to)return 1.0;
  if(($fx['base']??null)!=='USD'||!is_array($fx['rates']??null))return null;
  $stamp=strtotime((string)($fx['fetched_at']??''));if(!$stamp||abs(time()-$stamp)>172800)return null;
  $a=$from==='USD'?1:($fx['rates'][$from]??null);$b=$to==='USD'?1:($fx['rates'][$to]??null);
  if(!is_numeric($a)||!is_numeric($b)||!is_finite((float)$a)||!is_finite((float)$b)||(float)$a<=0||(float)$b<=0)return null;
  $rate=(float)$b/(float)$a;return is_finite($rate)&&$rate>0&&$rate<=100000?$rate:null;
 }
 static function line(string $key,$amount,string $currency,int $qty,string $target,?array $fx):array {
  $dec=self::decimal($amount);$rate=self::conversion($currency,$target,$fx);
  $out=['line_key'=>$key,'unit_amount'=>$amount,'source_currency'=>$currency,'quantity'=>$qty,
   'source_total_minor'=>null,'target_currency'=>$target,'target_total_minor'=>null,
   'fx_rate'=>$rate,'fx_status'=>$currency===$target?'same_currency':'public_reference_only','status'=>'missing_amount_or_quantity'];
  if(!$dec||$qty<1||$qty>100000||$dec>intdiv(PHP_INT_MAX-99,$qty))return $out;
  $scaled=$dec*$qty;$minor=intdiv($scaled+99,100);
  if($minor>self::LIMIT)return $out;
  $out['source_total_minor']=$minor;
  if($rate===null){$out['status']='fx_reference_missing';return $out;}
  // Convert exact extended source cost (not rounded per unit); round once per line.
  $converted=$scaled*$rate/100;
  if(!is_finite($converted)||$converted>self::LIMIT){$out['status']='amount_limit_exceeded';return $out;}
  $out['target_total_minor']=$currency===$target?$minor:(int)ceil($converted);
  $out['status']='reference_cost_calculated';return $out;
 }
 static function identity(array $v):string {
  $m=$v['mapping']??[];
  return hash('sha256',json_encode([$v['entity_kind']??null,$v['entity_type']??null,$v['entity_id']??null,
    $m['repeater_field']??null,$v['row_key']??null,$m['amount_field']??null],JSON_UNESCAPED_SLASHES));
 }
 static function totals(array $lines):array {
  $sum=0;$by=[];$missing=0;$count=0;
  foreach($lines as $l){
   $source=$l['source_total_minor']??null;$target=$l['target_total_minor']??null;
   if(is_int($source)){$cur=$l['source_currency'];$by[$cur]=($by[$cur]??0)+$source;}
   if(!is_int($target)||$sum>self::LIMIT-$target){$missing++;continue;}$sum+=$target;$count++;
  }
  ksort($by);return ['source_subtotals_minor'=>$by,'converted_reference_subtotal_minor'=>$count?$sum:null,
   'converted_line_count'=>$count,'unpriced_line_count'=>$missing];
 }
 static function profit(?int $cost,?string $currency):?array {
  if(!$cost||!$currency||!class_exists('MAD4B_Company_Global_Profit_V1',false))return null;
  $r=MAD4B_Company_Global_Profit_V1::preview($cost,$currency);
  if(is_wp_error($r))return ['error'=>$r->get_error_code()];
  return $r+['partial_scope_only'=>true,'commercial_price_approved'=>false];
 }
 /** Registry candidates remain separate from native accommodation/guide cost to avoid double counting. */
 static function generic(array $rows,array $c,?array $fx):array {
  $groups=[];$unassigned=0;$blockers=[];$lines=[];$other=[];$used=[];
  foreach($rows as $row){
   if(!str_starts_with((string)($row['source']??''),'registry:'))continue;
   $v=$row['value']??[];$m=$v['mapping']??[];
   if(($row['component']??'')==='Registry candidate limit')$blockers[]='partial_candidate_set';
   if(empty($m['component_key'])){$unassigned++;continue;}
   $groups[$m['component_key']][]=$row;
  }
  foreach($groups as $key=>$group){
   $policy=$group[0]['value']['mapping'];$mode=$policy['selection_mode']??'manual';
   $role=$policy['component_role']??'conditional';$inc=$policy['inclusion']??'included';
   $p=static fn($m)=>json_encode(array_intersect_key($m,array_flip(['component_role','selection_mode','inclusion','quantity_source','fixed_quantity'])));
   $valid=[];$issues=[];$seen=[];
   foreach($group as $row){
    $v=$row['value'];$m=$v['mapping'];
    if($p($m)!==$p($policy)){$issues[]='conflicting_component_policies';continue;}
    $id=self::identity($v);if(isset($seen[$id])){$issues[]='duplicate_source_instance';continue;}$seen[$id]=true;
    $basis=$v['basis']??null;$cur=$v['currency']??null;
    $qty=is_string($basis)?self::quantity($m,$basis,$c):null;
    if(($row['status']??'')!=='reverify'||!$qty||!is_string($cur)||!$c['currency']){$issues[]='missing_cost_or_quantity';continue;}
    $l=self::line($key.':'.$id,$v['amount'],$cur,$qty,$c['currency'],$fx);
    if($l['target_total_minor']===null){$issues[]=$l['status'];continue;}
    $valid[]=$l+['identity'=>$id,'source_entity_id'=>$v['entity_id']??null,'row_key'=>$v['row_key']??null,
      'basis'=>$basis,'priority'=>(int)($m['priority']??100),'weight'=>(int)($m['weight']??100)];
   }
   if($role!=='required'||$inc!=='included'){
    $other[]=['component_key'=>$key,'role'=>$role,'inclusion'=>$inc,'reference_candidates'=>$valid,'blockers'=>array_values(array_unique($issues))];
    if($inc==='included'&&$role==='conditional')$blockers[]=$key.':condition_unresolved';
    continue;
   }
   if($mode==='manual')$issues[]='manual_selection_required';
   if(!$valid)$issues[]='no_calculable_reference';
   if($issues){foreach(array_unique($issues) as $issue)$blockers[]=$key.':'.$issue;continue;}
   if(in_array($mode,['priority','cheapest_eligible'],true)){
    // Do not compare different billing bases even after FX; stage/eligibility are unverified.
    if(count(array_unique(array_column($valid,'basis')))!==1){$blockers[]=$key.':incomparable_basis';continue;}
    usort($valid,static fn($a,$b)=>($mode==='priority'?($a['priority']<=>$b['priority']):($a['target_total_minor']<=>$b['target_total_minor']))
      ?:($b['weight']<=>$a['weight'])?:($a['target_total_minor']<=>$b['target_total_minor'])?:strcmp($a['identity'],$b['identity']));
    $valid=[$valid[0]];
   }elseif($mode!=='all_required'){$blockers[]=$key.':invalid_selection_mode';continue;}
   foreach($valid as $l){
    if(isset($used[$l['identity']])){$blockers[]=$key.':source_already_counted';continue;}
    $used[$l['identity']]=true;$lines[]=$l;
   }
  }
  if($unassigned)$blockers[]='ungrouped_registry_references';
  if(!$groups)$blockers[]='no_grouped_registry_components';
  $t=self::totals($lines);
  return ['scope'=>'registry_only_not_merged_with_native','lines'=>$lines,'optional_extra_conditional'=>$other,
   'component_count'=>count($groups),'blockers'=>array_values(array_unique($blockers))]+$t+
   ['profit_preview'=>!$blockers&&!$t['unpriced_line_count']?self::profit($t['converted_reference_subtotal_minor'],$c['currency']):null,
    'native_eligibility_verified'=>false,'commercial_total_minor'=>null];
 }
 static function native(array $preflight,array $c,?array $fx):array {
  $lines=[];$blockers=(array)($preflight['missing_cost_groups']??$preflight['missing_groups']??[]);
  $seen=[];$target=$c['currency'];$guide=$preflight['native_guide_cost_references']??[];
  if(!$target)return ['blockers'=>['target_currency_invalid'],'commercial_total_minor'=>null];
  if(!empty($preflight['missing_source_errors'])||!empty($preflight['source_errors']))$blockers[]='native_stage_source_errors';
  foreach((array)($preflight['stages']??[]) as $s){
   $key=(string)($s['stage_key']??$s['key']??'');
   if(!$key||isset($seen[$key])){$blockers[]='duplicate_or_missing_native_stage';continue;}$seen[$key]=true;
   $cur=$s['currency']??$preflight['currency']??null;
   if(!is_string($cur)||!self::decimal($s['reference_cost']??null)){$blockers[]=$key.':cost_missing';continue;}
   // Native stage already includes the entire room/party/night quantity. Never multiply again.
   $lines[]=self::line('native:'.$key,$s['reference_cost'],$cur,1,$target,$fx)+['basis'=>'native_extended_stage_total','property_id'=>$s['property_id']??$s['pricing_property_id']??null,'cost_rate_id'=>$s['cost_rate_id']??null];
  }
  $language=$c['guide_language_id'];$options=(array)($guide['priced_language_options']??[]);
  if($language===null)$blockers[]='guide_language_selection_required';
  else{
   $matches=array_values(array_filter($options,static fn($g)=>(int)($g['language_id']??0)===$language));
   if(count($matches)!==1||!self::decimal($matches[0]['internal_cost']??null)||!is_string($matches[0]['currency']??null))
    $blockers[]='guide_cost_reference_missing';
   else{
    $g=$matches[0];$qty=$c['guides'];
    if(!$qty)$blockers[]='guide_count_required';
    else $lines[]=self::line('native:guide:'.$language,$g['internal_cost'],$g['currency'],$qty,$target,$fx)+['basis'=>'native_whole_program_per_guide','guide_language_id'=>$language];
   }
  }
  $t=self::totals($lines);
  if($t['unpriced_line_count'])$blockers[]='native_line_fx_or_amount_missing';
  return ['scope'=>'native_partial_reference_only','lines'=>$lines,'blockers'=>array_values(array_unique($blockers))]+$t+
   ['profit_preview'=>self::profit($t['converted_reference_subtotal_minor'],$target),'commercial_total_minor'=>null];
 }
 static function inspect(array $rows,array $options,array $native=[]):array {
  $c=self::context($options);$fx=self::fxSnapshot();
  return ['contract'=>self::CONTRACT,'context'=>$c,'fx_snapshot'=>$fx?['source'=>$fx['source']??null,
   'fetched_at'=>$fx['fetched_at']??null,'sha256'=>hash('sha256',wp_json_encode($fx)),'commercially_approved'=>false]:null,
   'registry_composition'=>self::generic($rows,$c,$fx),
   'native_composition'=>$native?self::native($native,$c,$fx):null,
   'native_and_registry_not_summed'=>true,'commercial_total_minor'=>null,'approved_price'=>null,
   'rate_generation_ready'=>false,'checkout_ready'=>false,
   'generator_contract'=>'mad4b.package-approved-price-composition.v1',
   'commercial_blockers'=>['independent_signed_receipts_and_native_eligibility_required','complete_component_coverage_and_deduplication_required','commercial_fx_and_tax_policy_required','occupancy_and_date_window_approval_required']];
 }
}

<?php
/** Unified nonfinancial extra arithmetic. Sources remain domain-specific. */
defined('ABSPATH') || exit;
final class Shared_Extra_Unit_V1 {
 const BASES=['per_stay','per_booking','per_night','per_person','per_person_night',
  'per_room','per_room_night','per_cabin','per_cabin_night','per_unit',
  'per_unit_night','per_use'];
 public static function quote($value,$source,$target,$basis,$context,$quantity=1){
  if(!is_array($context)||!is_string($basis)||!in_array($basis,self::BASES,true)||
     !is_scalar($value)||is_bool($value)||!is_numeric($value)||
     !is_finite((float)$value)||(float)$value<0||
     !is_string($source)||!preg_match('/^[A-Z]{3}$/D',$source)||
     !is_string($target)||!preg_match('/^[A-Z]{3}$/D',$target)||
     !is_int($quantity)||$quantity<1||$quantity>100)
   return new WP_Error('shared_extra_contract_invalid');
  $nights=$context['nights']??null;$units=$context['units']??null;
  $adults=$context['adults']??null;$children=$context['children']??null;
  if(!is_int($nights)||$nights<1||$nights>365||!is_int($units)||$units<1||$units>99||
     !is_int($adults)||$adults<1||$adults>300||!is_int($children)||$children<0||$children>300)
   return new WP_Error('shared_extra_context_invalid');
  $unitKind=$context['unit_kind']??null;
  if(in_array($basis,['per_cabin','per_cabin_night'],true)&&$unitKind!=='per_cabin_night')
   return new WP_Error('shared_extra_cabin_basis_mismatch');
  if(in_array($basis,['per_room','per_room_night'],true)&&$unitKind!=='per_room_night')
   return new WP_Error('shared_extra_room_basis_mismatch');
  $people=$adults+$children;
  $mult=match($basis){
   'per_booking','per_stay'=>1,
   'per_night'=>$nights,
   'per_person'=>$people,
   'per_person_night'=>$people*$nights,
   'per_room','per_cabin','per_unit'=>$units,
   'per_room_night','per_cabin_night','per_unit_night'=>$units*$nights,
   'per_use'=>$quantity,
  };
  // A selection quantity multiplies only where it is commercially
  // meaningful, including multi-use services and optional unit count.
  if($basis!=='per_use' && $quantity!==1)
   return new WP_Error('shared_extra_quantity_basis_not_supported');
  $raw=round((float)$value*$mult,2);
  $converted=$source===$target?$raw:pricing_currency_convert($raw,$source,$target);
  if(!is_numeric($converted)||!is_finite((float)$converted)||(float)$converted<0)
   return new WP_Error('shared_extra_fx_unavailable');
  return ['contract'=>'shared.extra-unit.v1','basis'=>$basis,
   'quantity'=>$quantity,'multiplier'=>$mult,'unit_amount'=>(float)$value,
   'source_total'=>$raw,'source_currency'=>$source,
   'amount'=>round((float)$converted,2),'currency'=>$target,
   'status'=>'preview_only_not_checkout_authority','checkout_ready'=>false];
 }

 /** Transport specific quantities. Accommodation quote() is unchanged. */
 public static function quote_transport($amount,$from,$to,$basis,$context,$quantity=1){
  $fields=['per_booking'=>'bookings','per_trip'=>'trips','per_day'=>'days',
   'per_vehicle'=>'vehicles','per_person'=>'persons','per_leg'=>'legs'];
  $picked=['per_bag','per_stop','per_waiting_hour','per_use'];
  if(!is_array($context)||!is_string($basis)||
    (!isset($fields[$basis])&&!in_array($basis,$picked,true))||
    !is_numeric($amount)||!is_finite((float)$amount)||(float)$amount<=0||
    !preg_match('/^[A-Z]{3}$/D',(string)$from)||
    !preg_match('/^[A-Z]{3}$/D',(string)$to)||
    !is_int($quantity)||$quantity<1||$quantity>100)
   return new \WP_Error('shared_transport_extra_contract_invalid');
  if(in_array($basis,$picked,true))$mult=$quantity;
  else{
   if($quantity!==1)return new \WP_Error('shared_transport_quantity_basis_invalid');
   $mult=$context[$fields[$basis]]??null;
   if(!is_int($mult)||$mult<1||$mult>999)return new \WP_Error('shared_transport_quantity_unverified');
  }
  $source=round((float)$amount*$mult,2);
  $fx=$from===$to?$source:pricing_currency_convert($source,$from,$to);
  if(!is_numeric($fx)||!is_finite((float)$fx)||(float)$fx<0)
   return new \WP_Error('shared_transport_fx_unavailable');
  return ['basis'=>$basis,'quantity'=>$quantity,'multiplier'=>$mult,
   'source_amount'=>$source,'source_currency'=>$from,
   'amount'=>round((float)$fx,2),'currency'=>$to,
   'status'=>'nonbinding_transport_extra_preview','checkout_ready'=>false];
 }
}

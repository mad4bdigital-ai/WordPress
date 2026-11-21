<?php
/**
 * Explicit one-time authorized WordPress CLI import of a trusted, private
 * Google Sheets readback (NOT a public media upload or rate-publisher).
 *
 * Input: /tmp/mad4b-quotation-source-reference-20261121.json
 * Only the Apps-connected sheet, Hotels/Cruises/Costal source tabs.
 * Never reads Sheet2 (contains unrelated personal guest records).
 *
 * Invoked by: wp --user=1 eval-file /tmp/.../import-quotation-....php
 *
 * No Property, Unit, Tour Rate, Order or booking mutation.
 * Idempotent; errors on unknown foreign source options.
 */
defined('ABSPATH') || exit;
if(!defined('WP_CLI') || !WP_CLI)return;
$fail=static function($code){WP_CLI::error('MAD4B_QUOTATION_SOURCE_NOT_IMPORTED '.$code);};
if(!current_user_can('manage_options') || !current_user_can('commerce_manage_pricing'))
    $fail('not_authorized');
$path='/tmp/mad4b-quotation-source-reference-20261121.json';
if(!is_readable($path))$fail('snapshot_unavailable');
$raw=file_get_contents($path);
if(!is_string($raw)||strlen($raw)<8000||strlen($raw)>200000)$fail('snapshot_size');
$data=json_decode($raw,true);
if(!is_array($data)||json_last_error()!==JSON_ERROR_NONE)$fail('snapshot_invalid_json');
if(($data['v']??null)!==1 ||
   ($data['sheet']??'')!=='1PxrMcF2CuZrRGZ6R-2ZEeuqHXoqL6dT150XkhZk04-c' ||
   ($data['source_status']??'')!=='user_quotation_reference_unapproved' ||
   !isset($data['groups']) || !is_array($data['groups']) ||
   count($data['groups'])!==92)$fail('unrecognized_sheet_version');
$tabs=['Hotels'=>41,'Cruises'=>40,'Costal'=>11];
$counts=array_fill_keys(array_keys($tabs),0);
$keys=[];
$optional=0;$mandatory=0;$notes=0;$priceRows=0;
foreach($data['groups'] as $group){
 if(!is_array($group)||!isset($group['t'],$group['r'],$group['n']) ||
    !isset($counts[$group['t']]) || (int)$group['r']<3 ||
    (int)$group['r']>400 || trim((string)$group['n'])==='')$fail('invalid_group');
 $key=$group['t'].':'.(int)$group['r'];
 if(isset($keys[$key]))$fail('duplicate_source_group_'.$key);
 $keys[$key]=true;$counts[$group['t']]++;
 foreach((array)($group['x']??[]) as $note){
    if(!is_array($note)||count($note)<5)$fail('invalid_note');
    $index=(int)$note[2];$row=(int)$note[1];$text=$note[3];
    if($index<4||$index>15||$row<(int)$group['r'] ||
       !is_string($text)||strlen($text)>3500)$fail('out_of_bounds_note');
    $notes++;
    if($note[4]==='mandatory')$mandatory++;
    if(in_array($note[4],['optional','upgrade'],true))$optional++;
 }
 foreach((array)($group['p']??[]) as $price){
    if(!is_array($price)||count($price)<6 ||
        (int)$price[1]<(int)$group['r'])$fail('invalid_price_row');
    $priceRows++;
 }
}
if($counts!==$tabs)$fail('source_tab_count_drift');
$option='mad4b_supplier_quotation_sheet_source_v1';
$old=get_option($option,null);
if($old!==null){
  if(!is_array($old)||($old['sheet']??'')!==$data['sheet'] ||
     ($old['v']??null)!==1)$fail('other_source_already_registered');
}
if(!empty($GLOBALS['mad4b_supplier_sheet_preflight_only'])){
    WP_CLI::line(wp_json_encode([
        'status'=>'PREFLIGHT_PASS','site'=>home_url(),'option_exists'=>$old!==null,
        'groups'=>count($data['groups']),'tab_counts'=>$counts,
        'source_notes'=>$notes,'optional_mentions'=>$optional,
        'mandatory_mentions'=>$mandatory,'rate_row_references'=>$priceRows,
        'source_manifest_sha256'=>hash('sha256',$raw),
        'commercial_approval'=>false,'checkout_enabled'=>false
    ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    return;
}
if($old===null){
    $write=add_option($option,$data,'',false);
    if(!$write)$fail('add_option_denied');
    $status='CREATED_PRIVATE_SOURCE_REGISTRY';
}else{
    if(hash('sha256',wp_json_encode($old))===hash('sha256',wp_json_encode($data)))
        $status='ALREADY_CURRENT_NO_WRITE';
    else $fail('refuse_to_overwrite_modified_source_without_new_audit');
}
$saved=get_option($option,[]);
if(!is_array($saved)||count($saved['groups']??[])!==92 ||
   hash('sha256',wp_json_encode($saved))!==hash('sha256',wp_json_encode($data)))
   $fail('post_write_readback_mismatch');
global $wpdb;
$autoload=$wpdb->get_var($wpdb->prepare(
  "SELECT autoload FROM {$wpdb->options} WHERE option_name=%s",$option));
if(!in_array($autoload,['off','no','auto-off'],true))
   $fail('private_source_must_not_autoload');
WP_CLI::line(wp_json_encode([
  'status'=>$status,'site'=>home_url(),'groups'=>count($saved['groups']),
  'tab_counts'=>$counts,'raw_sheet_notes'=>$notes,
  'optional_mentions'=>$optional,'mandatory_mentions'=>$mandatory,
  'sheet_price_references'=>$priceRows,'option_autoload'=>$autoload,
  'source_manifest_sha256'=>hash('sha256',$raw),
  'commercial_price_approved'=>false,'tour_rates_modified'=>false,
  'supplier_contracts_written'=>false
],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));

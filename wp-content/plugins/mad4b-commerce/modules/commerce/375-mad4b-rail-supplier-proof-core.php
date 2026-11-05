<?php
/**
 * Plugin Name: MAD4B Rail Supplier Evidence Core
 * Version: 0.1.0
 * Description: Review-only evidence receipts; never publishes or approves a price.
 */
defined('ABSPATH')||exit;
final class MAD4B_Rail_Supplier_Proof_V1 {
 const CONTRACT='mad4b.rail-supplier-evidence.v1';
 const STORE='mad4b_rail_supplier_quote_proofs_v1';
 static function can_submit(): bool {
  return current_user_can('manage_woocommerce')&&
   class_exists('MAD4B_Rail_Fare_Quote_Intake_V1')&&
   MAD4B_Rail_Fare_Quote_Intake_V1::can_write();
 }
 static function request_rows(): array {
  $data=(array)get_option(MAD4B_Rail_Fare_Quote_Intake_V1::STORE,[]);
  return array_filter($data,static function($row){
   $s=(array)($row['snapshot']??[]);
   return ($row['contract']??'')===MAD4B_Rail_Fare_Quote_Intake_V1::CONTRACT &&
    !empty($s['travel_date']) && !empty($s['service_class']) && !empty($s['market']) &&
    !in_array(($row['status']??''),['STALE_SOURCE_ROUTE','CANCELLED'],true);
  });
 }
 static function allowed_source($url,$provider): bool {
  if(!filter_var($url,FILTER_VALIDATE_URL) ||
     strtolower((string)wp_parse_url($url,PHP_URL_SCHEME))!=='https')return false;
  $host=strtolower((string)wp_parse_url($url,PHP_URL_HOST));
  return in_array($host,$provider==='abela'?
   ['abelatrains.com','www.abelatrains.com','sleep.abelatrains.com']:
   ['obs.enr.gov.eg','enr.gov.eg','www.enr.gov.eg'],true);
 }
 static function submit(array $fields){
  if(!self::can_submit())return new WP_Error('rail_proof_unauthorized');
  $key=strtolower(trim((string)($fields['request_key']??'')));
  if(!preg_match('/^[a-f0-9]{64}$/D',$key))return new WP_Error('rail_proof_key_invalid');
  $request=(array)(self::request_rows()[$key]??[]);
  if(!$request)return new WP_Error('rail_proof_dated_request_required');
  $snap=(array)$request['snapshot'];
  $cfg=MAD4B_Rail_Fare_Quote_Intake_V1::config((int)($snap['transport_id']??0));
  if(is_wp_error($cfg))return $cfg;
  if($cfg['site_uuid']!==($snap['site_uuid']??'') ||
     $cfg['train_number']!==(string)$snap['train_number']||
     (int)$cfg['from_station_id']!==(int)$snap['from_station_id']||
     (int)$cfg['to_station_id']!==(int)$snap['to_station_id']||
     $cfg['provider']!==$snap['provider']||
     !in_array($snap['service_class'],$cfg['classes'],true)||
     !in_array($snap['market'],$cfg['markets'],true))
    return new WP_Error('rail_proof_source_or_route_changed');
  if(!class_exists('MAD4B_SCP_Content_Readiness_Alerts')||
     !class_exists('MAD4B_SCP_Content_Jobs')||
     !class_exists('MAD4B_SCP_Research_Groups'))
    return new WP_Error('rail_proof_source_services_missing');
  $ledger=(array)get_option(MAD4B_SCP_Content_Readiness_Alerts::STORE,[]);
  $issue=(array)($ledger[$cfg['issue_id']]??[]);
  $job=MAD4B_SCP_Content_Jobs::get_job(['job_id'=>$cfg['content_job_id']]);
  if(($issue['status']??'')!=='open'||
     ($issue['content_job_id']??'')!==$cfg['content_job_id']||
     is_wp_error($job)||($job['job']['state']??'')!=='WAITING_REVIEW')
    return new WP_Error('rail_proof_content_job_not_reviewable');
  $ref=trim((string)($fields['operator_reference']??''));
  $amount=trim((string)($fields['amount']??''));
  $currency=strtoupper(trim((string)($fields['currency']??'')));
  $url=trim((string)($fields['source_url']??''));
  $expires=trim((string)($fields['expires_at']??''));
  if(!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.:\/-]{5,119}$/D',$ref))
   return new WP_Error('rail_proof_supplier_reference_invalid');
  if(!preg_match('/^[0-9]{1,9}(?:\.[0-9]{1,2})?$/D',$amount)|| (float)$amount<=0)
   return new WP_Error('rail_proof_amount_invalid');
  if(!in_array($currency,['EGP','USD','EUR','GBP'],true))
   return new WP_Error('rail_proof_currency_invalid');
  if(!self::allowed_source($url,$cfg['provider']))
   return new WP_Error('rail_proof_provider_url_required');
  $tz=wp_timezone();
  $deadline=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$expires,$tz);
  if(!$deadline||$deadline->format('Y-m-d\TH:i')!==$expires||
     $deadline<=new DateTimeImmutable('now',$tz))
   return new WP_Error('rail_proof_expired_or_invalid');
  // Optional redacted media. Never accept untrusted direct filesystem paths.
  $attachment=absint($fields['attachment_id']??0);
  $media=null;
  if($attachment){
   $file=get_attached_file($attachment);
   $mime=get_post_mime_type($attachment);
   if(get_post_type($attachment)!=='attachment'||!is_string($file)||
      !is_readable($file)||!in_array($mime,[
       'application/pdf','image/jpeg','image/png','image/webp'],true))
      return new WP_Error('rail_proof_media_invalid');
   $media=['attachment_id'=>$attachment,'mime'=>$mime,'sha256'=>hash_file('sha256',$file)];
  }
  $proofId=hash('sha256',implode('|',[
   self::CONTRACT,$key,$ref,$amount,$currency,$deadline->format(DATE_ATOM)
  ]));
  $record=[
   'contract'=>self::CONTRACT,'proof_id'=>$proofId,'request_key'=>$key,
   'site_uuid'=>$cfg['site_uuid'],'issue_id'=>$cfg['issue_id'],
   'job_id'=>$cfg['content_job_id'],'transport_id'=>$cfg['transport_id'],
   'source_sha256'=>$request['source_sha256'],'request_snapshot'=>$snap,
   'operator'=>$cfg['provider'],'operator_reference'=>$ref,
   'supplier_claimed_amount'=>$amount,'supplier_currency'=>$currency,
   'source_url'=>$url,'redacted_attachment'=>$media,
   'expires_at'=>$deadline->format(DATE_ATOM),'submitted_at'=>gmdate('c'),
   'status'=>'EVIDENCE_SUBMITTED_REQUIRES_INDEPENDENT_VERIFICATION',
   'supplier_authenticity_verified'=>false,'supplier_inventory_verified'=>false,
   'pricing_approved'=>false,'rate_published'=>false,'woo_checkout_authorized'=>false,
   'submitted_by'=>get_current_user_id(),
   'woo_assessment'=>MAD4B_Rail_Woo_Settings_V1::evaluate($amount,$currency,false,false)
  ];
  global $wpdb;
  $lock='mad4b_rail_proof_'.get_current_blog_id();
  if((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,3)',$lock))!==1)
   return new WP_Error('rail_proof_ledger_busy');
  try{
   $existing=(array)get_option(self::STORE,[]);
   if(isset($existing[$proofId]))
    return ['proof_id'=>$proofId,'created'=>false,'status'=>$existing[$proofId]['status']];
   if(count($existing)>=500)return new WP_Error('rail_proof_ledger_limit');
   $group=MAD4B_SCP_Research_Groups::group(
    'geo:transportations:'.$cfg['transport_id'],$cfg['site_uuid'],'en');
   if(is_wp_error($group))return $group;
   $r=MAD4B_SCP_Research_Groups::add_record($group['id'],'manual_research','en',
     $url,[
      'contract'=>self::CONTRACT,'proof_id'=>$proofId,
      'request_key'=>$key,'site_uuid'=>$cfg['site_uuid'],
      'job_id'=>$cfg['content_job_id'],'train_number'=>$cfg['train_number'],
      'travel_date'=>$snap['travel_date'],'class'=>$snap['service_class'],
      'market'=>$snap['market'],'currency'=>$currency,
      'supplier_claimed_amount'=>$amount,
      'operator_reference'=>$ref,'supplier_confirmed'=>false,
      'inventory_confirmed'=>false,'rate_approved'=>false,
      'redacted_attachment'=>$media
     ]);
   if(is_wp_error($r))return $r;
   $record['research_uid']=$r['record_uid'];
   $existing[$proofId]=$record;
   update_option(self::STORE,$existing,false);
   $read=(array)get_option(self::STORE,[]);
   if(($read[$proofId]['research_uid']??'')!==$r['record_uid'])
    return new WP_Error('rail_proof_readback_failed');
   $ledger=(array)get_option(MAD4B_SCP_Content_Readiness_Alerts::STORE,[]);
   $issue=(array)($ledger[$cfg['issue_id']]??[]);
   if(($issue['status']??'')!=='open'||
      ($issue['content_job_id']??'')!==$cfg['content_job_id'])
      return new WP_Error('rail_proof_ledger_issue_changed');
   $linked=(array)($issue['geo_research']['supplier_proof_ids']??[]);
   if(!in_array($proofId,$linked,true))$linked[]=$proofId;
   $ledger[$cfg['issue_id']]['geo_research']['supplier_proof_ids']=array_slice($linked,-30);
   $ledger[$cfg['issue_id']]['geo_research']['supplier_proof_review']='EVIDENCE_SUBMITTED_NOT_VERIFIED';
   update_option(MAD4B_SCP_Content_Readiness_Alerts::STORE,$ledger,false);
   $verify=(array)get_option(MAD4B_SCP_Content_Readiness_Alerts::STORE,[]);
   if(!in_array($proofId,(array)($verify[$cfg['issue_id']]['geo_research']['supplier_proof_ids']??[]),true))
      return new WP_Error('rail_proof_alert_readback_failed');
  }finally{$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));}
  if(class_exists('MAD4B_SCP_Audit'))
   MAD4B_SCP_Audit::record('mad4b/rail-supplier-evidence-submitted',[
    'site_uuid'=>$cfg['site_uuid'],'proof_id'=>$proofId,
    'job_id'=>$cfg['content_job_id'],'supplier_verified'=>false,
    'price_write_authorized'=>false], 'ok');
  return ['proof_id'=>$proofId,'created'=>true,
    'status'=>'EVIDENCE_SUBMITTED_REQUIRES_INDEPENDENT_VERIFICATION',
    'rate_created'=>false];
 }
 static function status():array{
  $r=(array)get_option(self::STORE,[]);
  $by=[];
  foreach($r as $v)$by[$v['status']??'UNKNOWN']=($by[$v['status']??'UNKNOWN']??0)+1;
  return ['dated_requests'=>count(self::request_rows()),'proof_records'=>count($r),
   'proof_statuses'=>$by,'supplier_verification_automated'=>false,
   'rate_publication_automated'=>false];
 }
}
if(defined('WP_CLI')&&WP_CLI)
 WP_CLI::add_command('mad4b rail-proof',static function($args){
  WP_CLI::line(wp_json_encode(MAD4B_Rail_Supplier_Proof_V1::status(),JSON_PRETTY_PRINT));
 });

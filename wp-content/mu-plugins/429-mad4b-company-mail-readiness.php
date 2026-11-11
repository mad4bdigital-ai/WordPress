<?php
/**
 * Plugin Name: MAD4B Company Mail Readiness
 * Description: Site-scoped, independently evidenced SMTP handoff, distinct from CMS write grants or marketing policy.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;
final class MAD4B_Company_Mail_Readiness_V1 {
 const EVIDENCE='mad4b_company_smtp_transport_handoff_v1';
 const ORIGINAL='mad4b_company_email_verification_v1';
 const LIFETIME=90*DAY_IN_SECONDS;
 private static function email($x):string {$e=strtolower(trim((string)$x));return is_email($e)?$e:'';}
 private static function site():array {
  if(!class_exists('MAD4B_SCP_Company_Communication',false)||!class_exists('MAD4B_SCP_Site_Profile',false))return [];
  $p=MAD4B_SCP_Site_Profile::profile();$uuid=(string)($p['site_uuid']??'');
  if(!$uuid || !MAD4B_SCP_Site_Profile::site_urls_match_enrollment())return [];
  $c=MAD4B_SCP_Company_Communication::config();
  $from=self::email($c['transactional_from_email']??'');
  if(!$from || ($c['email_transport']??'')!=='fluent_smtp')return [];
  $identity=hash('sha256',wp_json_encode(['site_uuid'=>$uuid,'environment'=>wp_get_environment_type(),
    'from_email'=>$from,'reply_to'=>(string)$c['traveler_reply_to'],
    'bookings_inbox'=>(string)$c['bookings_inbox']],JSON_UNESCAPED_SLASHES));
  return ['uuid'=>$uuid,'env'=>wp_get_environment_type(),'home'=>untrailingslashit(home_url('/')),
   'identity'=>$identity,'config'=>$c,'from'=>$from];
 }
 private static function connections(array $site):array {
  if(!function_exists('fluentMailGetSettings') || !class_exists('FluentMail\\App\\Models\\Settings'))return [];
  $settings=fluentMailGetSettings();if(!is_array($settings))return [];
  $wanted=[$site['from']];$support=self::email($site['config']['support_inbox']??'');
  if($support && !in_array($support,$wanted,true))$wanted[]=$support;
  $fingerprints=[];
  foreach($wanted as $email){
   $id=\FluentMail\App\Models\Settings::findMappingKey((array)($settings['mappings']??[]),$email);
   $x=(array)($settings['connections'][$id]['provider_settings']??[]);
   if(!$id || ($x['provider']??'')!=='smtp'||self::email($x['sender_email']??'')!==$email
     ||self::email($x['username']??'')!==$email ||empty($x['password']) ||($x['auth']??'')!=='yes'
     ||!in_array((string)($x['encryption']??''),['ssl','tls'],true)
     ||!in_array((int)($x['port']??0),[465,587],true)||empty($x['host']))return [];
   // HMAC of the secret binds certification to the current credentials;
   // no password, username or reversible credential is persisted in evidence.
   $fingerprints[$email]=hash('sha256',wp_json_encode([
    'mapping_id_digest'=>hash('sha256',(string)$id),'email'=>$email,
    'host'=>strtolower((string)$x['host']),'port'=>(int)$x['port'],
    'encryption'=>(string)$x['encryption'],
    'auth'=>(string)$x['auth'],
    'password_hmac'=>hash_hmac('sha256',(string)$x['password'],wp_salt('auth'))
   ],JSON_UNESCAPED_SLASHES));
  }
  ksort($fingerprints);
  return $fingerprints;
 }
 private static function certificate():array {
  $site=self::site();if(!$site)return [];
  $con=self::connections($site);if(!$con)return [];
  return ['site'=>$site,'fingerprint'=>hash('sha256',wp_json_encode($con,JSON_UNESCAPED_SLASHES)),
    'sender_count'=>count($con)];
 }
 private static function verified():bool {
  $c=self::certificate();if(!$c)return false;
  $e=get_option(self::EVIDENCE,[]);$v=get_option(self::ORIGINAL,[]);$s=$c['site'];
  if(!is_array($e)||!is_array($v))return false;
  return ($e['contract']??'')==='mad4b.company-smtp-handoff-evidence.v1'
   && ($e['site_uuid']??'')===$s['uuid']
   && ($e['environment']??'')===$s['env']
   && ($e['identity_sha256']??'')===$s['identity']
   && ($e['connections_sha256']??'')===$c['fingerprint']
   && ($e['state']??'')==='SMTP_TEST_HANDOFF_ACCEPTED'
   && (int)($e['checked_at_unix']??0)>time()-self::LIFETIME
   && ($v['contract']??'')==='mad4b.company-email-verification.v1'
   && ($v['status']??'')==='VERIFIED'
   && ($v['site_uuid']??'')===$s['uuid']
   && ($v['identity_sha256']??'')===$s['identity']
   && ($v['evidence_digest']??'')===hash('sha256',wp_json_encode($e,JSON_UNESCAPED_SLASHES))
   && !empty($v['verified_at']) && !empty($v['verifier_user_id']);
 }
 private static function source_log_evidence(array $cert):array {
  global $wpdb;
  $table=$wpdb->prefix.'fsmpt_email_logs';
  if($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$table))!==$table)return [];
  $rows=$wpdb->get_results("SELECT id,created_at,status,`from`,`to`,subject,response FROM {$table} ORDER BY id DESC LIMIT 150",ARRAY_A);
  $recipients=array_values(array_filter([self::email(get_option('admin_email','')),
      self::email(get_option('mad4b_admin_secondary_notification_email_v1',''))]));
  $targets=array_keys(self::connections($cert['site']));$proof=[];
  foreach((array)$rows as $row){
   if(($row['status']??'')!=='sent')continue;
   $stamp=strtotime((string)$row['created_at'].' UTC');
   if(!$stamp||$stamp<time()-self::LIFETIME||$stamp>time()+300)continue;
   $response=maybe_unserialize($row['response']??'');
   if(!is_array($response)||strtoupper(trim((string)($response['response']??'')))!=='OK')continue;
   if(!preg_match('/test/i',(string)($row['subject']??'')))continue;
   $from=(string)($row['from']??'');preg_match_all('/[a-zA-Z0-9._%+\\-]+@[a-zA-Z0-9.\\-]+/',$from,$matches);
   $senders=array_unique(array_map('strtolower',(array)($matches[0]??[])));
   $to=maybe_unserialize($row['to']??'');$testRecipients=[];
   foreach((array)$to as $item){$address=is_array($item)?($item['email']??''):$item;$address=self::email($address);if($address)$testRecipients[]=$address;}
   if(!$testRecipients||array_diff($testRecipients,$recipients))continue;
   foreach($targets as $email){if(!in_array($email,$senders,true)||isset($proof[$email]))continue;
    $proof[$email]=['log_id'=>(int)$row['id'],'logged_at_utc'=>gmdate('c',$stamp),
      'transport_status'=>'sent','provider_response'=>'OK',
      'recipient_scope'=>'configured_site_admin_inbox',
      'evidence_kind'=>'FluentSMTP SMTP test handoff; not an Inbox/DMARC delivery receipt'];
   }
  }
  if(count($proof)!==count($targets))return [];
  ksort($proof);return $proof;
 }
 public static function status():array {
  $c=self::certificate();$site=$c['site']??self::site();
  return ['contract'=>'mad4b.company-mail-readiness.v1','site'=>$site['home']??home_url(),
   'site_uuid'=>$site['uuid']??'','environment'=>wp_get_environment_type(),
   'configured'=>!empty($c),'mapped_sender_count'=>$c['sender_count']??0,
   'smtp_handoff_verified'=>self::verified(),
   'company_identity_verified'=>self::verified(),
   'evidence_kind'=>'SMTP_TEST_HANDOFF_ONLY','inbox_delivery_verified'=>false,
   'sender_setting_writes'=>0,'messages_sent_by_status'=>0,'cms_mutation_gate_independent'=>true];
 }
 public static function certify():array {
  if(!defined('WP_CLI')||!WP_CLI||!current_user_can('mad4b_manage_company_operations')
    ||!current_user_can('manage_options'))return ['error'=>'COMPANY_ADMIN_AUTH_REQUIRED'];
  $c=self::certificate();if(!$c)return ['error'=>'SMTP_CONNECTIONS_OR_SCOPE_NOT_READY'];
  $proof=self::source_log_evidence($c);if(!$proof)return ['error'=>'ORIGINAL_DUAL_SMTP_TEST_HANDOFF_EVIDENCE_MISSING'];
  $s=$c['site'];$record=['contract'=>'mad4b.company-smtp-handoff-evidence.v1',
   'site_uuid'=>$s['uuid'],'environment'=>$s['env'],'identity_sha256'=>$s['identity'],
   'connections_sha256'=>$c['fingerprint'],'state'=>'SMTP_TEST_HANDOFF_ACCEPTED',
   'sources'=>$proof,'checked_at_unix'=>time(),'verifier_user_id'=>get_current_user_id(),
   'inbox_delivery_verified'=>false];
  $v=['contract'=>'mad4b.company-email-verification.v1','site_uuid'=>$s['uuid'],
   'identity_sha256'=>$s['identity'],'status'=>'VERIFIED',
   'evidence_digest'=>hash('sha256',wp_json_encode($record,JSON_UNESCAPED_SLASHES)),
   'verified_at'=>gmdate('c'),'verifier_user_id'=>get_current_user_id()];
  update_option(self::EVIDENCE,$record,false);update_option(self::ORIGINAL,$v,false);
  return ['status'=>self::verified()?'SMTP_HANDOFF_CERTIFIED':'CERTIFICATE_READBACK_FAILED',
   'site_uuid'=>$s['uuid'],'environment'=>$s['env'],
   'sender_count'=>count($proof),'source_log_ids'=>array_values(array_map(static fn($x)=>$x['log_id'],$proof)),
   'inbox_delivery_verified'=>false,'no_email_sent_by_certifier'=>true];
 }
 public static function cli($args):void {
  if(($args[0]??'status')==='status'){WP_CLI::line(wp_json_encode(self::status(),JSON_UNESCAPED_SLASHES));return;}
  if(($args[0]??'')==='certify'){ $x=self::certify();WP_CLI::line(wp_json_encode($x,JSON_UNESCAPED_SLASHES));if(isset($x['error']) || ($x['status']??'')!=='SMTP_HANDOFF_CERTIFIED')WP_CLI::error('CERTIFICATION_NOT_ACCEPTED');return; }
  WP_CLI::error('Use status or certify');
 }
 public static function boot():void {
  // Do not invalidate independent transport verifiers already enrolled by
  // another company: this site-scoped evidence is additive, not exclusive.
  add_filter('mad4b/company_communication/smtp_verified',static fn($v)=>(bool)$v || self::verified(),PHP_INT_MAX);
  if(defined('WP_CLI') && WP_CLI)WP_CLI::add_command('mad4b company-mail',[__CLASS__,'cli']);
 }
}
add_action('init',[MAD4B_Company_Mail_Readiness_V1::class,'boot'],5);

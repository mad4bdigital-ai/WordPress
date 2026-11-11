<?php
/** Isolated Lab QA access. Tokens are short-lived, single-use, and sent by POST. */
if (!defined('ABSPATH')) return;
if (wp_get_environment_type()==='staging') {
 /**
  * Explicit, short-lived and one-use QA browser login for ONE enrolled
  * user on the exact central STAGING installation. CLI is the only issuer.
  * Links are issued only by VPS WP-CLI. No password, no public issuance route.
  */
 final class MAD4B_Staging_QA_Browser_Magic_V1 {
  const USER_ID=25;
  const LOGIN='lab_jetengine_qa';
  const ORIGIN='https://tours.dev.mad4b.com';
  const TTL=600;
  private static function central():bool {
   return wp_get_environment_type()==='staging'
    && defined('DB_NAME') && DB_NAME==='etg_commerce_staging'
    && untrailingslashit(home_url('/'))===self::ORIGIN
    && class_exists('MAD4B_SCP_Site_Profile',false)
    && MAD4B_SCP_Site_Profile::origin_enrolled()
    && MAD4B_SCP_Site_Profile::site_urls_match_enrollment();
  }
  private static function qa() {
   $user=get_user_by('id',self::USER_ID);
   return $user && $user->user_login===self::LOGIN
    && count((array)$user->roles)===1
    && in_array('administrator',(array)$user->roles,true)
    && MAD4B_SCP_Site_Profile::user_is_enrolled(self::USER_ID)
    ?$user:false;
  }
  private static function key(string $token):string {
   return 'mad4b_staging_qa_once_'.hash('sha256',$token);
  }
  private static function issue():string {
   if(!self::central()||!self::qa())throw new RuntimeException('QA_STAGING_SCOPE_NOT_ADMITTED');
   $token=bin2hex(random_bytes(32));
   $record=['contract'=>'mad4b.staging-qa-browser-once.v1',
    'site_uuid'=>MAD4B_SCP_Site_Profile::site_uuid(),
    'user_id'=>self::USER_ID,'created'=>time(),'expires'=>time()+self::TTL,
    'purpose'=>'qa_browser_login'];
   if(!add_option(self::key($token),$record,'',false))
    throw new RuntimeException('QA_TICKET_CREATION_FAILED');
   return self::ORIGIN.'/?mad4b_staging_qa_once=1#'.$token;
  }
  public static function cli($args,$assoc):void {
   if(!defined('WP_CLI')||!WP_CLI||!self::central())
    WP_CLI::error('QA_STAGING_CLI_SCOPE_REQUIRED');
   $mode=$args[0]??'status';
   if($mode==='status'){
    WP_CLI::log(wp_json_encode(['scope'=>'staging-central-only',
     'user_id'=>self::USER_ID,'qa_user_valid'=>(bool)self::qa(),
     'ttl_seconds'=>self::TTL,'one_use'=>true,
     'password_required'=>false]));
    return;
   }
   if($mode==='issue'){
    if(($assoc['user-id']??'')!=='25')WP_CLI::error('QA_EXACT_USER_25_REQUIRED');
    // Print the secret ONLY to stdout: caller must redirect into a
    // root-owned ephemeral file, never paste the link into chat or logs.
    WP_CLI::line(self::issue());
    return;
   }
   WP_CLI::error('UNKNOWN_QA_STAGING_COMMAND');
  }
  public static function browser():void {
   if(!isset($_GET['mad4b_staging_qa_once']) && !isset($_POST['mad4b_staging_qa_token']))return;
   nocache_headers();
   // The same-origin form POST must retain a real Origin header.\n   header('Referrer-Policy: same-origin');
   header('X-Content-Type-Options: nosniff');
   if(!self::central() || !is_ssl() || !self::qa()){
    status_header(403);exit('QA access disabled');
   }
   if(isset($_GET['mad4b_staging_qa_once'])) {
    if(($_SERVER['REQUEST_METHOD']??'')!=='GET'){status_header(405);exit;}
    header('Content-Type: text/html; charset=utf-8');
    header("Content-Security-Policy: default-src 'none'; script-src 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
    echo '<!doctype html><meta name="robots" content="noindex"><title>Staging QA one-time login</title><form method="post" action="/" id="qa"><input type="hidden" name="mad4b_staging_qa_token" id="qa-token"></form><script>const t=location.hash.slice(1);history.replaceState(null,"",location.pathname+location.search);if(/^[a-f0-9]{64}$/.test(t)){document.getElementById("qa-token").value=t;document.getElementById("qa").submit()}else document.body.textContent="Invalid or expired QA ticket";</script>';
    exit;
   }
   if(($_SERVER['REQUEST_METHOD']??'')!=='POST'
      || !is_string($_POST['mad4b_staging_qa_token'])
      || !preg_match('/^[a-f0-9]{64}$/D',$_POST['mad4b_staging_qa_token'])
      || ($_SERVER['HTTP_ORIGIN']??'')!==self::ORIGIN) {
    status_header(403);exit('Invalid QA token');
   }
   $token=(string)$_POST['mad4b_staging_qa_token'];
   $key=self::key($token);
   $record=get_option($key,false);
   if(!is_array($record)
      || ($record['contract']??'')!=='mad4b.staging-qa-browser-once.v1'
      || ($record['user_id']??0)!==self::USER_ID
      || ($record['purpose']??'')!=='qa_browser_login'
      || ($record['created']??0)<time()-self::TTL
      || ($record['expires']??0)<time()
      || ($record['site_uuid']??'')!==MAD4B_SCP_Site_Profile::site_uuid()) {
    status_header(403);exit('Expired QA token');
   }
   // Atomic compare-and-delete prevents replay even under concurrent POSTs.
   global $wpdb;
   $removed=$wpdb->delete($wpdb->options,
     ['option_name'=>$key,'option_value'=>maybe_serialize($record)],
     ['%s','%s']);
   wp_cache_delete($key,'options');
   if($removed!==1){status_header(403);exit('QA token already used');}
   $user=self::qa();
   if(!$user){status_header(403);exit('QA user unavailable');}
   wp_set_current_user($user->ID);
   wp_set_auth_cookie($user->ID,false,true);
   wp_safe_redirect(admin_url());
   exit;
  }
 }
 add_action('init',['MAD4B_Staging_QA_Browser_Magic_V1','browser'],1);
 if(defined('WP_CLI')&&WP_CLI&&class_exists('WP_CLI')){
  WP_CLI::add_command('mad4b qa-browser',['MAD4B_Staging_QA_Browser_Magic_V1','cli']);
 }
 return;
}
if (wp_get_environment_type() !== 'local') return;
if (!get_option('etg_lab_qa_magic_link_enabled',false)) return;
add_action('init',static function(){
 if(isset($_GET['etg_lab_qa_landing'])){
  nocache_headers();header('Referrer-Policy: no-referrer');header('Content-Type: text/html; charset=utf-8');
  echo '<!doctype html><meta name="robots" content="noindex"><title>Lab QA access</title><form method="post" action="/" id="access"><input type="hidden" name="etg_lab_qa_token" id="key"></form><script>const t=location.hash.slice(1);history.replaceState(null,"",location.pathname+location.search);if(/^[a-f0-9]{64}$/.test(t)){document.getElementById("key").value=t;document.getElementById("access").submit()}else{document.body.textContent="Invalid or expired link"}</script>';
  exit;
 }
 if(!isset($_POST['etg_lab_qa_token'])||!is_string($_POST['etg_lab_qa_token']))return;
 nocache_headers();$token=(string)$_POST['etg_lab_qa_token'];
 if(!preg_match('/^[a-f0-9]{64}$/D',$token)){status_header(403);exit;}
 $key='etg_qa_magic_'.hash('sha256',$token);$record=get_transient($key);
 if(!is_array($record)||empty($record['user_id'])){status_header(403);exit;}
 delete_transient($key);
 $user=get_user_by('id',(int)$record['user_id']);
 if(!$user||$user->user_login!=='lab_jetengine_qa'||!in_array('administrator',(array)$user->roles,true)){status_header(403);exit;}
 wp_set_current_user($user->ID);wp_set_auth_cookie($user->ID,false,is_ssl());wp_safe_redirect(admin_url());exit;
},1);

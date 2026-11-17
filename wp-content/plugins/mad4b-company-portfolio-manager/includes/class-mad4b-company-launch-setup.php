<?php
/**
 * Plugin Name: MAD4B — Tourism Launch Settings
 * Description: Governed admin-only onboarding of providers, brand setup, hosted trials and launch evidence.
 * Version: 1.0.0
 * No provider mutation, commercial activation or auto-publication from saving settings.
 */
defined('ABSPATH') || exit;
final class MAD4B_Tourism_Launch_Setup {
    const PAGE = 'mad4b-tourism-launch-setup';
    const OPTION = 'mad4b_tourism_launch_setup_v1';
    const TRIALS = 'mad4b_tourism_company_trial_intents_v1';
    const ROOT = '/run/secrets/mad4b-company-providers';
    const CATALOG = '/run/mad4b-company-hub-catalog.json';
    const ENDPOINT = 'https://coolify.infra.mad4b.com';
    const LANG = array('en','ar','fr','de','it','es');
    const CURRENCY = array('USD','EUR','GBP','EGP','AED','SAR');
    private static function site() {
        // The site-bound central authority comes from the original Core
        // Site Profile and host ledger through Company Management.
        return class_exists('MAD4B_Company_Portfolio_Manager_V1',false)
            && MAD4B_Company_Portfolio_Manager_V1::staging();
    }
    public static function boot() {
        if (!self::site()) return;
        add_action('admin_menu', array(__CLASS__, 'menu'), 99);
        add_action('admin_post_mad4b_launch_setup_save',array(__CLASS__,'save'));
        add_action('admin_post_mad4b_launch_setup_test',array(__CLASS__,'test'));
        add_filter('mad4b_company_cloudflare_provider_settings',array(__CLASS__,'cf_settings'));
    }
    public static function menu() {
        add_management_page('MAD4B — Launch Setup','MAD4B Launch Setup',
            'manage_options',self::PAGE,array(__CLASS__,'screen'));
    }
    private static function cap() {
        if (!self::site() || !current_user_can('manage_options') ||
            !current_user_can('activate_plugins')) wp_die('Owner administrator capability required.',403);
    }
    private static function go($status,$trial_company='') {
        $url=admin_url('tools.php?page='.self::PAGE.'&result='.rawurlencode($status));
        if(is_string($trial_company) && preg_match('/^[a-z0-9_-]{1,120}$/D',$trial_company))
            $url=add_query_arg('trial_company',$trial_company,$url);
        wp_safe_redirect($url,303);
        exit;
    }
    private static function safe_token($value) {
        $value=trim((string)$value);
        return strlen($value)>=20 && strlen($value)<=512 &&
            (bool)preg_match('/^[A-Za-z0-9._~+\/|=-]{20,512}$/D',$value);
    }
    private static function path($kind) {
        if (!in_array($kind,array('cloudflare','coolify'),true)) return null;
        return self::ROOT.'/'.$kind.'-token';
    }
    private static function secret($kind) {
        $path=self::path($kind);
        if(!$path || !is_file($path) || is_link($path) || !is_readable($path)) return '';
        $value=trim((string)@file_get_contents($path));
        return self::safe_token($value)?$value:'';
    }
    private static function write_secret($kind,$value) {
        $dir=realpath(self::ROOT);
        $path=self::path($kind);
        if($dir!==self::ROOT || !$path || !self::safe_token($value) ||
           is_link($path) || !is_file($path) || !is_writable($dir) || !is_writable($path))
            return false;
        $tmp=tempnam($dir,'.provider-');
        if(!$tmp || is_link($tmp)) return false;
        chmod($tmp,0600);
        $written=file_put_contents($tmp,$value."\n",LOCK_EX);
        if($written===false || $written!==strlen($value)+1) {
            @unlink($tmp);return false;
        }
        if(!rename($tmp,$path)){@unlink($tmp);return false;}
        @chmod($path,0600);
        return self::secret($kind)===$value;
    }
    private static function option() {
        $x=get_option(self::OPTION,array());
        return is_array($x)?$x:array();
    }
    private static function cf_id($x) {
        $x=strtolower(trim((string)$x));
        return preg_match('/^[a-f0-9]{32}$/D',$x)?$x:'';
    }
    public static function cf_settings($s) {
        if(!self::site() || !is_array($s)) return $s;
        $v=self::option();
        foreach(array('zone_id','account_id','origin_ipv4') as $key) {
            if(!empty($v[$key]))$s[$key]=$v[$key];
        }
        // Promote ONLY independently approved, auditable company registry
        // entries into the pre-existing Cloudflare domain adapter. A matching
        // hostname or a previously registered Site Profile is not permission
        // to mutate DNS on its own.
        $central=get_option('mad4b_tourism_company_site_registry_v1',array());
        if(is_array($central)) {
            if(!isset($s['registry']) || !is_array($s['registry']))$s['registry']=array();
            foreach($central as $code=>$entry) {
                if(!is_string($code) || !preg_match('/^[a-z][a-z0-9-]{2,47}$/D',$code) ||
                   !is_array($entry) || isset($s['registry'][$code]) ||
                   ($entry['company_code']??'')!==$code ||
                   empty($entry['host_binding_verified']) ||
                   empty($entry['dns_authority_approved']) ||
                   !preg_match('/^[a-f0-9]{64}$/D',(string)($entry['dns_approval_receipt_sha256']??'')) ||
                   !preg_match('/^[a-f0-9]{64}$/D',(string)($entry['native_profile_digest']??'')) ||
                   !wp_is_uuid((string)($entry['native_site_profile_uuid']??'')) ||
                   !wp_is_uuid((string)($entry['company_site_uuid']??'')) ||
                   !preg_match('/^[a-zA-Z0-9-]{8,65}$/D',(string)($entry['provider_resource_uuid']??'')) ||
                   wp_parse_url((string)($entry['canonical_origin']??''),PHP_URL_SCHEME)!=='https') {
                   continue;
                }
                $host=wp_parse_url($entry['canonical_origin'],PHP_URL_HOST);
                if(!is_string($host) ||
                   !MAD4B_SCP_Company_Domain_Cloudflare::valid_host($host))continue;
                $s['registry'][$code]=array(
                    'production_admitted'=>true,
                    'origin_host'=>$host,
                    'approved_custom_domains'=>
                        array_values(array_filter((array)($entry['approved_custom_domains']??array()),
                            array('MAD4B_SCP_Company_Domain_Cloudflare','valid_host'))),
                );
            }
        }
        return $s;
    }
    /** Optimistic concurrency only for fields actually edited by this form. */
    private static function provider_fingerprint(array $settings) {
        $scope=array();
        foreach(array('zone_id','account_id','origin_ipv4') as $key)
            $scope[$key]=(string)($settings[$key]??'');
        return hash('sha256',wp_json_encode($scope));
    }
    /** All input validation completes before any DB or secret-file write. */
    private static function provider_save_plan(array $existing,array $submitted) {
        foreach(array('zone_id','account_id','origin_ipv4','expected_provider_sha256') as $field)
            if(!array_key_exists($field,$submitted) || !is_scalar($submitted[$field]))
                return new WP_Error('provider_form_incomplete','Provider settings were incomplete; nothing was saved.');
        $digest=sanitize_text_field(wp_unslash((string)$submitted['expected_provider_sha256']));
        if(!preg_match('/^[a-f0-9]{64}$/D',$digest)
            ||!hash_equals(self::provider_fingerprint($existing),$digest))
            return new WP_Error('provider_settings_stale','Provider settings changed; reload the form.');
        $zone=trim((string)wp_unslash($submitted['zone_id']));
        $account=trim((string)wp_unslash($submitted['account_id']));
        $origin=trim((string)wp_unslash($submitted['origin_ipv4']));
        if($zone!=='' && self::cf_id($zone)==='')return new WP_Error('invalid_zone','Cloudflare zone ID is invalid.');
        if($account!=='' && self::cf_id($account)==='')return new WP_Error('invalid_account','Cloudflare account ID is invalid.');
        if($origin!=='' && !filter_var($origin,
            FILTER_VALIDATE_IP,FILTER_FLAG_IPV4|FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE))
            return new WP_Error('invalid_origin','Origin IPv4 is invalid.');
        $tokens=array();
        foreach(array('cloudflare','coolify') as $kind){
            $incoming=$submitted[$kind.'_token']??'';
            if(!is_scalar($incoming))return new WP_Error('provider_token_invalid','Token input must be a string.');
            $incoming=trim((string)wp_unslash($incoming));
            if($incoming!==''){
                if(!self::safe_token($incoming))
                    return new WP_Error($kind.'_token_invalid','Provider token has an invalid format.');
                $tokens[$kind]=$incoming;
            }
        }
        return array('zone_id'=>self::cf_id($zone),'account_id'=>self::cf_id($account),
            'origin_ipv4'=>$origin,'tokens'=>$tokens,
            'values_changed'=>(
                (string)($existing['zone_id']??'')!==self::cf_id($zone)
                ||(string)($existing['account_id']??'')!==self::cf_id($account)
                ||(string)($existing['origin_ipv4']??'')!==$origin));
    }
    /** Restore original secret bytes without leaking them to the database/log. */
    private static function restore_secret_bytes($kind,$original) {
        $path=self::path($kind);$dir=realpath(self::ROOT);
        if($dir!==self::ROOT || !$path || !is_string($original)
          || is_link($path) || !is_file($path) || !is_writable($dir))return false;
        $tmp=tempnam($dir,'.provider-restore-');
        if(!$tmp || is_link($tmp))return false;
        @chmod($tmp,0600);
        $written=@file_put_contents($tmp,$original,LOCK_EX);
        if($written===false || $written!==strlen($original) || !@rename($tmp,$path)){
            @unlink($tmp);return false;
        }
        @chmod($path,0600);
        $actual=@file_get_contents($path);
        return is_string($actual)&&hash_equals(hash('sha256',$original),hash('sha256',$actual));
    }
    /** No token is changed until every requested token has passed preflight. */
    private static function replace_provider_tokens(array $requested) {
        $originals=array();$updates=array();
        foreach($requested as $kind=>$value){
            $path=self::path($kind);$dir=realpath(self::ROOT);
            if($dir!==self::ROOT || !$path || !self::safe_token($value) ||
                is_link($path) || !is_file($path) || !is_readable($path) ||
                !is_writable($path) || !is_writable($dir))
                return new WP_Error('provider_secret_preflight_failed',
                    'A provider credential cannot be updated on this host.');
            $raw=@file_get_contents($path);
            if(!is_string($raw))return new WP_Error('provider_secret_read_failed',
                'Existing credential could not be inspected for rollback.');
            if(hash_equals(trim($raw),$value))continue;
            $originals[$kind]=$raw;
            $updates[$kind]=$value;
        }
        $applied=array();
        foreach($updates as $kind=>$value){
            if(!self::write_secret($kind,$value)){
                $rollback_ok=true;
                foreach(array_unique(array_merge($applied,array($kind))) as $changed){
                    if(!self::restore_secret_bytes($changed,$originals[$changed]))
                        $rollback_ok=false;
                }
                return new WP_Error($rollback_ok?'provider_secret_write_failed':'provider_secret_rollback_failed',
                    $rollback_ok?'Credential update failed; prior credentials were restored.':
                        'Credential update failed and rollback requires operator review.');
            }
            $applied[]=$kind;
        }
        return $originals; // in-memory only; never log or persist this snapshot.
    }
    private static function restore_provider_tokens(array $originals){
        $ok=true;
        foreach($originals as $kind=>$raw)
            if(!self::restore_secret_bytes($kind,$raw))$ok=false;
        return $ok;
    }

    /** Merge only submitted provider fields onto the newest saved state.
     * Independent verification results, governance settings, and opaque
     * company metadata must never be restored from the form's old snapshot.
     */
    private static function provider_merge_settings(array $fresh,array $plan,array $changed_tokens,int $actor) {
        $target=$fresh;
        foreach(array('zone_id','account_id','origin_ipv4') as $key)
            $target[$key]=$plan[$key];
        if((string)($fresh['zone_id']??'')!==$plan['zone_id']){
            if(!isset($target['checks'])||!is_array($target['checks']))$target['checks']=array();
            if(!isset($target['checks']['cloudflare'])||!is_array($target['checks']['cloudflare']))
                $target['checks']['cloudflare']=array();
            $target['checks']['cloudflare']['zone_access_status']='not_tested';
            $target['checks']['cloudflare']['dns_read_status']='not_tested';
        }
        foreach($changed_tokens as $kind=>$token)
            $target['checks'][$kind]=array('status'=>'not_tested','reason'=>'token_changed');
        $target['updated_at']=gmdate('c');
        $target['updated_by']=(int)$actor;
        return $target;
    }

    /** Compare only governance inputs, not provider-health settings. */
    private static function governance_fingerprint(array $state) {
        $scope=array();
        foreach(array('license_evidence','policy_review_reference','payment_review_reference','payment_mode') as $key)
            $scope[$key]=$state[$key]??($key==='payment_mode'?'none':'');
        $scope['privacy_page_id']=(int)get_option('wp_page_for_privacy_policy',0);
        return hash('sha256',wp_json_encode($scope));
    }
    /** A single mutex for all writers of the launch-settings WordPress Option.
     * Never hold this mutex during a remote network verification.
     */
    private static function with_settings_lock(callable $action) {
        global $wpdb;
        $lock='mad4b_company_launch_'.md5(DB_NAME.'|'.$wpdb->prefix);
        if((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,3)',$lock))!==1)
            return 'launch_settings_busy';
        try{
            // MySQL GET_LOCK serializes requests, but a waiting PHP process
            // may have loaded the option before acquiring the mutex. Discard
            // process-local and persistent WordPress Option cache entries
            // before each critical read/merge/write section.
            wp_cache_delete(self::OPTION,'options');
            wp_cache_delete('alloptions','options');
            wp_cache_delete('notoptions','options');
            return $action();
        }finally{
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));
        }
    }

    private static function save_provider_settings(array $input) {
        return self::with_settings_lock(static function() use($input){
            $previous=self::option();
            $plan=self::provider_save_plan($previous,$input);
            if(is_wp_error($plan))return $plan->get_error_code();
            $changed_tokens=array();
            foreach($plan['tokens'] as $kind=>$token)
                if(!hash_equals(self::secret($kind),$token))$changed_tokens[$kind]=$token;
            if(!$plan['values_changed'] && !$changed_tokens)
                return 'providers_already_saved';
            $snapshots=self::replace_provider_tokens($changed_tokens);
            if(is_wp_error($snapshots))return $snapshots->get_error_code();
            $target=self::provider_merge_settings($previous,$plan,$changed_tokens,get_current_user_id());
            update_option(self::OPTION,$target,false);
            if(self::option()!==$target){
                $restored=self::restore_provider_tokens($snapshots);
                if(self::option()!==$previous){
                    update_option(self::OPTION,$previous,false);
                    if(self::option()!==$previous)return 'provider_settings_rollback_failed';
                }
                return $restored?'provider_settings_save_failed':'provider_secret_rollback_failed';
            }
            return 'providers_saved';
        });
    }

    /** Never replace a stale settings snapshot after remote provider calls. */
    private static function save_provider_verification($provider,$token,$zone,array $entry,$successful) {
        return self::with_settings_lock(static function() use($provider,$token,$zone,$entry,$successful){
            if(!hash_equals($token,self::secret($provider)))
                return 'provider_token_changed_during_test';
            $fresh=self::option();
            if($provider==='cloudflare' && self::cf_id($fresh['zone_id']??'')!==$zone)
                return 'provider_scope_changed_during_test';
            if(!isset($fresh['checks'])||!is_array($fresh['checks']))$fresh['checks']=array();
            $fresh['checks'][$provider]=$entry;
            update_option(self::OPTION,$fresh,false);
            $saved=self::option();
            if(!is_array($saved['checks'][$provider]??null)
                || $saved['checks'][$provider]!==$entry)
                return 'provider_test_readback_failed';
            return $provider.'_'.($successful?'verified':'not_verified');
        });
    }

    /** Per-company preference CAS inside an option-scoped mutex. Do not
     * overwrite choices loaded from a different company or older page.
     */
    private static function persist_trial_intent($company,$expected,array $intent,$option_key=self::TRIALS) {
        global $wpdb;
        $lock='mad4b_company_trial_'.md5(DB_NAME.'|'.$wpdb->prefix.'|'.$option_key);
        if((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,3)',$lock))!==1)
            return 'trial_preferences_busy';
        try{
            wp_cache_delete($option_key,'options');
            wp_cache_delete('alloptions','options');
            wp_cache_delete('notoptions','options');
            $records=get_option($option_key,[]);
            if(!is_array($records))return 'trial_registry_invalid';
            $previous=$records[$company]??[];
            if(!is_array($previous))return 'trial_registry_invalid';
            if(!is_string($expected) || !preg_match('/^[a-f0-9]{64}$/D',$expected)
                || !hash_equals(hash('sha256',wp_json_encode($previous)),$expected))
                return 'trial_preferences_stale';
            $fields=['company_site_uuid','native_site_profile_uuid','source_catalog_sha256',
                'tours','languages','currencies'];
            $same=(bool)$previous;
            foreach($fields as $field)
                if(($previous[$field]??null)!==($intent[$field]??null)){$same=false;break;}
            if($same)return 'trial_preferences_already_saved';
            $records[$company]=$intent;
            update_option($option_key,$records,false);
            $saved=get_option($option_key,[]);
            if(!is_array($saved)||$saved!==$records ||
                ($saved[$company]??null)!==$intent)
                return 'trial_preferences_readback_failed';
            return 'trial_preference_saved_no_publication';
        }finally{
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));
        }
    }

    public static function save() {
        self::cap();
        check_admin_referer('mad4b_launch_setup_save');
        if(strtoupper((string)($_SERVER['REQUEST_METHOD']??''))!=='POST')self::go('invalid_method');
        $section=sanitize_key((string)($_POST['section']??''));
        if($section==='providers')
            self::go(self::save_provider_settings($_POST));
        if($section==='governance'){
            $status=self::with_settings_lock(static function(){
            $v=self::option();
            $expected=sanitize_text_field(wp_unslash((string)($_POST['expected_governance_sha256']??'')));
            if(!preg_match('/^[a-f0-9]{64}$/D',$expected) ||
               !hash_equals(self::governance_fingerprint($v),$expected))
                return 'governance_stale_reload';
            foreach(array('license_evidence','policy_review_reference','payment_review_reference','payment_mode','privacy_page_id') as $required)
                if(!array_key_exists($required,$_POST))return 'governance_form_incomplete';
            $pid=(int)($_POST['privacy_page_id']??0);
            if($pid>0) {
                $p=get_post($pid);
                if(!$p || $p->post_type!=='page'||$p->post_status!=='publish')
                    return 'privacy_page_must_be_published';
            }
            // Validate ALL governance input before writing either WordPress
            // option; an invalid payment mode must not partially move the
            // site's published privacy policy assignment.
            $mode=sanitize_key((string)($_POST['payment_mode']??'none'));
            if(!in_array($mode,array('none','manual_inquiry','sandbox_review','provider_integration_pending'),true))
                return 'payment_mode_invalid';
            $old_governance=$v;
            $old_privacy=(int)get_option('wp_page_for_privacy_policy',0);
            foreach(array('license_evidence','policy_review_reference','payment_review_reference') as $k) {
                $v[$k]=substr(sanitize_textarea_field(wp_unslash((string)($_POST[$k]??''))),0,3500);
            }
            $v['payment_mode']=$mode;
            $v['governance_updated_at']=gmdate('c');
            $v['governance_actor']=get_current_user_id();
            $v['license_admission_granted']=false;
            $v['commercial_booking_approved']=false;
            $v['automatic_launch_granted']=false;
            // Recheck the fingerprint immediately before the first write to
            // reject forms that went stale while validating other fields.
            if(!hash_equals(self::governance_fingerprint($old_governance),$expected))
                return 'governance_stale_reload';
            update_option(self::OPTION,$v,false);
            if(self::option()!==$v)return 'governance_readback_failed';
            if($pid>0 && $pid!==$old_privacy) {
                update_option('wp_page_for_privacy_policy',$pid,false);
                if((int)get_option('wp_page_for_privacy_policy',0)!==$pid) {
                    // Compensate only the first change. Never report success
                    // if the two saved settings are inconsistent.
                    update_option(self::OPTION,$old_governance,false);
                    if(self::option()!==$old_governance)
                        return 'governance_rollback_failed';
                    return 'privacy_page_save_failed';
                }
            }
            return 'governance_saved_pending_certification';
            });
            self::go($status);
        }
        if($section==='trial') {
            $company=sanitize_key((string)($_POST['company_code']??''));
            $reg=get_option('mad4b_tourism_company_site_registry_v1',array());
            if(!is_array($reg)||!isset($reg[$company])||empty($reg[$company]['host_binding_verified']))self::go('company_not_enrolled');
            $cat=self::catalog();
            if(!is_array($cat))self::go('hub_catalog_unavailable');
            $allowed=array_column($cat['items'],'hub_ref');
            $tours=array_map('sanitize_key',(array)wp_unslash($_POST['tours']??array()));
            $languages=array_map('sanitize_key',(array)wp_unslash($_POST['languages']??array()));
            $currency=array_map('sanitize_text_field',(array)wp_unslash($_POST['currencies']??array()));
            if(count($tours)<1||count($tours)>12||count(array_unique($tours))!==count($tours)||
               count($languages)<1||count($currency)<1 ||
               count(array_diff($tours,$allowed)) ||
               count(array_diff($languages,self::LANG)) ||
               count(array_diff($currency,self::CURRENCY)))self::go('trial_selection_invalid');
            $expected=sanitize_text_field(wp_unslash((string)($_POST['expected_trial_sha256']??'')));
            $intent=array('contract'=>'mad4b.central-hosted-trial-intent.v1',
                'company_site_uuid'=>$reg[$company]['company_site_uuid'],
                'native_site_profile_uuid'=>$reg[$company]['native_site_profile_uuid'],
                'source_catalog_sha256'=>$cat['catalog_sha256'],
                'tours'=>array_values($tours),'languages'=>array_values($languages),
                'currencies'=>array_values($currency),
                'state'=>'selected_pending_licensed_content_distribution',
                'target_publication_authorized'=>false,
                'booking_activated'=>false,'created_at'=>gmdate('c'),
                'actor'=>get_current_user_id());
            $result=self::persist_trial_intent($company,$expected,$intent);
            self::go($result,$company);
        }
        self::go('unknown_section');
    }
    public static function catalog() {
        if(!is_readable(self::CATALOG)||is_link(self::CATALOG)||filesize(self::CATALOG)>700000)return null;
        $x=json_decode((string)file_get_contents(self::CATALOG),true);
        if(!is_array($x)||($x['contract']??'')!=='mad4b.content-hub.hosted-trial-discovery.v1'||
            ($x['distribution_status']??'')!=='NOT_AUTHORIZED_FOR_TENANT_PUBLICATION' ||
            !is_array($x['items']??null) || count($x['items'])>300)return null;
        $hash=hash('sha256',wp_json_encode($x['items'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
        return hash_equals((string)($x['catalog_sha256']??''),$hash)?$x:null;
    }
    public static function test() {
        self::cap();
        check_admin_referer('mad4b_launch_setup_test');
        $provider=sanitize_key((string)($_POST['provider']??''));
        if(!in_array($provider,array('cloudflare','coolify'),true))self::go('provider_unknown');
        $token=self::secret($provider);
        if(!$token)self::go($provider.'_missing');
        // Coolify /api/v1/version answers plain text (for example 4.5.3).
        // Verify the token against a team-scoped JSON endpoint instead.
        $url=$provider==='cloudflare'?
            'https://api.cloudflare.com/client/v4/user/tokens/verify':
            self::ENDPOINT.'/api/v1/teams/current';
        $r=wp_safe_remote_get($url,array('timeout'=>14,'redirection'=>0,
            'headers'=>array('Authorization'=>'Bearer '.$token,'Accept'=>'application/json'),
            'limit_response_size'=>8192));
        $ok=false;$error='transport_error';
        if(!is_wp_error($r)) {
            $code=(int)wp_remote_retrieve_response_code($r);
            $body=json_decode((string)wp_remote_retrieve_body($r),true);
            if($provider==='cloudflare') {
                $ok=$code===200 && is_array($body) &&
                    !empty($body['success']) && ($body['result']['status']??'')==='active';
            } else {
                $ok=$code===200 && is_array($body) &&
                    isset($body['id']) && is_int($body['id']) && $body['id']>=0 &&
                    isset($body['name']) && is_string($body['name']) && trim($body['name'])!=='';
            }
            $error=$ok?'verified':($code===200?'unexpected_provider_response':'http_'.$code);
        }
        $v=self::option();
        if(!isset($v['checks'])||!is_array($v['checks']))$v['checks']=array();
        $entry=array('status'=>$ok?'verified':'failed',
            'reason'=>$error,'checked_at'=>gmdate('c'));
        if($provider==='cloudflare') {
            // Token activation alone does NOT establish permission to manage
            // this customer's zone. Test read-only zone and DNS access separately.
            $entry['zone_access_status']='not_checked';
            $entry['dns_read_status']='not_checked';
            $zone=self::cf_id($v['zone_id']??'');
            if(!$ok) {
                $entry['zone_access_status']='token_not_verified';
                $entry['dns_read_status']='token_not_verified';
            } elseif($zone==='') {
                $entry['zone_access_status']='zone_id_missing';
                $entry['dns_read_status']='zone_id_missing';
            } else {
                foreach(array('zone_access_status'=>'/zones/'.$zone,
                    'dns_read_status'=>'/zones/'.$zone.'/dns_records?per_page=1') as $field=>$path) {
                    $probe=wp_safe_remote_get('https://api.cloudflare.com/client/v4'.$path,
                        array('timeout'=>14,'redirection'=>0,
                        'headers'=>array('Authorization'=>'Bearer '.$token,'Accept'=>'application/json'),
                        'limit_response_size'=>12000));
                    if(is_wp_error($probe)) {
                        $entry[$field]='transport_error';
                        continue;
                    }
                    $http=(int)wp_remote_retrieve_response_code($probe);
                    $data=json_decode((string)wp_remote_retrieve_body($probe),true);
                    $is_valid=$http===200 && is_array($data) && !empty($data['success']);
                    if($field==='zone_access_status' && $is_valid) {
                        $is_valid=($data['result']['name']??'')==='mad4b.com';
                        if(!$is_valid){$entry[$field]='zone_name_mismatch';continue;}
                    }
                    $entry[$field]=$is_valid?'verified':'http_'.$http;
                }
            }
        }
        // Re-read fresh settings under the same mutex as provider and
        // governance saves. Reject rotated tokens or changed Cloudflare zone;
        // merge ONLY the checked provider result into the latest record.
        $status=self::save_provider_verification($provider,$token,
            $provider==='cloudflare'?$zone:'',$entry,$ok);
        self::go($status);
    }
    private static function escval($v) {return esc_attr((string)$v);}
    private static function section_start($name) {
        echo '<div class="card" style="max-width:1050px;padding:16px 24px;margin:16px 0"><h2>'.esc_html($name).'</h2>';
    }
    private static function section_end() {echo '</div>';}
    /** Human-readable result codes; never reveal provider secret values. */
    private static function save_feedback($status) {
        $status=sanitize_key((string)$status);
        $messages=array(
          'providers_saved'=>'Provider settings saved and verified. Current values are displayed below.',
          'providers_already_saved'=>'Provider settings are already saved; no changes were made.',
          'governance_saved_pending_certification'=>'Governance references saved and verified. This is not a license or launch approval.',
          'trial_preference_saved_no_publication'=>'Hosted trial preferences saved and verified. Nothing was published.',
          'cloudflare_verified'=>'Cloudflare credential verification completed and its result was saved.',
          'coolify_verified'=>'Coolify credential verification completed and its result was saved.',
          'provider_settings_stale'=>'Provider settings changed after the form was opened. Reload and review before saving.',
          'provider_form_incomplete'=>'Some provider settings were missing from the form; previous values were preserved.',
          'provider_secret_preflight_failed'=>'The provider credential file is not ready for a safe update. No new credentials were saved.',
          'provider_secret_write_failed'=>'Credential update failed; rollback of prior credentials was attempted.',
          'provider_secret_rollback_failed'=>'Credential rollback could not be fully verified. Operator review is required.',
          'provider_settings_save_failed'=>'Provider settings could not be saved and verified. Review the current saved values.',
          'provider_settings_rollback_failed'=>'Provider settings rollback failed. Operator review is required.',
          'provider_test_readback_failed'=>'Provider verification result could not be read back. Verification was not recorded as complete.',
          'provider_token_changed_during_test'=>'Provider credential changed while testing. The old test result was not recorded.',
          'provider_scope_changed_during_test'=>'The Cloudflare zone changed during verification. The old result was not recorded.',
          'launch_settings_busy'=>'Another settings update is in progress. Try saving again.',
          'governance_stale_reload'=>'Governance settings changed after the form was opened. Reload before saving.',
          'governance_form_incomplete'=>'Some governance fields were missing; previous settings were preserved.',
          'governance_readback_failed'=>'Governance settings could not be read back after saving.',
          'privacy_page_save_failed'=>'The privacy page assignment could not be saved; governance settings were restored.',
          'privacy_page_must_be_published'=>'Choose a published privacy policy page before saving.',
          'payment_mode_invalid'=>'The payment mode is not supported. No governance change was saved.',
          'trial_preferences_readback_failed'=>'Hosted trial preferences could not be read back after saving.',
          'trial_preferences_stale'=>'The selected company preferences changed. Reload that company before saving.',
          'trial_preferences_busy'=>'Another hosted preferences save is in progress. Retry shortly.',
          'trial_preferences_already_saved'=>'These company content preferences are already saved; nothing changed.',
          'cloudflare_not_verified'=>'Cloudflare verification did not succeed; review the provider diagnostics.',
          'coolify_not_verified'=>'Coolify verification did not succeed; review the provider diagnostics.'
        );
        $success=array('providers_saved','providers_already_saved',
          'governance_saved_pending_certification',
          'trial_preference_saved_no_publication','trial_preferences_already_saved',
          'cloudflare_verified','coolify_verified');
        $good=in_array($status,$success,true);
        return array('success'=>$good,'code'=>$status,
          'message'=>$messages[$status]??('The operation was not confirmed: '.$status));
    }
    private static function form_start($section) {
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('mad4b_launch_setup_save');
        echo '<input type="hidden" name="action" value="mad4b_launch_setup_save"><input type="hidden" name="section" value="'.esc_attr($section).'">';
    }
    public static function screen() {
        self::cap();
        $s=self::option();$cat=self::catalog();
        $cf=self::secret('cloudflare')!=='';$cool=self::secret('coolify')!=='';
        $checks=$s['checks']??array();
        echo '<div class="wrap"><h1>MAD4B — Tourism Company Launch Setup</h1>';
        echo '<p>One central setup page. Saving credentials or references does not activate DNS, publish content, create sites, or approve checkout.</p>';
        if(isset($_GET['result'])){
            $feedback=self::save_feedback(wp_unslash((string)$_GET['result']));
            echo '<div class="notice '.($feedback['success']?'notice-success':'notice-error')
                .'" role="'.($feedback['success']?'status':'alert').'"><p>'
                .esc_html($feedback['message'])
                .' <code>'.esc_html($feedback['code']).'</code></p></div>';
        }
        echo '<p><a href="'.esc_url(admin_url('tools.php?page=mad4b-tourism-company-launch')).'">Company applications and owner review</a> · ';
        echo '<a href="'.esc_url(admin_url('options-privacy.php')).'">WordPress privacy settings</a> · ';
        echo '<a href="'.esc_url(admin_url('admin.php?page=mad4b-company-policy-library')).'">JetEngine customer policies</a></p>';
        self::section_start('1 — Provider credentials: Coolify + Cloudflare');
        echo '<p><strong>Secrets are written outside the web root.</strong> Blank token fields leave existing values unchanged. Never paste credentials into content or chat.</p>';
        self::form_start('providers');
        echo '<input type="hidden" name="expected_provider_sha256" value="'
            .esc_attr(self::provider_fingerprint($s)).'">';
        echo '<table class="form-table"><tbody>';
        foreach(array('coolify'=>'Coolify API token','cloudflare'=>'Cloudflare API token') as $name=>$title){
            $present=$name==='coolify'?$cool:$cf;
            $test=$checks[$name]??array();
            echo '<tr><th><label for="'.esc_attr($name).'_token">'.esc_html($title).'</label></th><td>';
            echo '<input id="'.esc_attr($name).'_token" type="password" name="'.esc_attr($name).'_token" value="" autocomplete="new-password" class="regular-text" placeholder="Enter a scoped token">';
            echo '<p class="description">Stored: '.($present?'Yes':'No').' · Last verification: '.esc_html($test['status']??'not tested').
              ' '.esc_html($test['checked_at']??'').'</p>';
            if($name==='cloudflare') {
                echo '<p class="description"><strong>Zone read:</strong> '.esc_html($test['zone_access_status']??'not tested').
                    ' · <strong>DNS records read:</strong> '.esc_html($test['dns_read_status']??'not tested').'</p>';
                if(($test['status']??'')==='verified' &&
                    (strpos((string)($test['zone_access_status']??''),'http_403')===0 ||
                    strpos((string)($test['dns_read_status']??''),'http_403')===0))
                    echo '<p><strong>Cloudflare token is active, but the selected zone denied read access (403). Review Zone Read / DNS Read + Edit permissions and restrict the token to mad4b.com.</strong></p>';
            }
            echo '</td></tr>';
        }
        foreach(array('zone_id'=>'Cloudflare zone ID (mad4b.com)','account_id'=>'Cloudflare account ID (optional)','origin_ipv4'=>'Origin public IPv4') as $k=>$title){
            echo '<tr><th><label>'.esc_html($title).'</label></th><td><input class="regular-text" name="'.esc_attr($k).'" value="'.self::escval($s[$k]??'').'"></td></tr>';
        }
        echo '</tbody></table>';
        submit_button('Save provider settings (no DNS changes)');
        echo '</form>';
        foreach(array('coolify'=>'Test Coolify token','cloudflare'=>'Test Cloudflare token') as $provider=>$label){
            echo '<form style="display:inline-block;margin-right:8px" method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
            wp_nonce_field('mad4b_launch_setup_test');
            echo '<input type="hidden" name="action" value="mad4b_launch_setup_test"><input type="hidden" name="provider" value="'.esc_attr($provider).'">';
            submit_button($label,'secondary','submit',false);
            echo '</form>';
        }
        echo '<p class="description">Coolify endpoint: '.esc_html(self::ENDPOINT).' (fixed). Cloudflare settings are made available to the existing MAD4B DNS adapter; its own per-company enrollment and owner approval gates remain active.</p>';
        echo '<p>Manage provider tokens: <a href="https://coolify.infra.mad4b.com/" target="_blank" rel="noopener noreferrer">Coolify</a> · <a href="https://dash.cloudflare.com/" target="_blank" rel="noopener noreferrer">Cloudflare Dashboard</a>.</p>';
        echo '<p class="description">Cloudflare: restrict the token to the relevant zone and request Zone Read and DNS Edit for governed domain onboarding. Coolify: use a separate, limited token with only the project/environment permissions needed by the provisioning workflow. Provider token verification alone does not prove those granular write permissions.</p>';
        self::section_end();
        self::section_start('2 — System package, plugin licensing and privacy policy');
        self::form_start('governance');
        echo '<input type="hidden" name="expected_governance_sha256" value="'
            .esc_attr(self::governance_fingerprint($s)).'">';
        echo '<p>Current code release: <code>wplab-code-5c3c93258c8b5b5cdd2d</code> — source only. Licensing and template review are still mandatory before production provisioning.</p>';
        echo '<p><label for="license_evidence"><strong>License evidence / provider approvals</strong></label></p>';
        echo '<textarea name="license_evidence" id="license_evidence" rows="5" style="width:100%" placeholder="Elementor Pro, WPML, JetEngine, JetSmartFilters, JetElements, WooCommerce extensions. Record each license holder, permitted usage, verification reference and expiry.">'.esc_textarea($s['license_evidence']??'').'</textarea>';
        $draft=get_page_by_path('mad4b-tourism-applications-privacy',OBJECT,'page');
        if($draft && $draft->post_status==='draft') {
            echo '<p><a href="'.esc_url(get_edit_post_link($draft->ID,'')).'"><strong>Edit the unpublished privacy policy draft</strong></a> — complete legal details and publish only after approval.</p>';
        }
        echo '<p><label for="privacy_page_id"><strong>Published Privacy Policy</strong></label></p>';
        echo '<select id="privacy_page_id" name="privacy_page_id"><option value="0">'
            .((int)get_option('wp_page_for_privacy_policy',0)>0
                ?'Keep the currently assigned privacy policy page'
                :'Choose an approved published page').'</option>';
        $pages=get_posts(array('post_type'=>'page','post_status'=>'publish','posts_per_page'=>250,'orderby'=>'title','order'=>'ASC'));
        foreach($pages as $p)echo '<option value="'.(int)$p->ID.'" '.selected((int)get_option('wp_page_for_privacy_policy'),(int)$p->ID,false).'>'.esc_html($p->post_title).'</option>';
        echo '</select>';
        echo '<p class="description">Public application intake remains blocked until a published Privacy Policy is selected; saving a reference does not constitute legal certification.</p>';
        echo '<h3>Commercial readiness references (no automatic activation)</h3>';
        foreach(array('policy_review_reference'=>'Approved company policies / legal review reference','payment_review_reference'=>'Payment provider and commercial certification evidence') as $k=>$label){
            echo '<p><label><strong>'.esc_html($label).'</strong><br><textarea rows="2" style="width:100%" name="'.esc_attr($k).'">'.esc_textarea($s[$k]??'').'</textarea></label></p>';
        }
        echo '<p><label>Payment mode <select name="payment_mode">';
        foreach(array('none'=>'Not configured','manual_inquiry'=>'Manual inquiry only','sandbox_review'=>'Sandbox review','provider_integration_pending'=>'Gateway integration pending') as $key=>$text){
            echo '<option value="'.esc_attr($key).'" '.selected($s['payment_mode']??'none',$key,false).'>'.esc_html($text).'</option>';
        }
        echo '</select></label></p>';
        submit_button('Save governance references — still pending certification');
        echo '</form>';
        self::section_end();
        self::section_start('3 — Hosted Content Hub: tours, languages and currencies');
        echo '<p>The Content Hub catalog is remote-source preview metadata. Selections do not import uploads, copy images, publish tours or activate bookings.</p>';
        $registry=get_option('mad4b_tourism_company_site_registry_v1',array());
        $trials=get_option(self::TRIALS,array());
        if(!is_array($registry)||!$registry||!is_array($cat)){
            echo '<p>Company enrollment or the read-only Content Hub catalog is not yet available.</p>';
        } else {
            $eligible=array();
            foreach($registry as $code=>$entry)
                if(!empty($entry['host_binding_verified']))$eligible[]=(string)$code;
            $requested=isset($_GET['trial_company'])
                ?sanitize_key(wp_unslash((string)$_GET['trial_company'])):'';
            $company=in_array($requested,$eligible,true)?$requested:($eligible[0]??'');
            if($company===''){
                echo '<p>No enrolled companies are available for hosted content preferences.</p>';
            }else{
            $stored=is_array($trials[$company]??null)?$trials[$company]:array();
            // This GET selector reloads the correct company's saved choices.
            // Changing it never submits another company's cached preferences.
            echo '<form method="get" action="'.esc_url(admin_url('tools.php')).'">';
            echo '<input type="hidden" name="page" value="'.esc_attr(self::PAGE).'">';
            echo '<p><label><strong>Load company preferences</strong> <select name="trial_company">';
            foreach($eligible as $code){
                echo '<option value="'.esc_attr($code).'" '.selected($company,$code,false).'>'
                    .esc_html($code).' — '.esc_html($registry[$code]['canonical_origin']??'').'</option>';
            }
            echo '</select></label> <button class="button" type="submit">Load selected company</button></p></form>';
            self::form_start('trial');
            echo '<input type="hidden" name="company_code" value="'.esc_attr($company).'">';
            echo '<input type="hidden" name="expected_trial_sha256" value="'
                .esc_attr(hash('sha256',wp_json_encode($stored))).'">';
            echo '<p>Editing hosted content preferences for <strong>'.esc_html($company).'</strong>.</p>';
            echo '<p><strong>Tours — choose up to 12 of '.count($cat['items']).' available groups</strong></p>';
            echo '<div style="background:#fff;border:1px solid #ccd0d4;padding:12px;max-height:280px;overflow-y:auto">';
            foreach($cat['items'] as $item){
                $langs=$item['languages'];
                $base=$langs['en']??reset($langs);
                echo '<p><label><input type="checkbox" name="tours[]" value="'.esc_attr($item['hub_ref']).'" '.checked(in_array($item['hub_ref'],$stored['tours']??array(),true),true,false).'> '.esc_html($base['title']??$item['hub_ref']).'</label> <a href="'.esc_url($base['source_preview_url']??'').'" target="_blank" rel="noopener noreferrer">Source preview</a></p>';
            }
            echo '</div><h3>Languages</h3>';
            foreach(self::LANG as $l)echo '<label style="display:inline-block;margin:0 12px 6px 0"><input type="checkbox" name="languages[]" value="'.esc_attr($l).'" '.checked(in_array($l,$stored['languages']??array('en'),true),true,false).'> '.esc_html(strtoupper($l)).'</label>';
            echo '<h3>Display currencies (no exchange rates or checkout)</h3>';
            foreach(self::CURRENCY as $v)echo '<label style="display:inline-block;margin:0 12px 6px 0"><input type="checkbox" name="currencies[]" value="'.esc_attr($v).'" '.checked(in_array($v,$stored['currencies']??array('USD'),true),true,false).'> '.esc_html($v).'</label>';
            submit_button('Save company content preferences (not publish)');
            echo '</form>';
            $public=MAD4B_Company_Portfolio_Manager_V1::company_public_routes((string)$company);
            if (!is_wp_error($public)) {
                echo '<p><a href="'.esc_url($public['admin_url']).'" target="_blank" rel="noopener noreferrer">'
                    .'Open '.esc_html($company).' WordPress administration</a> — public HTTPS only; company owner controls authentication.</p>';
            } else {
                echo '<p>Company administration link is unavailable until its verified public HTTPS origin is ready. '
                    .'Internal Docker/Coolify ports cannot be used for external login.</p>';
            }
            }
        }
        self::section_end();
        self::section_start('4 — Operational release gate');
        echo '<p><a href="'.esc_url(rest_url('mad4b/v1/tourism-launch/workflow')).'" target="_blank" rel="noopener noreferrer"><strong>REST workflow contract &amp; stages</strong></a> — owner-scoped readback, exact plans, audited execution and independent source / content admission.</p>';
        echo '<p><strong>Saving this setup never grants:</strong> production provisioning, domain mutation, licensed content distribution, live payments, or public Go-Live. Those require exact company plans, native Site Profile, signed audit and separate authorization.</p>';
        echo '<p><a href="'.esc_url(admin_url('tools.php?page=mad4b-tourism-company-launch')).'">Open owner request review workflow</a></p>';
        self::section_end();
        echo '</div>';
    }
}
MAD4B_Tourism_Launch_Setup::boot();

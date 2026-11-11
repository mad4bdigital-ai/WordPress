<?php
/**
 * Plugin Name: MAD4B Credential Provider Adapters
 * Description: Provider-specific nonsecret health evidence and synthetic secure staging canary.
 * Version: 0.3.0
 */
if(!defined('ABSPATH'))exit;
final class MAD4B_Credential_Provider_Adapters {
    const CONTRACT='mad4b.credential-provider-adapters.v1';
    const STORE='mad4b_credential_provider_staging_v1';
    const AUDIT='mad4b_credential_provider_stage_audit_v1';
    const MAX=60;
    const ROOT='/var/www/.mad4b-private-host/company-credentials';
    const STATES=['STAGED','VERIFIED','ACTIVE_CANARY','ROLLED_BACK','REVOKED'];
    public static function boot():void {
        add_action('rest_api_init',[__CLASS__,'routes']);
        add_action('wp_abilities_api_init',[__CLASS__,'abilities'],62);
        add_filter('mad4b/configuration_jobs/discover',[__CLASS__,'discover_cached_provider_failures'],25,1);
        add_filter('mad4b/job_routing/exact_rules',[__CLASS__,'routing_rules'],25,1);
    }
    private static function deny(string $reason,int $status=409):WP_Error {
        return new WP_Error('mad4b_adapter_'.$reason,'Credential adapter operation not admitted.',
            ['status'=>$status]);
    }
    private static function company():bool {
        return class_exists('MAD4B_Secure_Credential_Envelope') &&
               !MAD4B_Secure_Credential_Envelope::source();
    }
    private static function trusted():bool {
        return self::company() && MAD4B_Secure_Credential_Envelope::trusted_cli();
    }
    private static function uuid():string {
        $p=(array)get_option('mad4b_scp_site_profile_v2',[]);
        return (string)($p['site_uuid']??'');
    }
    public static function catalog():array {
        $maxmind=class_exists('WC_Geolocation') && function_exists('WC');
        $smtp=defined('FLUENTMAIL')||function_exists('fluentMail')||class_exists('FluentMail\\App\\Hooks\\Handlers\\SchedulerHandler');
        $stripe=class_exists('WC_Stripe')||class_exists('WC_Stripe_Payment_Gateway');
        $result=['contract'=>self::CONTRACT,'site_uuid'=>self::uuid(),'providers'=>[
           'mad4b_test_fixture'=>['available'=>self::company(),'mode'=>'test_fixture',
             'native_stage'=>true,'native_validation'=>'synthetic_only',
             'native_activation'=>'isolated_test_canary_only','production_enabled'=>false],
           'woocommerce_maxmind_geolite2'=>['available'=>$maxmind,
             'mode'=>'managed_dataset','native_stage'=>false,
             'native_validation'=>'local_database_and_licensed_source_receipt',
             'native_activation'=>'existing_verified_dataset_sync','production_key_materialization_enabled'=>false],
           'fluent_smtp'=>['available'=>$smtp || defined('FLUENTMAIL_PLUGIN_VERSION'),
             'mode'=>'company_owned','native_stage'=>false,'native_validation'=>'read_only_plugin_status',
             'native_activation'=>'requires_company_owned_provider_specific_adapter',
             'production_key_materialization_enabled'=>false],
           'stripe_payments'=>['available'=>$stripe,'mode'=>'company_owned',
             'native_stage'=>false,'native_validation'=>'read_only_gateway_status',
             'native_activation'=>'merchant_specific_keys_never_cloned',
             'production_key_materialization_enabled'=>false],
        ],'plain_secret_returned'=>false];
        $extended=apply_filters('mad4b/company_credentials/local_adapter_catalog',[]);
        foreach((array)$extended as $id=>$row){
            if(!is_string($id)||!preg_match('/^[a-z0-9_]{3,62}$/D',$id)||!is_array($row))continue;
            $result['providers'][$id]=[
                'available'=>(bool)($row['available']??false),
                'mode'=>'company_owned','license_kind'=>sanitize_key((string)($row['license_kind']??'plugin_license')),
                'native_stage'=>false,'native_validation'=>'independent_vendor_attestation_required',
                'native_activation'=>'provider_specific_entitlement_and_vendor_verification_required',
                'production_key_materialization_enabled'=>false,'vendor_license_account_owner_verified'=>false];
        }
        return $result;
    }
    public static function health():array {
        $out=[];
        if(class_exists('WC_Geolocation')){
            $db=WC_Geolocation::get_local_database_path();
            $receipt=(array)get_option('mad4b_maxmind_delegated_update_v1',[]);
            $valid=is_string($db)&&is_readable($db);
            $match=$valid&&is_string($receipt['sha256']??null)&&
                preg_match('/^[a-f0-9]{64}$/D',$receipt['sha256'])&&
                hash_equals($receipt['sha256'],hash_file('sha256',$db));
            $country='';
            if($valid){
                $result=(new WC_Geolocation())->geolocate_ip('8.8.8.8',true,false);
                $country=(string)($result['country']??'');
            }
            $integration=function_exists('WC')?WC()->integrations->get_integration('maxmind_geolocation'):null;
            $nativeKey=(bool)($integration && $integration->get_option('license_key'));
            $updater=function_exists('as_has_scheduled_action')?
               (bool)as_has_scheduled_action('woocommerce_geoip_updater',[],'woocommerce'):false;
            $out['woocommerce_maxmind_geolite2']=[
              'database_readable'=>$valid,'source_mirror_verified'=>(bool)$match,
              'source_license_receipt_confirmed'=>!empty($receipt['origin_license_present'])&&
                  !empty($receipt['origin_updater_pending']),
              'native_license_configured'=>$nativeKey,'native_updater_scheduled'=>$updater,
              'geoip_readback_test_passed'=>$country==='US',
              'has_current_source_receipt'=>!empty($receipt['verified_at'])&&
                  (int)$receipt['verified_at']>time()-2*HOUR_IN_SECONDS,
              'geolocation_provider_healthy'=>$valid,
              'vendor_secret_value_exposed'=>false];
        }
        if(defined('FLUENTMAIL')||function_exists('fluentMail')||defined('FLUENTMAIL_PLUGIN_VERSION')){
            $out['fluent_smtp']=self::cached_smtp_health()+[
              'production_key_installation_available'=>false,
              'provider_validation_required'=>true,
              'smtp_credential_exposed'=>false];
        }
        if(class_exists('WC_Payment_Gateways')){
            $gateways=WC()->payment_gateways();$all=is_object($gateways)?$gateways->payment_gateways():[];
            $hasStripe=false;foreach((array)$all as $id=>$g)
                if(str_contains(strtolower((string)$id),'stripe')){$hasStripe=true;break;}
            $out['stripe_payments']=['gateway_registered'=>$hasStripe,
                'merchant_credential_exposed'=>false,'cross_company_copy_permitted'=>false];
        }
        $extended=apply_filters('mad4b/company_credentials/local_adapter_health',[]);
        foreach((array)$extended as $id=>$row){
            if(!is_string($id)||!preg_match('/^[a-z0-9_]{3,62}$/D',$id)||!is_array($row))continue;
            $out[$id]=[
                'state'=>sanitize_key((string)($row['state']??'unverified')),
                'plugin_installed_and_active'=>(bool)($row['plugin_installed_and_active']??false),
                'local_license_key_present'=>(bool)($row['local_license_key_present']??false),
                'native_cached_valid'=>(bool)($row['native_cached_valid']??false),
                'native_cached_check_recent'=>(bool)($row['native_cached_check_recent']??false),
                'license_kind'=>sanitize_key((string)($row['license_kind']??'plugin_license')),
                'vendor_account_verified'=>false,'vendor_live_test_performed'=>false,
                'company_entitlement_verified'=>false,
                'cross_company_license_transfer_enabled'=>false,
                'secret_value_exposed'=>false];
        }
        return ['contract'=>self::CONTRACT,'site_uuid'=>self::uuid(),
            'providers'=>$out,'secret_values_included'=>false];
    }
    /** Cached-only reading. Does NOT send email or refresh provider OAuth. */
    private static function cached_smtp_health():array {
        $s=['plugin_available'=>false,'configured_connections'=>0,
            'cached_healthy'=>0,'cached_error'=>0,'cached_fresh_error'=>0,'cached_unknown'=>0,
            'cached_stale'=>0,'cached_report_total'=>0,'vendor_live_check_performed'=>false,
            'email_addresses_or_messages_returned'=>false];
        $model='FluentMail\App\Models\Settings';
        $healthClass='FluentMail\App\Services\ConnectionHealth';
        if(!class_exists($model)||!class_exists($healthClass))return $s;
        $s['plugin_available']=true;
        try {
            $connections=(new $model())->getConnections();
            $s['configured_connections']=is_array($connections)?count($connections):0;
            $report=(new $healthClass())->getReport();
            if(!is_array($report))return $s;
            foreach($report as $record){
                if(!is_array($record))continue;
                $status=(string)($record['status']??'');
                if($status==='healthy')$s['cached_healthy']++;
                elseif($status==='error')$s['cached_error']++;
                else $s['cached_unknown']++;
                $timestamp=is_string($record['checked_at']??null)?strtotime($record['checked_at'].' UTC'):false;
                $stale=!$timestamp||$timestamp<time()-48*HOUR_IN_SECONDS||$timestamp>time()+300;
                if($stale)$s['cached_stale']++;
                if($status==='error'&&!$stale)$s['cached_fresh_error']++;
                $s['cached_report_total']++;
            }
        } catch (\Throwable $e) {
            // Do not return exception text; it can contain a credential or PII.
            $s['status']='PROVIDER_REPORT_UNAVAILABLE';
        }
        return $s;
    }
    public static function qualification():array {
        $health=self::health();
        $all=(array)($health['providers']??[]);
        $results=[];
        $geo=(array)($all['woocommerce_maxmind_geolite2']??[]);
        if($geo){
            $local=!empty($geo['database_readable']);
            $mirror=!empty($geo['source_mirror_verified'])&&!empty($geo['has_current_source_receipt'])
                 &&!empty($geo['source_license_receipt_confirmed'])&&!empty($geo['geoip_readback_test_passed']);
            $native=!empty($geo['native_license_configured'])&&!empty($geo['native_updater_scheduled']);
            $results['woocommerce_maxmind_geolite2']=[
              'state'=>!$local?'DATABASE_UNAVAILABLE':($mirror?'LOCAL_MANAGED_DATASET_VERIFIED':
                  ($native?'NATIVE_SOURCE_UPDATE_CONFIGURED':'UPDATE_PROVENANCE_UNVERIFIED')),
              'independent_local_readback'=>$local&&(!empty($geo['geoip_readback_test_passed'])),
              'central_grant_verification_required'=>true,
              'direct_vendor_key_installation_allowed'=>false];
        }
        $smtp=(array)($all['fluent_smtp']??[]);
        if($smtp){
            $protocol=class_exists('MAD4B_Fluent_SMTP_Sandbox_Validator')
                ?MAD4B_Fluent_SMTP_Sandbox_Validator::status():[];
            $sandboxPassed=!empty($protocol['sandbox_protocol_test_verified']);
            $results['fluent_smtp']=[
              'state'=>(int)($smtp['cached_fresh_error']??0)>0?'CACHED_PLUGIN_HEALTH_ERROR':
                ((int)($smtp['cached_stale']??0)>0?'STALE_CACHED_HEALTH_UNVERIFIED':
                ((int)($smtp['configured_connections']??0)>0?'CONFIGURED_VENDOR_CHECK_NOT_ATTESTED':'NO_CONNECTIONS_CONFIGURED')),
              'cached_report_observed'=>(int)($smtp['cached_report_total']??0)>0,
              'sandbox_smtp_auth_protocol_state'=>$sandboxPassed
                ?'SANDBOX_AUTH_ACCEPT_AND_REJECT_PASS_VENDOR_UNVERIFIED'
                :'SANDBOX_PROTOCOL_NOT_QUALIFIED',
              'sandbox_accepted_fixture_tests'=>(int)($protocol['accepted_fixture_authentications']??0),
              'sandbox_rejected_fixture_tests'=>(int)($protocol['rejected_fixture_authentications']??0),
              'vendor_live_test_performed'=>false,
              'direct_vendor_key_installation_allowed'=>false];
        }
        $stripe=(array)($all['stripe_payments']??[]);
        if($stripe){
            $results['stripe_payments']=[
              'state'=>!empty($stripe['gateway_registered'])?'GATEWAY_REGISTERED_MERCHANT_IDENTITY_NOT_ATTESTED':'GATEWAY_NOT_REGISTERED',
              'vendor_live_test_performed'=>false,'merchant_identity_verified'=>false,
              'cross_company_key_copy_allowed'=>false];
        }
        $extended=apply_filters('mad4b/company_credentials/local_adapter_qualification',[]);
        foreach((array)$extended as $id=>$row){
            if(!is_string($id)||!preg_match('/^[a-z0-9_]{3,62}$/D',$id)||!is_array($row))continue;
            $results[$id]=[
                'state'=>sanitize_key((string)($row['state']??'unverified')),
                'license_kind'=>sanitize_key((string)($row['license_kind']??'plugin_license')),
                'vendor_live_test_performed'=>false,
                'company_entitlement_verified'=>false,
                'direct_vendor_key_installation_allowed'=>false];
        }
        return ['contract'=>self::CONTRACT,'site_uuid'=>self::uuid(),
          'providers'=>$results,'production_materialization_enabled'=>false,'secrets_included'=>false];
    }
    public static function routing_rules(array $rules):array {
        $rules[]=['origin'=>'configuration','provider'=>'fluent-smtp',
          'code'=>'smtp_cached_connection_error','family'=>'configuration',
          'candidate_families'=>['configuration','communication'],'confidence'=>'high'];
        $rules[]=['origin'=>'configuration','provider'=>'mad4b-credentials',
          'code'=>'company_credential_stage_integrity_unverified','family'=>'configuration',
          'candidate_families'=>['configuration','security'],'confidence'=>'high'];
        return $rules;
    }
    public static function discover_cached_provider_failures(array $signals):array {
        $s=self::cached_smtp_health();
        if($s['plugin_available'] && $s['cached_fresh_error']>=1){
            $signals[]=['provider'=>'fluent-smtp','code'=>'smtp_cached_connection_error',
              'scope'=>'company-mail-provider-cached-health','source'=>'probe','severity'=>'high',
              'label'=>'Fluent SMTP cached configuration health reports an error; SMTP login was not tested',
              'guidance'=>'Review the native Fluent SMTP Connection Health report with an authorized company administrator. For standard SMTP, cached healthy/error means configuration validation, not SMTP AUTH login success/failure. Never copy sender addresses, errors or credentials to jobs; do not trigger mail sends or OAuth refresh.'];
        }
        if(self::company()){
            $pre=self::rotation_preflight();
            if(empty($pre['private_stage_directory_verified']) || (int)($pre['unexpected_private_stage_files']??0)>0
                || (int)($pre['cipher_mismatch_or_missing_stage_files']??0)>0
                || (int)($pre['invalid_embedded_audit_chains']??0)>0){
                $signals[]=['provider'=>'mad4b-credentials',
                    'code'=>'company_credential_stage_integrity_unverified',
                    'scope'=>'company-private-stage-integrity-'.sanitize_key(self::uuid()),
                    'source'=>'probe','severity'=>'high',
                    'label'=>'Encrypted credential stage files do not match private ledger or secure directory is unavailable',
                    'guidance'=>'Inspect site-bound private stage files and nonsecret ledger readback through the trusted host operator. Do not auto-delete or publish sealed files; never rotate the recipient key until mismatch is reconciled.'];
            }
        }
        return $signals;
    }
    private static function file_dir():string {
        return self::ROOT.'/staged';
    }
    private static function secure_dir():bool {
        if(!self::company()||!is_dir(self::ROOT)||is_link(self::ROOT)||!is_writable(self::ROOT))return false;
        $dir=self::file_dir();
        if(!is_dir($dir)&&!@mkdir($dir,0700,false))return false;
        clearstatcache(true,$dir);
        return is_dir($dir)&&!is_link($dir)&&is_writable($dir)&&
            ((fileperms($dir)&0777)===0700);
    }
    private static function recipient_seed():string|WP_Error {
        $path=self::ROOT.'/recipient.seed';
        if(!is_file($path)||is_link($path)||((fileperms($path)&0077)!==0))return self::deny('recipient_private_key_missing');
        $b=@file_get_contents($path);
        if(!is_string($b)||!preg_match('/^[a-f0-9]{64}$/D',$b))return self::deny('recipient_private_key_invalid');
        return hex2bin($b);
    }
    private static function encryption_key():string|WP_Error {
        $seed=self::recipient_seed();if(is_wp_error($seed))return $seed;
        return sodium_crypto_generichash('MAD4B-VAULT-STAGING-V1|'.self::uuid(),$seed,
            SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }
    private static function path(string $id):string {
        return self::file_dir().'/'.$id.'.sealed';
    }
    // Ledger-embedded audit is the primary receipt. An external audit option is
    // only a secondary convenience; its write is not transactionally coupled
    // to the stage state. This chain detects accidental gaps, not DB-owner tampering.
    private static function receipt_event(array $row,string $action):array {
        $previous=(string)($row['audit_chain_head']??str_repeat('0',64));
        $event=['action'=>$action,'state'=>(string)$row['state'],
            'version'=>(int)$row['version'],'previous'=>$previous];
        $event['digest']=hash('sha256',self::uuid().'|'.(string)$row['id'].'|'.wp_json_encode($event));
        return $event;
    }
    private static function append_receipt_event(array $row,string $action):array {
        $e=self::receipt_event($row,$action);
        $chain=(array)($row['receipt_events']??[]);
        $chain[]=$e;
        $row['receipt_events']=$chain;
        $row['audit_chain_head']=$e['digest'];
        $row['receipt_chain_version']=1;
        return $row;
    }
    private static function verify_receipt_chain(array $row):bool {
        if(($row['receipt_chain_version']??null)!==1)return false;
        $events=$row['receipt_events']??null;
        if(!is_array($events)||count($events)<1||count($events)>8)return false;
        $prev=str_repeat('0',64);
        foreach(array_values($events) as $index=>$event){
            if(!is_array($event)||array_keys($event)!==['action','state','version','previous','digest'])return false;
            if((int)($event['version']??0)!==$index+1 ||
                !hash_equals($prev,(string)($event['previous']??'')))return false;
            $without=$event;unset($without['digest']);
            $expected=hash('sha256',self::uuid().'|'.(string)$row['id'].'|'.wp_json_encode($without));
            if(!is_string($event['digest'])||!hash_equals($expected,$event['digest']))return false;
            $prev=$expected;
        }
        $last=end($events);
        return hash_equals($prev,(string)($row['audit_chain_head']??''))
            &&(int)$last['version']===(int)($row['version']??0)
            &&$last['state']===($row['state']??null);
    }
    private static function audit(string $action,array $row):void {
        $events=(array)get_option(self::AUDIT,[]);
        $events[]=[
            'contract'=>self::CONTRACT,'action'=>$action,
            'stage_id'=>(string)($row['id']??''),
            'site_uuid'=>self::uuid(),'provider_id'=>'mad4b_test_fixture',
            'state'=>(string)($row['state']??'UNKNOWN'),
            'version'=>(int)($row['version']??0),
            'occurred_utc'=>gmdate('c'),'principal'=>'trusted_host_wpcli',
            'secret_included'=>false,'production_provider_changed'=>false];
        update_option(self::AUDIT,array_slice($events,-400),false);
    }
    public static function audit_status():array {
        $events=(array)get_option(self::AUDIT,[]);
        $actions=[];
        foreach($events as $e){
            if(!is_array($e)||($e['site_uuid']??'')!==self::uuid())continue;
            $a=(string)($e['action']??'unknown');
            if(!in_array($a,['STAGED','VERIFY','ACTIVATE','ROLLBACK','REVOKE'],true))continue;
            $actions[$a]=($actions[$a]??0)+1;
        }
        $ledger=(array)get_option(self::STORE,[]);$embedded=0;$invalid=0;$legacy=0;
        foreach($ledger as $record){
            if(!is_array($record)||($record['site_uuid']??'')!==self::uuid())continue;
            if(!isset($record['receipt_chain_version'])){$legacy++;continue;}
            if(self::verify_receipt_chain($record))$embedded++;
            else $invalid++;
        }
        return ['contract'=>self::CONTRACT,'site_uuid'=>self::uuid(),
            'events_total'=>array_sum($actions),'actions'=>$actions,
            'embedded_audit_valid_stage_count'=>$embedded,
            'embedded_audit_invalid_stage_count'=>$invalid,
            'legacy_pre_audit_stage_count'=>$legacy,
            'plaintext_value_included'=>false,'production_key_changed'=>false];
    }
    private static function lock(callable $cb) {
        global $wpdb;$lock='mad4b_company_credential_stage_'.get_current_blog_id();
        if((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,2)',$lock))!==1)return self::deny('ledger_busy');
        try{return $cb();}finally{$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));}
    }
    /** Called only after a separately trusted, signed and decrypted fixture envelope. */
    public static function stage_fixture(array $header,string $secret):array|WP_Error {
        if(!self::trusted())return self::deny('trusted_host_lane_required',403);
        if(($header['provider_id']??'')!=='mad4b_test_fixture'||
            ($header['mode']??'')!=='test_fixture'||empty($header['test_only'])||
            ($header['grant_id']??'')!=='test-synthetic-no-production-grant'||
            !str_starts_with($secret,'MAD4B_TEST_ONLY_'))return self::deny('production_provider_not_enabled');
        if(!self::secure_dir())return self::deny('secure_stage_directory_missing');
        return self::lock(static function()use($header,$secret){
            $id=hash('sha256',self::uuid().'|'.$header['issuer_site_uuid'].'|'.$header['nonce']);
            $stages=(array)get_option(self::STORE,[]);
            foreach($stages as $prior){
                if(!is_array($prior)||($prior['site_uuid']??'')!==self::uuid())continue;
                if(isset($prior['receipt_chain_version'])&&!self::verify_receipt_chain($prior))
                    return self::deny('existing_stage_audit_chain_invalid');
            }
            if(isset($stages[$id]))return self::deny('stage_replay_denied');
            if(count($stages)>=self::MAX)return self::deny('stage_ledger_capacity');
            $key=self::encryption_key();if(is_wp_error($key))return $key;
            $iv=random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $cipher=$iv.sodium_crypto_secretbox($secret,$iv,$key);
            $path=self::path($id);
            if(is_link($path)||is_file($path))return self::deny('stage_path_exists');
            $f=@fopen($path,'x');if($f===false)return self::deny('stage_file_failed');
            @chmod($path,0600);$wrote=false;
            try{$wrote=fwrite($f,$cipher)===strlen($cipher)&&fflush($f);}
            finally{fclose($f);}
            if(!$wrote){@unlink($path);return self::deny('stage_file_incomplete');}
            $stages[$id]=['id'=>$id,'state'=>'STAGED','site_uuid'=>self::uuid(),
                'provider_id'=>'mad4b_test_fixture','test_only'=>true,
                'grant_id'=>(string)$header['grant_id'],'nonce_digest'=>hash('sha256',$header['nonce']),
                'cipher_digest'=>hash('sha256',$cipher),'fixture_digest'=>hash('sha256',$secret),
                'source_issuer_site_uuid'=>(string)$header['issuer_site_uuid'],
                'created_at'=>gmdate('c'),'version'=>1,
                'vendor_secret_materialized'=>false,'provider_setting_changed'=>false];
            $stages[$id]=self::append_receipt_event($stages[$id],'STAGED');
            update_option(self::STORE,$stages,false);
            $readback=(array)get_option(self::STORE,[]);
            if(!isset($readback[$id])||$readback[$id]['cipher_digest']!==hash('sha256',$cipher)
                ||!self::verify_receipt_chain($readback[$id])){
                @unlink($path);return self::deny('stage_ledger_readback_failed');
            }
            self::audit('STAGED',$stages[$id]);
            return ['state'=>'STAGED','stage_id'=>$id,'encrypted_at_rest'=>true,
                'fixture_only'=>true,'vendor_credential_written'=>false];
        });
    }
    private static function decrypt_fixture(array $record):string|WP_Error {
        $id=$record['id']??'';
        if(!is_string($id)||!preg_match('/^[a-f0-9]{64}$/D',$id))return self::deny('invalid_stage_id');
        $path=self::path($id);
        if(!is_file($path)||is_link($path)||((fileperms($path)&0077)!==0))return self::deny('stage_file_unavailable');
        $bytes=@file_get_contents($path);
        if(!is_string($bytes)||strlen($bytes)>5500||strlen($bytes)<SODIUM_CRYPTO_SECRETBOX_NONCEBYTES+SODIUM_CRYPTO_SECRETBOX_MACBYTES||
           !hash_equals((string)($record['cipher_digest']??''),hash('sha256',$bytes)))return self::deny('stage_cipher_integrity_failure');
        $key=self::encryption_key();if(is_wp_error($key))return $key;
        $iv=substr($bytes,0,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $clear=sodium_crypto_secretbox_open(substr($bytes,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),$iv,$key);
        if(!is_string($clear)||!str_starts_with($clear,'MAD4B_TEST_ONLY_')||
            !hash_equals((string)($record['fixture_digest']??''),hash('sha256',$clear)))return self::deny('stage_decrypt_or_canary_validation_failed');
        return $clear;
    }
    public static function transition(string $id,string $action,int $version):array|WP_Error {
        if(!self::trusted())return self::deny('host_only',403);
        if(!preg_match('/^[a-f0-9]{64}$/D',$id)||!in_array($action,['verify','activate','rollback','revoke'],true)||$version<1)
            return self::deny('invalid_transition');
        return self::lock(static function()use($id,$action,$version){
            $all=(array)get_option(self::STORE,[]);$row=$all[$id]??null;
            if(!is_array($row)||($row['site_uuid']??'')!==self::uuid()||empty($row['test_only'])||
                ($row['provider_id']??'')!=='mad4b_test_fixture')return self::deny('stage_not_found');
            if(!self::verify_receipt_chain($row))return self::deny('ledger_embedded_audit_incomplete');
            if((int)$row['version']!==$version)return self::deny('stage_version_conflict');
            $flow=['verify'=>['STAGED'=>'VERIFIED'],'activate'=>['VERIFIED'=>'ACTIVE_CANARY'],
                'rollback'=>['ACTIVE_CANARY'=>'ROLLED_BACK'],
                'revoke'=>['STAGED'=>'REVOKED','VERIFIED'=>'REVOKED','ACTIVE_CANARY'=>'REVOKED','ROLLED_BACK'=>'REVOKED']];
            $from=$row['state'];
            if(!isset($flow[$action][$from]))return self::deny('invalid_state_transition');
            if($action!=='revoke'){
                $plain=self::decrypt_fixture($row);if(is_wp_error($plain))return $plain;
                if($action==='activate'){
                    foreach($all as $otherId=>$other)
                        if($otherId!==$id&&($other['state']??'')==='ACTIVE_CANARY')
                            return self::deny('one_active_canary_at_a_time');
                }
            }
            if($action==='revoke'){
                // Fail closed before changing the ledger state. A DB flag saying
                // REVOKED while the encrypted file is still readable is unsafe.
                $file=self::path($id);
                if(is_link($file)||is_dir($file))return self::deny('revoke_unsafe_staged_path');
                if(is_file($file) && !@unlink($file))return self::deny('revoke_encrypted_file_delete_failed');
                clearstatcache(true,$file);
                if(file_exists($file)||is_link($file))return self::deny('revoke_file_still_present');
            }
            $row['state']=$flow[$action][$from];$row['version']++;
            $row['last_transition_at']=gmdate('c');
            $row['provider_test_verified']=in_array($row['state'],['VERIFIED','ACTIVE_CANARY'],true);
            $row=self::append_receipt_event($row,strtoupper($action));
            $all[$id]=$row;
            update_option(self::STORE,$all,false);
            $read=(array)get_option(self::STORE,[]);
            if(($read[$id]['state']??'')!==$row['state']||($read[$id]['version']??0)!==$row['version']
                ||!self::verify_receipt_chain($read[$id]))
                return self::deny('stage_transition_readback_failed');
            self::audit(strtoupper($action),$row);
            return ['state'=>$row['state'],'stage_id'=>$id,'version'=>$row['version'],
                'provider_test_verified'=>(bool)$row['provider_test_verified'],
                'vendor_credential_changed'=>false,'production_provider_affected'=>false,
                'can_rollback'=>$row['state']==='ACTIVE_CANARY'];
        });
    }
    /** Recipient seed rotation is refused while any encrypted stage still
     * needs its current key. Revoked records remain for audit only.
     */
    public static function rotation_preflight():array {
        $all=(array)get_option(self::STORE,[]);
        $blocking=[];$badCipherOrMissing=0;$invalidEmbeddedAudit=0;
        foreach($all as $record){
            if(!is_array($record)||($record['site_uuid']??'')!==self::uuid())continue;
            if(isset($record['receipt_chain_version'])&&!self::verify_receipt_chain($record))
                $invalidEmbeddedAudit++;
            if(($record['state']??'')!=='REVOKED'){
                $blocking[]=['stage_id'=>(string)($record['id']??''),
                    'state'=>(string)($record['state']??'UNKNOWN')];
                $id=(string)($record['id']??'');
                $file=preg_match('/^[a-f0-9]{64}$/D',$id)?self::path($id):'';
                if(!$file||!is_file($file)||is_link($file)||!is_readable($file)
                    || ((fileperms($file)&0777)!==0600) || filesize($file)>5500
                    || !preg_match('/^[a-f0-9]{64}$/D',(string)($record['cipher_digest']??''))
                    || !hash_equals((string)$record['cipher_digest'],hash_file('sha256',$file)))
                    $badCipherOrMissing++;
            }
        }
        // Never rely solely on WordPress Options. A leftover sealed file can
        // survive a failed revoke, a corrupt ledger, or a restored old backup.
        // Do not unlink or expose its contents: block key rotation instead.
        $unexpected=0;$dir=self::file_dir();$storageVerified=true;
        if(is_link($dir)||!is_dir($dir)||!is_readable($dir)){
            $storageVerified=false;
        }else{
            $entries=@scandir($dir);
            if(!is_array($entries))$storageVerified=false;
            else foreach($entries as $name){
                if($name==='.'||$name==='..')continue;
                if(!preg_match('/^[a-f0-9]{64}\.sealed$/D',$name)){
                    $unexpected++;continue;
                }
                $stageId=substr($name,0,64);
                $record=$all[$stageId]??null;
                if(!is_array($record)||($record['site_uuid']??'')!==self::uuid()
                    ||($record['state']??'')==='REVOKED'
                    ||is_link($dir.'/'.$name)||!is_file($dir.'/'.$name))$unexpected++;
            }
        }
        $safe=$storageVerified && count($blocking)===0 && $unexpected===0 && $badCipherOrMissing===0
            && $invalidEmbeddedAudit===0;
        return ['contract'=>self::CONTRACT,'site_uuid'=>self::uuid(),
            'recipient_private_key_rotation_safe'=>$safe,
            'blocking_stage_count'=>count($blocking),
            'blocked_stages'=>array_slice($blocking,0,40),
            'unexpected_private_stage_files'=>$unexpected,
            'cipher_mismatch_or_missing_stage_files'=>$badCipherOrMissing,
            'invalid_embedded_audit_chains'=>$invalidEmbeddedAudit,
            'private_stage_directory_verified'=>$storageVerified,
            'keys_rotated'=>false,'secret_returned'=>false];
    }
    public static function staging_status():array {
        $rows=(array)get_option(self::STORE,[]);$list=[];
        foreach($rows as $row){
            if(!is_array($row)||($row['site_uuid']??'')!==self::uuid())continue;
            $path=self::path((string)($row['id']??''));
            $cipherHealthy=($row['state']??'')!=='REVOKED'
                &&is_file($path)&&!is_link($path)&&is_readable($path)
                &&(fileperms($path)&0777)===0600&&filesize($path)<=5500
                &&preg_match('/^[a-f0-9]{64}$/D',(string)($row['cipher_digest']??''))
                &&hash_equals((string)$row['cipher_digest'],hash_file('sha256',$path));
            $list[]=['id'=>$row['id'],'state'=>$row['state'],'version'=>$row['version'],
                'test_only'=>true,'encrypted_at_rest'=>(bool)$cipherHealthy,
                'cipher_integrity_verified'=>(bool)$cipherHealthy,
                'revoked_file_absent'=>($row['state']==='REVOKED')?!file_exists($path):null,
                'vendor_credential_changed'=>false];
        }
        return ['contract'=>self::CONTRACT,'site_uuid'=>self::uuid(),
            'stages'=>$list,'total'=>count($list),'plaintext_secret_exposed'=>false];
    }
    public static function routes():void {
        register_rest_route('mad4b-company-credentials/v1','/adapter-status',[
            'methods'=>'GET','callback'=>static fn()=>rest_ensure_response([
                'catalog'=>self::catalog(),'health'=>self::health(),
                'qualification'=>self::qualification(),
                'staging'=>self::staging_status(),'staging_audit'=>self::audit_status(),
                'rotation_preflight'=>self::rotation_preflight()]),
            'permission_callback'=>static fn()=>current_user_can('mad4b_manage_company_operations')||current_user_can('manage_options')]);
    }
    public static function abilities():void {
        if(!function_exists('wp_register_ability')||(function_exists('wp_has_ability')&&wp_has_ability('mad4b/company-credential-adapters')))return;
        wp_register_ability('mad4b/company-credential-adapters',[
            'label'=>'Inspect company provider credential adapters','description'=>'Read-only; no API keys or account secrets.',
            'category'=>'mad4b-read','execute_callback'=>static fn($input)=>['catalog'=>self::catalog(),'health'=>self::health(),
                'qualification'=>self::qualification(),
                'staging'=>self::staging_status(),'staging_audit'=>self::audit_status(),
                'rotation_preflight'=>self::rotation_preflight()],
            'permission_callback'=>static fn()=>current_user_can('manage_options')||current_user_can('mad4b_manage_company_operations'),
            'input_schema'=>['type'=>'object','properties'=>[],'required'=>[],'additionalProperties'=>false],
            'output_schema'=>['type'=>'object','additionalProperties'=>true],
            'meta'=>['public'=>false,'show_in_rest'=>false,'mcp'=>['public'=>false,'type'=>'tool','surface'=>'read'],
                'annotations'=>['readonly'=>true,'idempotent'=>true,'destructive'=>false]],
        ]);
    }
}
MAD4B_Credential_Provider_Adapters::boot();

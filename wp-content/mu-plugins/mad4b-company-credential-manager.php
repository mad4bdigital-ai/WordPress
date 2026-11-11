<?php
/**
 * Plugin Name: MAD4B Company Credential Manager
 * Description: Central, site-bound company credential governance. Metadata only; never returns or accepts provider secrets.
 * Version: 0.1.0
 */
if (!defined('ABSPATH')) exit;
final class MAD4B_Company_Credential_Manager {
    const CONTRACT='mad4b.company-credential-manager.v1';
    const STORE='mad4b_company_credential_grants_v1';
    const AUDIT='mad4b_company_credential_audit_v1';
    const REGISTRY='mad4b_company_environment_registry_v2';
    const CAP='manage_options';
    const MENU='mad4b-company-credentials';
    const MAX=300;

    public static function boot():void {
        add_action('admin_menu',[__CLASS__,'menu'],93);
        add_action('rest_api_init',[__CLASS__,'routes']);
        add_action('wp_abilities_api_init',[__CLASS__,'abilities'],58);
        add_action('admin_post_mad4b_company_credential_intent',[__CLASS__,'handle_intent']);
        add_action('admin_post_mad4b_company_credential_transition',[__CLASS__,'handle_transition']);
        add_filter('mad4b/configuration_jobs/discover',[__CLASS__,'configuration_alerts'],20,1);
        add_filter('mad4b/job_routing/exact_rules',[__CLASS__,'classification_rules'],15,1);
    }
    public static function classification_rules(array $rules):array {
        foreach(['company_credential_grant_expiring','company_credential_grant_expired',
                 'company_credential_provider_unhealthy',
                 'company_credential_offhost_recovery_unverified',
                 'company_credential_local_recovery_failed'] as $code){
            $rules[]=['origin'=>'configuration','provider'=>'mad4b-credentials',
                      'code'=>$code,'family'=>'configuration',
                      'candidate_families'=>['configuration','security'],'confidence'=>'high'];
        }
        return $rules;
    }
    public static function configuration_alerts(array $signals):array {
        if(!self::central())return $signals;
        $now=time();$count=0;
        foreach((array)get_option(self::STORE,[]) as $grant){
            if(!is_array($grant)||$count>=80)break;
            if(!in_array($grant['state']??'',['ACTIVE','APPROVED'],true))continue;
            $expires=(int)($grant['expires_at']??0);
            $reason='';$code='';$severity='medium';
            if($expires<=$now){$code='company_credential_grant_expired';$reason='Grant expired; no new credential distribution should be admitted';$severity='high';}
            elseif($expires<$now+14*DAY_IN_SECONDS){$code='company_credential_grant_expiring';$reason='Company credential grant expires within 14 days; renew only after readback';}
            if($code==='')continue;
            $target=(array)($grant['target']??[]);
            $signals[]=['provider'=>'mad4b-credentials','code'=>$code,'source'=>'probe',
                'scope'=>sanitize_key((string)($target['company_code']??'')) .'-'.
                         sanitize_key((string)($target['environment']??'')) .'-'.
                         substr(hash('sha256',(string)($grant['id']??'')),0,12),
                'severity'=>$severity,'label'=>$reason,
                'guidance'=>'Open Company Credentials and inspect the existing site-bound grant, then check native provider readback. Never place a secret value inside a Configuration Job.'];
            ++$count;
        }
        // Recoverability is a separate governance condition from having a
        // working key; never mark off-host escrow as verified from a VPS-local
        // backup alone. The collector transports *status only*, not ciphertext.
        if(class_exists('MAD4B_Configuration_Fleet')){
            $fleet=MAD4B_Configuration_Fleet::snapshot();
            $a=(array)($fleet['credential_recovery']??[]);
            if(!empty($fleet['snapshot_fresh'])&&!empty($a['fresh'])){
                $unverified=($a['offhost_escrow_status']??'')==='NOT_INDEPENDENTLY_VERIFIED';
                $failed=($a['state']??'')==='BACKUP_ASSURANCE_INCOMPLETE';
                if($unverified || $failed){
                    $signals[]=['provider'=>'mad4b-credentials',
                      'code'=>$failed?'company_credential_local_recovery_failed':'company_credential_offhost_recovery_unverified',
                      'source'=>'probe','scope'=>'central-credential-recovery-host',
                      'severity'=>$failed?'high':'medium',
                      'label'=>$failed?'Credential key recovery local backup assurance failed':
                        'Credential recovery backups and root key lack independently verified off-host escrow',
                      'guidance'=>'Review the host-only encrypted recovery audit and establish independently protected off-host escrow. Do not upload the root recovery key to WordPress, ChatGPT, GitHub or the same storage as the encrypted backup.'];
                }
            }
        }
        return $signals;
    }
    private static function central_profile():array {
        $p=get_option('mad4b_scp_site_profile_v2',[]);
        return is_array($p)?$p:[];
    }
    public static function central():bool {
        $r=(array)get_option(self::REGISTRY,[]);
        $p=self::central_profile();$all=(array)($r['companies']??[]);
        $home=untrailingslashit(home_url('/'));
        return !empty($p['site_uuid'])&&isset($all['tours-central']['environments']['staging'])
           &&hash_equals((string)($all['tours-central']['environments']['staging']['site_uuid']??''),(string)$p['site_uuid'])
           &&hash_equals((string)($all['tours-central']['environments']['staging']['canonical_origin']??''),$home);
    }
    public static function can():bool {
        // The locally authorized host WP-CLI system principal is a trusted
        // execution lane. Never synthesize a WordPress Administrator identity.
        return self::central() && (current_user_can(self::CAP) ||
            (defined('WP_CLI') && WP_CLI===true && PHP_SAPI==='cli'));
    }
    private static function err(string $code,int $status=400):WP_Error {
        return new WP_Error('mad4b_credential_'.$code,'Credential management operation was not admitted.', ['status'=>$status,'code'=>$code]);
    }
    private static function identity(string $company,string $environment,string $uuid):array|WP_Error {
        if(!preg_match('/^[a-z0-9][a-z0-9-]{0,75}$/D',$company)
            ||!in_array($environment,['production','staging','development','local'],true)
            ||!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D',$uuid))return self::err('target_identity_invalid',422);
        $r=(array)get_option(self::REGISTRY,[]);
        $x=$r['companies'][$company]['environments'][$environment]??null;
        if(!is_array($x)||empty($x['site_uuid'])||empty($x['canonical_origin']))return self::err('company_enrollment_unverified',409);
        $url=(string)$x['canonical_origin'];
        if(!hash_equals((string)$x['site_uuid'],$uuid)||!preg_match('~^https://[a-z0-9.-]+(?::[0-9]{1,5})?$~D',$url))
            return self::err('site_uuid_or_origin_mismatch',409);
        return ['company_code'=>$company,'environment'=>$environment,'site_uuid'=>$uuid,'canonical_origin'=>$url,
                'company_name'=>sanitize_text_field((string)($r['companies'][$company]['company_name']??$company))];
    }
    public static function catalog():array {
        $raw=[
            'woocommerce_maxmind_geolite2'=>[
              'label'=>'WooCommerce MaxMind GeoLite2','secret_type'=>'maxmind_license',
              'allowed_modes'=>['managed_dataset'],
              'default_mode'=>'managed_dataset','materialized_secret_available'=>false,
              'notes'=>'Use source-licensed database distribution with tenant-specific files. No MaxMind account key copied.'],
            'stripe_payments'=>[
              'label'=>'Stripe payment gateway','secret_type'=>'payment_gateway',
              'allowed_modes'=>['company_owned'],'default_mode'=>'company_owned',
              'materialized_secret_available'=>false,'notes'=>'Separate merchant account credentials; never duplicate a live payment secret across companies.'],
            'fluent_smtp'=>[
              'label'=>'Fluent SMTP','secret_type'=>'mail_transport',
              'allowed_modes'=>['company_owned'],'default_mode'=>'company_owned',
              'materialized_secret_available'=>false,'notes'=>'Company-owned SMTP credentials; delivery metadata only centrally.'],
            'ai_api_provider'=>[
              'label'=>'Managed AI provider','secret_type'=>'api_key',
              'allowed_modes'=>['delegated_service'],'default_mode'=>'delegated_service',
              'materialized_secret_available'=>false,'notes'=>'Requires trusted central broker adapter before activation.'],
        ];
        $extensions=apply_filters('mad4b/company_credentials/provider_catalog',$raw);
        if(!is_array($extensions))$extensions=$raw;
        $result=[];
        foreach(array_slice($extensions,0,80,true) as $id=>$item){
            if(!is_string($id)||!preg_match('/^[a-z0-9][a-z0-9_]{1,62}$/D',$id)||!is_array($item))continue;
            $allowed=array_values(array_intersect(['managed_dataset','delegated_service','company_owned'],
                (array)($item['allowed_modes']??[])));
            if(!$allowed)continue;
            $result[$id]=[
                'id'=>$id,'label'=>sanitize_text_field((string)($item['label']??$id)),
                'secret_type'=>sanitize_key((string)($item['secret_type']??'api_key')),
                'allowed_modes'=>$allowed,'default_mode'=>in_array($item['default_mode']??null,$allowed,true)?$item['default_mode']:$allowed[0],
                'notes'=>sanitize_text_field((string)($item['notes']??'')),
                'plaintext_exposed'=>false,'direct_secret_transfer_admitted'=>false,
                'materialized_secret_available'=>false,
            ];
        }
        return ['contract'=>self::CONTRACT,'providers'=>$result,'provider_count'=>count($result),
                'plaintext_in_api'=>false,'direct_transfer_enabled'=>false];
    }
    public static function company_targets():array {
        $r=(array)get_option(self::REGISTRY,[]);$out=[];$live=[];
        $fleet=class_exists('MAD4B_Configuration_Fleet')?MAD4B_Configuration_Fleet::snapshot():[];
        if(!empty($fleet['snapshot_fresh'])){
            foreach((array)($fleet['sites']??[]) as $site){
                if(!is_array($site)||($site['coverage']??'')!=='verified')continue;
                $code=(string)($site['company_code']??'');
                $environment=(string)($site['environment']??'');
                $uuid=(string)($site['site_uuid']??'');
                if($code!=='' && $environment!=='' && $uuid!==''){
                    $live[$code.'|'.$environment.'|'.$uuid]=true;
                }
            }
        }
        foreach((array)($r['companies']??[]) as $company=>$row){
            foreach((array)($row['environments']??[]) as $env=>$v){
                if(!in_array($env,['production','staging','development','local'],true)||!is_array($v))continue;
                $uuid=(string)($v['site_uuid']??'');
                $formatted=(bool)preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D',$uuid);
                $verified=$formatted && isset($live[(string)$company.'|'.$env.'|'.$uuid]);
                $out[]=['company_code'=>(string)$company,'environment'=>$env,
                    'site_uuid'=>$uuid,'site_uuid_format_valid'=>$formatted,
                    'site_verified'=>$verified,
                    'enrollment_state'=>$verified?'LIVE_VERIFIED':($formatted?'REGISTERED_NOT_LIVE_VERIFIED':'SITE_UUID_MISSING'),
                    'company_name'=>sanitize_text_field((string)($row['company_name']??$company))];
            }
        }
        return $out;
    }
    private static function approved_fields(array $a,array $allowed):bool {
        return !array_diff(array_keys($a),$allowed);
    }
    public static function plan(array $input):array|WP_Error {
        if(!self::can())return self::err('not_authorized',403);
        if(!self::approved_fields($input,['company_code','environment','site_uuid','provider_id','mode','intent_key','expires_days','purpose']))
            return self::err('metadata_only_input_required',422);
        foreach(['company_code','environment','site_uuid','provider_id','mode','intent_key'] as $k)
            if(!is_string($input[$k]??null))return self::err('required_field_invalid',422);
        $target=self::identity($input['company_code'],$input['environment'],$input['site_uuid']);
        if(is_wp_error($target))return $target;
        $provider=(string)$input['provider_id'];$mode=(string)$input['mode'];
        $catalog=self::catalog()['providers'];
        if(!isset($catalog[$provider])||!in_array($mode,$catalog[$provider]['allowed_modes'],true))
            return self::err('provider_mode_not_supported',422);
        $intent=(string)$input['intent_key'];
        if(!preg_match('/^[a-z0-9][a-z0-9._:-]{7,127}$/D',$intent))return self::err('idempotency_key_invalid',422);
        $days=$input['expires_days']??30;
        if(!is_int($days)||$days<1||$days>90)return self::err('expiry_invalid',422);
        $purpose=$input['purpose']??'company_provider_maintenance';
        if(!is_string($purpose)||!preg_match('/^[a-z][a-z0-9._:-]{4,94}$/D',$purpose))return self::err('purpose_invalid',422);
        // Never key provider-mode readiness to one company or to a plugin version.
        // A verified collector receipt plus exact company Site UUID determines readiness.
        $nativeManagedProviders=['wp_all_import_pro','wp_all_export_pro','fluent_forms_pro'];
        $supported=($mode==='managed_dataset' && $provider==='woocommerce_maxmind_geolite2') ||
            (in_array($provider,$nativeManagedProviders,true) &&
             in_array($mode,['company_owned','delegated_service'],true));
        // Local vendor activation is a separate workflow from issuing a grant.
        // It already accepts a company administrator and requires no central
        // DRAFT/APPROVED round-trip. Do not present its plan as 'not ready'.
        $nativeLocal=false;
        // identity() validates the canonical registry binding, but does not
        // carry a site_verified property. Check Fleet evidence directly;
        // do not incorrectly classify real companies as ADAPTER_NOT_READY.
        if(in_array($mode,['company_owned','delegated_service'],true) &&
           class_exists('MAD4B_Configuration_Fleet')){
            $nativeProviders=['wp_all_import_pro','wp_all_export_pro',
                'fluent_forms_pro','ultimate_addons_elementor','elementor_pro'];
            if(in_array($provider,$nativeProviders,true)){
                $snapshot=MAD4B_Configuration_Fleet::snapshot();
                if(!empty($snapshot['snapshot_fresh'])){
                    foreach((array)($snapshot['sites']??[]) as $site){
                        if(($site['coverage']??'')!=='verified' ||
                           ($site['company_code']??'')!==$target['company_code'] ||
                           ($site['environment']??'')!==$target['environment'] ||
                           ($site['site_uuid']??'')!==$target['site_uuid'])continue;
                        $e=(array)($site['credential_provider_qualification']['states']??[]);
                        $local=(array)($e[$provider]??[]);
                        $nativeLocal=!empty($local) &&
                            ($local['state']??'')!=='PLUGIN_INACTIVE_LICENSE_UNVERIFIED' &&
                            ($local['state']??'')!=='plugin_inactive_license_unverified';
                        break;
                    }
                }
            }
        }
        $proof=$supported?self::ready_evidence($target,$provider,$mode):['verified'=>false];
        $state=!empty($proof['verified'])?'READY_FOR_VERIFIED_NATIVE_GRANT_ACTIVATION':
            (($mode==='managed_dataset' && $supported)?'READY_FOR_MANAGED_DATASET_VERIFICATION':
            ($nativeLocal?'RECIPIENT_NATIVE_ENROLLMENT_AVAILABLE':'ADAPTER_NOT_READY'));
        return ['contract'=>self::CONTRACT,'state'=>$state,'target'=>$target,
                'provider_id'=>$provider,'mode'=>$mode,'intent_key'=>$intent,
                'expires_days'=>$days,'purpose'=>$purpose,'can_create_draft'=>true,
                'can_activate_now'=>$supported && !empty($proof['verified']),
                'will_copy_credential'=>false,'will_change_provider_settings'=>false,
                'requires_approval'=>true,'requires_independent_readback'=>true,
                // This flag concerns *recipient-local WordPress activation*,
                // never a Company Manager grant or cross-site key transfer.
                'recipient_native_enrollment_available'=>$nativeLocal,
                'recipient_enrollment_requires_central_approval'=>false,
                'recipient_requires_authorized_vendor_credential'=>true,
                'recipient_independent_vendor_activation_required'=>true,
                'source_to_recipient_key_transfer_enabled'=>false];
    }
    private static function ready_evidence(array $target,string $provider,string $mode):array {
        if(!class_exists('MAD4B_Configuration_Fleet'))
            return ['verified'=>false,'reason'=>'fleet_adapter_unavailable'];
        $fleet=MAD4B_Configuration_Fleet::snapshot();
        if(empty($fleet['snapshot_fresh']))
            return ['verified'=>false,'reason'=>'fleet_evidence_stale'];
        $nativeProviders=['wp_all_import_pro','wp_all_export_pro','fluent_forms_pro'];
        $nativeMode=in_array($provider,$nativeProviders,true) &&
            in_array($mode,['company_owned','delegated_service'],true);
        $datasetMode=$provider==='woocommerce_maxmind_geolite2' && $mode==='managed_dataset';
        if(!$nativeMode && !$datasetMode)
            return ['verified'=>false,'reason'=>'provider_mode_has_no_independent_readback'];
        foreach((array)($fleet['sites']??[]) as $site){
            if(($site['company_code']??'')!==$target['company_code'] ||
               ($site['environment']??'')!==$target['environment'] ||
               ($site['site_uuid']??'')!==$target['site_uuid'])continue;
            if(($site['coverage']??'')!=='verified')
                return ['verified'=>false,'reason'=>'target_not_verified'];
            if($nativeMode){
                // Approval is permission to REQUEST a separately signed
                // provider operation, not proof of a vendor activation.
                // This is used only AFTER recipient native execution, when
                // Fleet independently observes the native vendor receipt.
                $native=(array)($site['credential_native_plugin_activation']??[]);
                if(($native['contract']??'')!=='mad4b.plugin-license-native-activation-evidence.v1' ||
                   ($native['sensitive_values_included']??null)!==false)
                    return ['verified'=>false,'reason'=>'recipient_native_evidence_untrusted'];
                $entry=(array)($native['provider_states'][$provider]??[]);
                if(($entry['state']??'')!=='recent_vendor_activation_receipt_and_local_valid' ||
                   ($entry['vendor_receipt_and_current_license_valid']??null)!==true ||
                   ($entry['secret_value_returned']??null)!==false ||
                   empty($entry['vendor_activated_at_utc']))
                    return ['verified'=>false,'reason'=>'recipient_vendor_receipt_or_local_status_not_verified'];
                $when=strtotime((string)$entry['vendor_activated_at_utc']);
                if($when===false || $when>time()+60 || $when<time()-30*DAY_IN_SECONDS)
                    return ['verified'=>false,'reason'=>'recipient_vendor_receipt_not_current'];
                $qualified=(array)($site['credential_provider_qualification']['states'][$provider]??[]);
                if(empty($qualified) ||
                   in_array(($qualified['state']??''),[
                       'license_not_configured','plugin_inactive_license_unverified',
                       'target_evidence_not_collected'],true))
                    return ['verified'=>false,'reason'=>'recipient_plugin_native_state_invalid'];
                return ['verified'=>true,
                    'reason'=>'recipient_native_vendor_activation_and_fleet_readback_verified',
                    'observed_utc'=>$site['observed_utc']??null,
                    // This confirms only the recipient vendor activation
                    // readback. Original signed 313 is a separate gate.
                    'original_313_certified'=>false];
            }
            $e=(array)($site['managed_credential_evidence']??[]);
            if(($e['contract']??'')!=='mad4b.geolite-delegated-update.v1'||
               ($e['mode']??'')!=='source_licensed_mirror'||empty($e['local_db_matches_receipt'])||
               empty($e['source_license_checked'])||empty($e['source_updater_checked'])||
               !empty($e['local_secret_present'])||
               ($e['source_site_uuid']??'')!==self::central_profile()['site_uuid']||
               (int)($e['proof_age_seconds']??999999)>7200)
                return ['verified'=>false,'reason'=>'delegated_source_receipt_not_verified'];
            return ['verified'=>true,'reason'=>'trusted_local_mirror_and_licensed_source_verified',
                'observed_utc'=>$site['observed_utc']??null];
        }
        return ['verified'=>false,'reason'=>'target_not_collected'];
    }
    private static function with_lock(callable $callback):array|WP_Error {
        global $wpdb;
        $name='mad4b_credential_manager_'.get_current_blog_id();
        if((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,3)',$name))!==1)return self::err('busy',409);
        try{return $callback();}
        finally{$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$name));}
    }
    private static function audit(string $action,array $grant):void {
        $events=(array)get_option(self::AUDIT,[]);
        $events[]=['action'=>$action,'grant_id'=>$grant['id']??'',
            'company_code'=>$grant['target']['company_code']??'',
            'environment'=>$grant['target']['environment']??'',
            'provider_id'=>$grant['provider_id']??'',
            'state'=>$grant['state']??'',
            'actor_id'=>get_current_user_id(),
            'actor_principal'=>get_current_user_id()>0?'wordpress_operator':'host_wpcli',
            'at'=>gmdate('c'),
            'secret_value_logged'=>false];
        update_option(self::AUDIT,array_slice($events,-1000),false);
    }
    public static function draft(array $input):array|WP_Error {
        $plan=self::plan($input);if(is_wp_error($plan))return $plan;
        return self::with_lock(static function()use($plan){
            $all=(array)get_option(self::STORE,[]);
            $bind=[$plan['target']['company_code'],$plan['target']['environment'],$plan['target']['site_uuid'],
                   $plan['provider_id'],$plan['mode'],$plan['intent_key'],(int)$plan['expires_days'],$plan['purpose']];
            $hash=hash('sha256',wp_json_encode($bind));
            $id='ccg.'.substr(hash('sha256',self::central_profile()['site_uuid'].'|'.$plan['intent_key'].'|'.$plan['target']['site_uuid']),0,48);
            if(isset($all[$id])){
                if(!hash_equals($hash,(string)($all[$id]['intent_hash']??'')))return self::err('idempotency_key_reused_with_different_payload',422);
                return ['status'=>'already_exists','grant'=>self::project($all[$id])];
            }
            if(count($all)>=self::MAX)return self::err('grant_capacity',409);
            $record=['id'=>$id,'contract'=>self::CONTRACT,'target'=>$plan['target'],
                'provider_id'=>$plan['provider_id'],'mode'=>$plan['mode'],'purpose'=>$plan['purpose'],
                'intent_hash'=>$hash,'state'=>'DRAFT','version'=>1,
                'expires_at'=>time()+$plan['expires_days']*DAY_IN_SECONDS,
                'created_at'=>gmdate('c'),'created_by'=>get_current_user_id(),
                'approved_by'=>0,'active_at'=>null,
                'credential_copied'=>false,'provider_settings_changed'=>false,
                'readback_verified'=>false,'secret_value_stored'=>false];
            $all[$id]=$record;
            update_option(self::STORE,$all,false);
            $saved=(array)get_option(self::STORE,[]);
            if(!isset($saved[$id]) || ($saved[$id]['intent_hash']??'')!==$hash)
                return self::err('grant_store_readback_failed',500);
            self::audit('DRAFT_CREATED',$record);
            return ['status'=>'created','grant'=>self::project($record)];
        });
    }
    private static function project(array $r):array {
        $status=$r['state'];if(($r['expires_at']??0)<=time()&&!in_array($status,['REVOKED','EXPIRED'],true))$status='EXPIRED';
        return ['id'=>$r['id'],'contract'=>self::CONTRACT,'target'=>$r['target'],
            'provider_id'=>$r['provider_id'],'mode'=>$r['mode'],'purpose'=>$r['purpose'],
            'state'=>$status,'version'=>$r['version'],'expires_at'=>gmdate('c',$r['expires_at']),
            'created_at'=>$r['created_at'],'created_by'=>$r['created_by'],
            'approved_by'=>$r['approved_by'],'readback_verified'=>$r['readback_verified'],
            'credential_copied'=>false,'secret_value_exposed'=>false,
            'provider_settings_changed'=>false];
    }
    public static function grants(int $page=1,int $perPage=20):array|WP_Error {
        if(!self::can())return self::err('not_authorized',403);
        $page=max(1,$page);$perPage=max(1,min(100,$perPage));
        $all=(array)get_option(self::STORE,[]);$rows=array_reverse(array_values($all));
        $items=array_map([__CLASS__,'project'],array_slice($rows,($page-1)*$perPage,$perPage));
        return ['contract'=>self::CONTRACT,'items'=>$items,'pagination'=>['page'=>$page,'per_page'=>$perPage,
                'total'=>count($rows),'pages'=>(int)ceil(count($rows)/$perPage)],'secret_values_included'=>false];
    }
    public static function transition(string $id,string $action,int $expectedVersion):array|WP_Error {
        if(!self::can())return self::err('not_authorized',403);
        if(!preg_match('/^ccg\.[a-f0-9]{48}$/D',$id)||!in_array($action,['approve','activate','revoke','renew'],true)||$expectedVersion<1)
            return self::err('invalid_transition_input',422);
        return self::with_lock(static function()use($id,$action,$expectedVersion){
            $all=(array)get_option(self::STORE,[]);$row=$all[$id]??null;
            if(!is_array($row))return self::err('grant_not_found',404);
            $x=$row['target'];$actual=self::identity($x['company_code'],$x['environment'],$x['site_uuid']);
            if(is_wp_error($actual))return $actual;
            if((int)$row['version']!==$expectedVersion){
                if((int)$row['version']===$expectedVersion+1 &&
                    ($row['last_transition_action']??'')===$action &&
                    (int)($row['last_transition_from_version']??0)===$expectedVersion)
                    return ['status'=>'already_transitioned','grant'=>self::project($row),
                            'new_credential_transferred'=>false];
                return self::err('version_conflict',409);
            }
            if($row['expires_at']<=time())return self::err('grant_expired',409);
            $state=$row['state'];
            $allowed=['approve'=>['DRAFT'=>'APPROVED'],
                      'activate'=>['APPROVED'=>'ACTIVE'],
                      'renew'=>['ACTIVE'=>'ACTIVE'],
                      'revoke'=>['DRAFT'=>'REVOKED','APPROVED'=>'REVOKED','ACTIVE'=>'REVOKED']];
            if(!isset($allowed[$action][$state]))return self::err('invalid_transition',409);
            if(in_array($action,['activate','renew'],true)){
                $proof=self::ready_evidence($x,$row['provider_id'],$row['mode']);
                if(!$proof['verified'])return self::err('independent_provider_readback_required',409);
                $row['readback_verified']=true;$row['active_at']=gmdate('c');
                $row['activation_evidence']=$proof['reason'];
            }
            $row['state']=$allowed[$action][$state];
            if($action==='renew')$row['expires_at']=time()+90*DAY_IN_SECONDS;
            $row['last_transition_action']=$action;
            $row['last_transition_from_version']=$expectedVersion;
            $row['version']++;
            if($action==='approve')$row['approved_by']=get_current_user_id();
            if($action==='revoke')$row['revoked_at']=gmdate('c');
            $all[$id]=$row;
            update_option(self::STORE,$all,false);
            $saved=(array)get_option(self::STORE,[]);
            if((int)($saved[$id]['version']??0)!==(int)$row['version'] ||
                ($saved[$id]['state']??'')!==$row['state'])
                return self::err('transition_store_readback_failed',500);
            self::audit(strtoupper($action),$row);
            return ['status'=>'transitioned','grant'=>self::project($row),
                    'native_provider_key_revoked'=>false,
                    'new_credential_transferred'=>false];
        });
    }
    public static function active_grant(string $company,string $env,string $uuid,string $provider,string $mode):bool {
        // Trusted local host observer only: grant is necessary but NOT sufficient for a native effect.
        if(!self::central())return false;
        $validated=self::identity($company,$env,$uuid);if(is_wp_error($validated))return false;
        foreach((array)get_option(self::STORE,[]) as $grant){
            if(!is_array($grant)||$grant['state']!=='ACTIVE'||(int)$grant['expires_at']<=time())continue;
            if($grant['target']['site_uuid']===$uuid && $grant['target']['company_code']===$company
                &&$grant['target']['environment']===$env &&$grant['provider_id']===$provider &&$grant['mode']===$mode
                &&!empty($grant['readback_verified']))return true;
        }
        return false;
    }
    public static function routes():void {
        $ns='mad4b-company-credentials/v1';$permit=static fn()=>self::can();
        register_rest_route($ns,'/catalog',['methods'=>'GET','callback'=>static fn()=>rest_ensure_response(self::catalog()),'permission_callback'=>$permit]);
        register_rest_route($ns,'/grants',['methods'=>'GET','callback'=>static fn($r)=>rest_ensure_response(self::grants((int)($r['page']??1),(int)($r['per_page']??20))), 'permission_callback'=>$permit]);
        register_rest_route($ns,'/grants/plan',['methods'=>'POST','callback'=>static function($r){$x=self::plan((array)$r->get_json_params());return is_wp_error($x)?$x:rest_ensure_response($x);},'permission_callback'=>$permit]);
        register_rest_route($ns,'/grants',['methods'=>'POST','callback'=>static function($r){$x=self::draft((array)$r->get_json_params());return is_wp_error($x)?$x:rest_ensure_response($x);},'permission_callback'=>$permit]);
        foreach(['approve','activate','renew','revoke'] as $action){
            register_rest_route($ns,'/grants/(?P<grant_id>ccg\.[a-f0-9]{48})/'.$action,[
              'methods'=>'POST','callback'=>static function($r)use($action){$x=self::transition((string)$r['grant_id'],$action,(int)($r['expected_version']??0));return is_wp_error($x)?$x:rest_ensure_response($x);},
              'permission_callback'=>$permit]);
        }
    }
    public static function abilities():void {
        if(!function_exists('wp_register_ability'))return;
        foreach([
            'mad4b/company-credential-catalog'=>static fn($in)=>self::catalog(),
            'mad4b/company-credential-grants'=>static fn($in)=>self::grants((int)($in['page']??1),(int)($in['per_page']??20)),
        ] as $name=>$handler){
            if(function_exists('wp_has_ability')&&wp_has_ability($name))continue;
            wp_register_ability($name,[
                'label'=>$name,'description'=>'Central company credential metadata only; no key values.',
                'category'=>'mad4b-read','execute_callback'=>$handler,
                'permission_callback'=>static fn()=>self::can(),
                'input_schema'=>['type'=>'object','properties'=>['page'=>['type'=>'integer','minimum'=>1],
                    'per_page'=>['type'=>'integer','minimum'=>1,'maximum'=>100]],'required'=>[],'additionalProperties'=>false],
                'output_schema'=>['type'=>'object','additionalProperties'=>true],
                'meta'=>['public'=>false,'show_in_rest'=>false,'mcp'=>['public'=>false,'type'=>'tool','surface'=>'read'],
                    'annotations'=>['readonly'=>true,'idempotent'=>true,'destructive'=>false]],
            ]);
        }
    }
    public static function menu():void {
        if(!self::central())return;
        add_submenu_page('mad4b-control-plane','Company Credentials','Company Credentials',self::CAP,self::MENU,[__CLASS__,'render']);
        add_submenu_page('mad4b-company','Company Credentials','Company Credentials',self::CAP,'mad4b-company-credential-hub',[__CLASS__,'render']);
    }
    public static function handle_intent():void {
        if(!self::can())wp_die('Forbidden',403);
        check_admin_referer('mad4b_company_credential_intent');
        $i=[];
        $selector=sanitize_text_field(wp_unslash((string)($_POST['target_selector']??'')));
        $parts=explode('|',$selector);
        if(count($parts)!==3)wp_die('Invalid selected company environment','Credential plan rejected',400);
        [$i['company_code'],$i['environment'],$i['site_uuid']]=$parts;
        $selected=sanitize_text_field(wp_unslash((string)($_POST['provider_mode_selector']??'')));
        $choice=explode('|',$selected);
        if(count($choice)!==2)wp_die('Invalid credential provider mode','Credential plan rejected',400);
        [$i['provider_id'],$i['mode']]=$choice;
        foreach(['intent_key','purpose'] as $k)
            $i[$k]=sanitize_text_field(wp_unslash((string)($_POST[$k]??'')));
        $i['expires_days']=absint($_POST['expires_days']??30);
        $result=self::draft($i);
        if(is_wp_error($result))wp_die(esc_html($result->get_error_code()),'Credential plan rejected',400);
        wp_safe_redirect(admin_url('admin.php?page='.self::MENU.'&status=created'));exit;
    }
    public static function handle_transition():void {
        if(!self::can())wp_die('Forbidden',403);
        check_admin_referer('mad4b_company_credential_transition');
        $x=self::transition(sanitize_text_field((string)($_POST['grant_id']??'')),
            sanitize_key((string)($_POST['transition']??'')),absint($_POST['version']??0));
        if(is_wp_error($x))wp_die(esc_html($x->get_error_code()),'Credential operation denied',400);
        wp_safe_redirect(admin_url('admin.php?page='.self::MENU.'&status=updated'));exit;
    }
    public static function render():void {
        if(!self::can())wp_die('Forbidden',403);
        // Drafts are allowed for registry-known identities. Only LIVE_VERIFIED
        // targets can become active following independent provider readback.
        $targets=array_values(array_filter(self::company_targets(),static fn($t)=>$t['site_uuid_format_valid']));
        $catalog=self::catalog()['providers'];$list=self::grants(1,70);
        echo '<div class="wrap"><h1>Company Credential Manager</h1>';
        echo '<p>Company-scoped credential ownership and permitted use. <strong>No API key is displayed or submitted on this screen.</strong> The existing CSO Secret Handoff and qualified native provider adapters remain responsible for any actual secret materialization.</p>';
        if(class_exists('MAD4B_Secure_Credential_Envelope')){
            $identity=MAD4B_Secure_Credential_Envelope::public_status();
            echo '<p><strong>Central signing identity:</strong> '.esc_html((string)($identity['state']??'UNAVAILABLE')).
                 ' &nbsp; <strong>Private key displayed:</strong> No &nbsp; <strong>Production secret transfer:</strong> Not enabled.</p>';
        }
        if(class_exists('MAD4B_Configuration_Fleet')){
            $fleet=MAD4B_Configuration_Fleet::snapshot();$a=(array)($fleet['credential_recovery']??[]);
            echo '<h2>Encrypted key recovery assurance</h2><p><strong>Locally verified key backups:</strong> '.
                (int)($a['verified_current_key_backups']??0).' / '.(int)($a['registered_local_sites']??0).
                ' &nbsp; <strong>Off-host recovery escrow:</strong> '.
                esc_html((string)($a['offhost_escrow_status']??'NOT_VERIFIED')).
                ' &nbsp; <strong>Audit freshness:</strong> '.(!empty($a['fresh'])?'Current':'Unverified').'</p>';
        }
        echo '<h2>Register a governed credential intent</h2>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('mad4b_company_credential_intent');
        echo '<input type="hidden" name="action" value="mad4b_company_credential_intent">';
        echo '<table class="form-table"><tbody><tr><th>Company and environment</th><td><select name="target_selector" required>';
        foreach($targets as $i=>$t)echo '<option value="'.esc_attr($t['company_code'].'|'.$t['environment'].'|'.$t['site_uuid']).'">'.
            esc_html($t['company_name'].' — '.$t['environment'].' — '.($t['site_verified']?'Live verified':'Registry only; not live verified')).'</option>';
        echo '</select><p class="description">Registered identity is not live enrollment proof. Unverified targets may only have drafts; native activation requires independent verified adapter evidence.</p></td></tr>';
        echo '<tr><th>Provider and distribution mode</th><td><select name="provider_mode_selector" required>';
        foreach($catalog as $id=>$p)foreach($p['allowed_modes'] as $mode)
            echo '<option value="'.esc_attr($id.'|'.$mode).'">'.esc_html($p['label'].' — '.$mode).'</option>';
        echo '</select><p class="description">The choice is restricted to modes explicitly supported by the registered provider.</p></td></tr>';
        echo '<tr><th>Purpose</th><td><input class="regular-text" name="purpose" value="company_provider_maintenance" required></td></tr>';
        echo '<tr><th>Idempotency key</th><td><input class="regular-text" name="intent_key" minlength="8" placeholder="maxmind-wayfa-initial" required> Same intent may be safely retried.</td></tr>';
        echo '<tr><th>Lifetime</th><td><input type="number" name="expires_days" min="1" max="90" value="30"> days</td></tr></tbody></table>';
        submit_button('Create credential policy draft (no secret transfer)');echo '</form>';
        echo '<h2>Credential grants</h2><table class="widefat striped"><thead><tr><th>Company</th><th>Environment</th><th>Provider</th><th>Mode</th><th>State</th><th>Expires</th><th>Action</th></tr></thead><tbody>';
        foreach($list['items'] as $g){
            echo '<tr><td>'.esc_html($g['target']['company_name']).'</td><td>'.esc_html($g['target']['environment']).'</td><td>'.esc_html($g['provider_id']).'</td><td>'.esc_html($g['mode']).'</td><td>'.esc_html($g['state']).'</td><td>'.esc_html($g['expires_at']).'</td><td>';
            $actions=['DRAFT'=>['approve','revoke'],'APPROVED'=>['activate','revoke'],
                      'ACTIVE'=>['renew','revoke']][$g['state']]??[];
            $vendorPending=false;
            if($g['state']==='APPROVED' &&
                in_array($g['provider_id'],['wp_all_import_pro','wp_all_export_pro','fluent_forms_pro'],true)){
                $proof=self::ready_evidence((array)$g['target'],(string)$g['provider_id'],(string)$g['mode']);
                if(empty($proof['verified'])){
                    $actions=array_values(array_diff($actions,['activate']));
                    $vendorPending=true;
                }
            }
            foreach($actions as $action){echo '<form method="post" style="display:inline-block;margin-right:4px" action="'.esc_url(admin_url('admin-post.php')).'">';
                wp_nonce_field('mad4b_company_credential_transition');
                echo '<input type="hidden" name="action" value="mad4b_company_credential_transition">';
                echo '<input type="hidden" name="grant_id" value="'.esc_attr($g['id']).'">';
                echo '<input type="hidden" name="version" value="'.(int)$g['version'].'">';
                echo '<input type="hidden" name="transition" value="'.esc_attr($action).'">';
                echo '<button class="button button-small" type="submit">'.esc_html(ucfirst($action)).'</button></form>';
            }
            if($vendorPending){
                $statusUrl=add_query_arg(['page'=>'mad4b-plugin-license-readiness',
                    'company_code'=>(string)$g['target']['company_code'],
                    'environment'=>(string)$g['target']['environment']],admin_url('admin.php'));
                echo '<a class="button button-small" href="'.esc_url($statusUrl).'">Check remote activation</a> ';
                echo '<span class="description">Grant approved; awaiting verified vendor receipt.</span>';
            }
            if(!$actions)echo '&mdash;';
            echo '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
}
MAD4B_Company_Credential_Manager::boot();

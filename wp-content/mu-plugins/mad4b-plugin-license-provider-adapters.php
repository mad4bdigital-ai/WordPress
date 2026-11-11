<?php
/**
 * Plugin Name: MAD4B Plugin License Provider Adapters
 * Description: Nonsecret plugin-license discovery and governed company catalog, without credential transfer.
 * Version: 0.8.0
 */
if (!defined('ABSPATH')) exit;
final class MAD4B_Plugin_License_Provider_Adapters {
    const CONTRACT='mad4b.plugin-license-providers.v1';
    private static function definitions():array {
        return [
            'wp_all_import_pro'=>['slug'=>'wp-all-import-pro','label'=>'WP All Import Pro','kind'=>'license_key'],
            'wp_all_export_pro'=>['slug'=>'wp-all-export-pro','label'=>'WP All Export Pro','kind'=>'license_key'],
            'ultimate_addons_elementor'=>['slug'=>'ultimate-elementor','label'=>'Ultimate Addons for Elementor','kind'=>'license_key'],
            'fluent_forms_pro'=>['slug'=>'fluentformpro','label'=>'Fluent Forms Pro','kind'=>'license_key'],
            'elementor_pro'=>['slug'=>'elementor-pro','label'=>'Elementor Pro','kind'=>'account_linked_license'],
        ];
    }
    private static function plugin_active(string $slug):bool {
        foreach((array)get_option('active_plugins',[]) as $name)
            if(is_string($name)&&str_starts_with($name,$slug.'/'))return true;
        return false;
    }
    private static function status(bool $present,string $cached,bool $recent=false,bool $active=true):string {
        if(!$active)return 'PLUGIN_INACTIVE_LICENSE_UNVERIFIED';
        if(!$present)return 'LICENSE_NOT_CONFIGURED';
        if($cached==='valid' && $recent)return 'CACHED_VALID_RECENT_NOT_INDEPENDENTLY_ATTESTED';
        if($cached==='valid')return 'CACHED_VALID_FRESHNESS_UNVERIFIED';
        if(in_array($cached,['expired','disabled','invalid','limit_reached','host_mismatch','unknown_key','no_key'],true))
            return 'CACHED_LICENSE_REQUIRES_REVIEW';
        return 'KEY_PRESENT_VENDOR_VALIDATION_REQUIRED';
    }
    public static function inspect():array {
        $out=[];
        // Read the plugin's canonical persisted option, not a singleton's
        // request-locally cached property. MU-plugins can be called before
        // WP All Import/Export finish their REST/MCP bootstrap, while CLI
        // initializes those singleton caches in a different order.
        // get_option() is read-only; the raw values never leave this method.
        $import=(array)get_option('PMXI_Plugin_Options',[]);
        $export=(array)get_option('PMXE_Plugin_Options',[]);
        $fluent=(array)get_option('__fluentformpro_license',[]);
        $brainstorm=(array)get_option('brainstrom_products',[]);
        $ultimate=[];
        foreach((array)($brainstorm['plugins']??[]) as $p)
            if(is_array($p)&&sanitize_key((string)($p['slug']??''))==='ultimate-elementor'){$ultimate=$p;break;}
        $keyed=[
            'wp_all_import_pro'=>[
                !empty($import['licenses']['PMXI_Plugin']),
                (string)($import['statuses']['PMXI_Plugin']??''),
                (int)($import['license_checked_at']??0)],
            'wp_all_export_pro'=>[
                !empty($export['license']),
                (string)($export['license_status']??''),
                (int)($export['license_checked_at']??0)],
            'ultimate_addons_elementor'=>[
                !empty($ultimate['purchase_key']), '', 0],
            'fluent_forms_pro'=>[
                !empty($fluent['license_key']), (string)($fluent['status']??''),0],
            'elementor_pro'=>[
                (string)get_option('elementor_pro_license_key','')!=='','',0],
        ];
        foreach(self::definitions() as $id=>$definition){
            $active=self::plugin_active($definition['slug']);
            [$present,$cached,$checked]=$keyed[$id];
            $recent=$checked>0 && $checked>=time()-DAY_IN_SECONDS && $checked<=time()+300;
            $state=self::status($present,$cached,$recent,$active);
            // Elementor's vendor authorization is account/domain bound; the
            // existence of a local key must not be treated as account proof.
            if($id==='elementor_pro' && $active && $present)
                $state='ACCOUNT_LINKED_LICENSE_VALIDATION_REQUIRED';
            $out[$id]=[
                'state'=>$state,'plugin_installed_and_active'=>$active,
                'local_license_key_present'=>(bool)$present,
                'native_cached_valid'=>(bool)($cached==='valid'),
                'native_cached_check_recent'=>(bool)$recent,
                'license_kind'=>$definition['kind'],
                'vendor_account_verified'=>false,
                'vendor_live_test_performed'=>false,
                'company_entitlement_verified'=>false,
                'cross_company_license_transfer_enabled'=>false,
                'native_activation_enabled'=>false,
                'secret_value_exposed'=>false,
            ];
        }
        return ['contract'=>self::CONTRACT,'providers'=>$out,
            'secrets_included'=>false,'independent_vendor_verification_completed'=>false];
    }
    public static function extend_catalog(array $original):array {
        foreach(self::definitions() as $id=>$def){
            // Public capability declaration only. No key writes or API calls.
            $original[$id]=[
                'available'=>self::plugin_active($def['slug']),
                'mode'=>'company_owned','license_kind'=>$def['kind'],
                'native_stage'=>false,'native_activation'=>'provider_specific_entitlement_and_vendor_verification_required',
                'production_key_materialization_enabled'=>false,
                'vendor_license_account_owner_verified'=>false,
            ];
        }
        return $original;
    }
    public static function extend_health(array $original):array {
        foreach(self::inspect()['providers'] as $id=>$data)$original[$id]=$data;
        return $original;
    }
    public static function extend_qualification(array $original):array {
        foreach(self::inspect()['providers'] as $id=>$data)
            $original[$id]=[
                'state'=>$data['state'],'vendor_live_test_performed'=>false,
                'company_entitlement_verified'=>false,
                'direct_vendor_key_installation_allowed'=>false,
                'license_kind'=>$data['license_kind'],
            ];
        return $original;
    }
    /** Central-only nonsecret provider entitlement matrix, bound to Fleet. */
    public static function readiness(string $companyCode='',string $environment=''):array {
        // Select the current live-verified company from the registry when no
        // explicit site is requested. Avoid hardcoding an individual tenant.
        if($companyCode==='' && class_exists('MAD4B_Company_Credential_Manager')){
            $targets=MAD4B_Company_Credential_Manager::company_targets();
            $selected=null;
            $centralProfile=(array)get_option('mad4b_scp_site_profile_v2',[]);
            $centralUuid=(string)($centralProfile['site_uuid']??'');
            foreach($targets as $t){
                if(empty($t['site_verified']) || ($t['site_uuid']??'')===$centralUuid)continue;
                if(($t['environment']??'')==='production'){$selected=$t;break;}
                if($selected===null)$selected=$t;
            }
            if($selected!==null){
                $companyCode=(string)$selected['company_code'];
                $environment=(string)$selected['environment'];
            }
        }
        $result=['contract'=>'mad4b.plugin-license-readiness.v1',
            'company_code'=>sanitize_key($companyCode),
            'environment'=>sanitize_key($environment),
            'state'=>'COMPANY_NOT_VERIFIED','source_site_uuid'=>null,
            'fleet_evidence_current'=>false,'provider_rows'=>[],
            'ready_for_real_license_activation'=>false,
            'credential_values_included'=>false,'vendor_entitlement_verified'=>false,
            'remote_execution'=>['state'=>'ORIGINAL313_RUNTIME_NOT_VERIFIED',
                'registry_enrolled'=>false,'original306_dispatcher_published'=>false,
                'existing_public_signers_pinned'=>false,
                'company_credential_channel_production_admitted'=>false,
                'operational'=>false]];
        if(!class_exists('MAD4B_Company_Credential_Manager') ||
           !MAD4B_Company_Credential_Manager::central() ||
           !in_array($environment,['production','staging','development','local'],true))return $result;
        $targets=array_values(array_filter(MAD4B_Company_Credential_Manager::company_targets(),
            static fn($t)=>($t['company_code']??null)===$companyCode &&
                    ($t['environment']??null)===$environment));
        if(count($targets)!==1)return $result;
        $target=$targets[0];
        $centralProfile=(array)get_option('mad4b_scp_site_profile_v2',[]);
        if(($target['site_uuid']??'')===($centralProfile['site_uuid']??null)){
            $result['state']='CENTRAL_SOURCE_NOT_A_COMPANY_RECIPIENT';return $result;
        }
        $result['site_uuid_format_valid']=!empty($target['site_uuid_format_valid']);
        $result['target_live_verified']=!empty($target['site_verified']);
        $result['state']=$result['target_live_verified']?'TARGET_LIVE_VERIFIED':'TARGET_NOT_LIVE_VERIFIED';
        if(!class_exists('MAD4B_Configuration_Fleet'))return $result;
        $snapshot=MAD4B_Configuration_Fleet::snapshot();
        $result['fleet_evidence_current']=!empty($snapshot['snapshot_fresh']);
        if(empty($snapshot['snapshot_fresh']))return $result;
        $runtime=(array)($snapshot['plugin_license_remote']??[]);
        if(($runtime['contract']??'')==='mad4b.plugin-license-remote-runtime.v1' &&
           ($runtime['operation_id']??'')==='plugin.license.activate' &&
           ($runtime['sensitive_values_included']??null)===false){
            $result['remote_execution']=[
                'state'=>sanitize_key((string)($runtime['state']??'not_verified')),
                'registry_enrolled'=>($runtime['registry_enrolled']??null)===true,
                'original306_dispatcher_published'=>($runtime['original306_dispatcher_published']??null)===true,
                'existing_public_signers_pinned'=>($runtime['existing_public_signers_pinned']??null)===true,
                'company_credential_channel_production_admitted'=>($runtime['company_credential_channel_production_admitted']??null)===true,
                'vendor_entitlement_independently_verified'=>($runtime['vendor_entitlement_independently_verified']??null)===true,
                'operational'=>($runtime['operational']??null)===true,
            ];
        }
        $targetEvidence=null;
        foreach((array)($snapshot['sites']??[]) as $site){
            if(($site['coverage']??'')!=='verified')continue;
            if(($site['company_code']??'')===$companyCode &&
               ($site['environment']??'')===$environment &&
               ($site['site_uuid']??'')===($target['site_uuid']??null)){$targetEvidence=$site;break;}
        }
        if(!$result['target_live_verified']||!is_array($targetEvidence))return $result;
        $source=self::inspect()['providers'];
        $remote=(array)($targetEvidence['credential_provider_qualification']['states']??[]);
        $nativeReceipts=(array)($targetEvidence['credential_native_plugin_activation']['provider_states']??[]);
        $requirements=[
            'wp_all_import_pro'=>['vendor_license_owner_evidence','actual_development_client_relationship','no_hosted_service_bundling','vendor_account_license_check'],
            'wp_all_export_pro'=>['vendor_license_owner_evidence','actual_development_client_relationship','no_hosted_service_bundling','vendor_account_license_check'],
            'ultimate_addons_elementor'=>['brainstorm_force_license_owner','vendor_plan_site_seat_available','native_activation_readback'],
            'fluent_forms_pro'=>['fluent_forms_license_owner','vendor_plan_site_seat_available','native_activation_readback'],
            'elementor_pro'=>['elementor_account_owner','subscription_has_available_domain_seat','vendor_account_connection_and_readback'],
        ];
        foreach(self::definitions() as $id=>$info){
            $src=(string)($source[$id]['state']??'SOURCE_UNAVAILABLE');
            $dst=(string)($remote[$id]['state']??'TARGET_EVIDENCE_NOT_COLLECTED');
            $sourcePresent=!empty($source[$id]['local_license_key_present']);
            $targetPresent=$dst!=='license_not_configured' && $dst!=='target_evidence_not_collected';
            $state='ENTITLEMENT_AND_VENDOR_VERIFICATION_REQUIRED';
            if($dst==='TARGET_EVIDENCE_NOT_COLLECTED')$state='TARGET_PLUGIN_EVIDENCE_MISSING';
            elseif(!$sourcePresent && !$targetPresent)$state='LICENSE_UNAVAILABLE_IN_CURRENT_LOCAL_STORES';
            elseif($id==='elementor_pro')$state='ACCOUNT_LINKED_ACTIVATION_AND_SEAT_CHECK_REQUIRED';
            elseif($targetPresent)$state='TARGET_LICENSE_PRESENT_VENDOR_ATTESTATION_REQUIRED';
            elseif(!$sourcePresent)$state='COMPANY_OWNED_LICENSE_ONBOARDING_REQUIRED';
            $nativeLocal=$dst!=='TARGET_EVIDENCE_NOT_COLLECTED' &&
                $dst!=='plugin_inactive_license_unverified' &&
                $dst!=='PLUGIN_INACTIVE_LICENSE_UNVERIFIED';
            $recipientMethod=$id==='elementor_pro'?'vendor_account_connect':
                ($id==='ultimate_addons_elementor'?'official_uae_license_settings':'native_vendor_key_form');
            $sourceTransport=$sourcePresent?'PRODUCTION_SECRET_TRANSPORT_NOT_ADMITTED':
                'NO_SOURCE_LICENSE_IN_NATIVE_STORE';
            $result['provider_rows'][$id]=[
                'provider'=>$id,'license_kind'=>$info['kind'],
                'source_local_state'=>$src,'target_local_state'=>$dst,
                'readiness_state'=>$state,
                'required_evidence'=>$requirements[$id],
                'company_owned_draft_allowed'=>true,'central_delegated_draft_allowed'=>true,
                'vendor_entitlement_attested'=>false,
                'activation_allowed'=>false,'real_license_key_transfer_allowed'=>false,
                'recipient_native_activation_available'=>$nativeLocal,
                'recipient_native_activation_method'=>$recipientMethod,
                'recipient_activation_requires_central_approval'=>false,
                'source_transfer_status'=>$sourceTransport,
                'recipient_native_receipt_state'=>(string)($nativeReceipts[$id]['state']??'vendor_receipt_not_reported'),
                'recipient_vendor_receipt_and_current_license_valid'=>!empty($nativeReceipts[$id]['vendor_receipt_and_current_license_valid']),
                'status_requires_native_vendor_readback'=>true,
                'sensitive_values_included'=>false];
        }
        $result['state']='READINESS_EVALUATED_ACTIVATION_NOT_AUTHORIZED';
        return $result;
    }
    public static function readiness_ability():void {
        if(!function_exists('wp_register_ability') ||
           !class_exists('MAD4B_Company_Credential_Manager') ||
           !MAD4B_Company_Credential_Manager::central())return;
        $name='mad4b/company-plugin-license-readiness';
        if(function_exists('wp_has_ability') && wp_has_ability($name))return;
        wp_register_ability($name,[
            'label'=>'Review company plugin license entitlement readiness',
            'description'=>'Read-only source/target license states and policy requirements; no license keys.',
            'category'=>'mad4b-read',
            'execute_callback'=>static function($in){
                return self::readiness((string)($in['company_code']??''),
                                       (string)($in['environment']??''));
            },
            'permission_callback'=>static fn()=>current_user_can('manage_options'),
            'input_schema'=>['type'=>'object','properties'=>[
               'company_code'=>['type'=>'string','minLength'=>2,'maxLength'=>80],
               'environment'=>['type'=>'string','enum'=>['production','staging','development','local']]],
               'required'=>[],'additionalProperties'=>false],
            'output_schema'=>['type'=>'object','additionalProperties'=>true],
            'meta'=>['public'=>false,'show_in_rest'=>false,'mcp'=>['public'=>false,'type'=>'tool','surface'=>'read'],
                'annotations'=>['readonly'=>true,'idempotent'=>true,'destructive'=>false]],
        ]);
    }
    public static function menu():void {
        if(!class_exists('MAD4B_Company_Credential_Manager') ||
           !MAD4B_Company_Credential_Manager::central())return;
        add_submenu_page('mad4b-company-credentials', 'Plugin License Readiness',
            'Plugin License Readiness','manage_options','mad4b-plugin-license-readiness',
            [__CLASS__,'render_readiness']);
    }
    /** A real actionable transfer REQUEST, not an unverified secret copy. */
    public static function request_transfer(array $input):array|WP_Error {
        if(!current_user_can('manage_options') ||
            !class_exists('MAD4B_Company_Credential_Manager') ||
            !MAD4B_Company_Credential_Manager::central())
            return new WP_Error('mad4b_license_request_forbidden','Authorized central operator required.');
        $company=sanitize_key((string)($input['company_code']??''));
        $env=sanitize_key((string)($input['environment']??''));
        $provider=sanitize_key((string)($input['provider_id']??''));
        $mode=sanitize_key((string)($input['mode']??''));
        if(!isset(self::definitions()[$provider]) ||
           !in_array($mode,['company_owned','delegated_service'],true) ||
           !in_array($env,['production','staging','development','local'],true))
            return new WP_Error('mad4b_license_request_input_invalid','Invalid provider, environment, or license mode.');
        $read=self::readiness($company,$env);
        if(empty($read['target_live_verified']) ||
           empty($read['fleet_evidence_current']) ||
           !isset($read['provider_rows'][$provider]))
            return new WP_Error('mad4b_license_target_not_verified','Live company identity and plugin status must be verified before requesting a transfer.');
        if($mode==='delegated_service' &&
           in_array($read['provider_rows'][$provider]['source_local_state'],
              ['LICENSE_NOT_CONFIGURED','PLUGIN_INACTIVE_LICENSE_UNVERIFIED','SOURCE_UNAVAILABLE'],true))
            return new WP_Error('mad4b_license_source_missing','No source license is configured for a delegated request.');
        // No source secret is read or serialized. The Manager owns idempotency,
        // recipient identity, auditable DRAFT records and expiry.
        $targets=array_values(array_filter(MAD4B_Company_Credential_Manager::company_targets(),
            static fn($t)=>($t['company_code']??null)===$company &&
             ($t['environment']??null)===$env && !empty($t['site_verified'])));
        if(count($targets)!==1)return new WP_Error('mad4b_license_target_identity_changed','Target identity changed; refresh the company evidence.');
        $site=(string)$targets[0]['site_uuid'];
        // Reuse a pending request instead of multiplying drafts on repeat clicks.
        $existing=self::transfer_requests($company,$env);
        if(isset($existing[$provider]) &&
           in_array($existing[$provider]['state'],['DRAFT','APPROVED','ACTIVE'],true))
            return ['status'=>'already_exists','provider_id'=>$provider,
                'company_code'=>$company,'environment'=>$env,'mode'=>$existing[$provider]['mode'],
                'state'=>$existing[$provider]['state'],
                'grant_id'=>$existing[$provider]['grant_id'],
                'secret_copied'=>false,'provider_activated'=>false,
                'next_step'=>'REVIEW_VENDOR_LICENSE_ENTITLEMENT'];
        // Same pending attempt always has the same identity under concurrent
        // clicks; a revoked/expired attempt can be retried with a new identity
        // derived from the previous terminal grant, not a random new draft.
        $previous=(string)($existing[$provider]['grant_id']??'initial');
        $intent='plugin-license-transfer-'.substr(hash('sha256',
            $company.'|'.$env.'|'.$site.'|'.$provider.'|'.$mode.'|'.$previous),0,30);
        $created=MAD4B_Company_Credential_Manager::draft([
            'company_code'=>$company,'environment'=>$env,'site_uuid'=>$site,
            'provider_id'=>$provider,'mode'=>$mode,
            'intent_key'=>$intent,'purpose'=>'plugin_license_entitlement_review',
            'expires_days'=>30]);
        if(is_wp_error($created))return $created;
        return ['status'=>(string)($created['status']??'unknown'),
           'provider_id'=>$provider,'company_code'=>$company,'environment'=>$env,
           'mode'=>$mode,'state'=>(string)($created['grant']['state']??'UNKNOWN'),
           'grant_id'=>(string)($created['grant']['id']??''),
           'secret_copied'=>false,'provider_activated'=>false,
           'next_step'=>'REVIEW_VENDOR_LICENSE_ENTITLEMENT'];
    }
    public static function handle_transfer_request():void {
        if(!current_user_can('manage_options'))wp_die('Forbidden',403);
        check_admin_referer('mad4b_plugin_license_transfer_request');
        $data=[];
        foreach(['company_code','environment','provider_id','mode'] as $key)
            $data[$key]=sanitize_key(wp_unslash((string)($_POST[$key]??'')));
        $result=self::request_transfer($data);
        if(is_wp_error($result)){
            wp_die(esc_html($result->get_error_code()),'License transfer request rejected',400);
        }
        $redirect=add_query_arg([
            'page'=>'mad4b-plugin-license-readiness',
            'company_code'=>$result['company_code'],
            'environment'=>$result['environment'],
            'request_status'=>$result['status'],
            'request_provider'=>$result['provider_id'],
            'request_grant'=>$result['grant_id']
        ],admin_url('admin.php'));
        wp_safe_redirect($redirect);exit;
    }
    public static function transfer_requests(string $company,string $environment):array {
        if(!current_user_can('manage_options') ||
            !class_exists('MAD4B_Company_Credential_Manager'))return [];
        $list=MAD4B_Company_Credential_Manager::grants(1,100);
        if(is_wp_error($list))return [];
        $requests=[];
        foreach((array)($list['items']??[]) as $grant){
            if(($grant['target']['company_code']??'')!==$company ||
                ($grant['target']['environment']??'')!==$environment ||
                ($grant['purpose']??'')!=='plugin_license_entitlement_review' ||
                !isset(self::definitions()[$grant['provider_id']??'']))continue;
            $id=(string)$grant['provider_id'];
            if(!isset($requests[$id]))$requests[$id]=[
                'state'=>(string)($grant['state']??'UNKNOWN'),
                'mode'=>(string)($grant['mode']??''),
                'grant_id'=>(string)($grant['id']??''),
                'credential_copied'=>false];
        }
        return $requests;
    }
    /** Trusted company URL from verified Fleet AND Company Registry. */
    private static function target_native_activation_url(string $company,string $env,string $provider):string {
        if(!isset(self::definitions()[$provider]) ||
            !class_exists('MAD4B_Company_Credential_Manager') ||
            !class_exists('MAD4B_Configuration_Fleet'))return '';
        $registry=(array)get_option(MAD4B_Company_Credential_Manager::REGISTRY,[]);
        $entry=(array)($registry['companies'][$company]['environments'][$env]??[]);
        $origin=rtrim((string)($entry['canonical_origin']??''),'/');
        $parts=wp_parse_url($origin);
        if(!is_array($parts)||($parts['scheme']??'')!=='https'||
            empty($parts['host']) || !empty($parts['user'])||!empty($parts['pass'])||
            !empty($parts['port'])||!empty($parts['query'])||!empty($parts['fragment'])||
            !in_array((string)($parts['path']??''),['','/'],true))return '';
        $fleet=MAD4B_Configuration_Fleet::snapshot();
        if(empty($fleet['snapshot_fresh']))return '';
        $expectedUuid=(string)($entry['site_uuid']??'');
        $centralProfile=(array)get_option('mad4b_scp_site_profile_v2',[]);
        if($expectedUuid==='' || $expectedUuid===($centralProfile['site_uuid']??null))return '';
        foreach((array)($fleet['sites']??[]) as $site){
            if(($site['coverage']??'')!=='verified' ||
               ($site['company_code']??'')!==$company ||
               ($site['environment']??'')!==$env ||
               ($site['site_uuid']??'')!==$expectedUuid)continue;
            $admin=wp_parse_url((string)($site['admin_url']??''));
            if(!is_array($admin)||($admin['scheme']??'')!=='https'||
                strcasecmp((string)($admin['host']??''),(string)$parts['host'])!==0)return '';
            return add_query_arg(['page'=>'mad4b-plugin-native-license-activation',
                'provider'=>$provider],$origin.'/wp-admin/admin.php');
        }
        return '';
    }
    /** A first-party remote activation preflight, with NO credential transfer.
     * This does not pretend to execute unregistered 312/313 write operations.
     */
    public static function remote_activation_preflight(string $company,string $environment,string $provider):array|WP_Error {
        if(!current_user_can('manage_options') ||
           !class_exists('MAD4B_Company_Credential_Manager') ||
           !MAD4B_Company_Credential_Manager::central())
            return new WP_Error('mad4b_remote_activation_denied','Authorized central operator required.');
        if(!isset(self::definitions()[$provider]) ||
           !in_array($environment,['production','staging','development','local'],true))
            return new WP_Error('mad4b_remote_activation_input_invalid','Invalid company environment or provider.');
        $read=self::readiness($company,$environment);
        if(empty($read['target_live_verified']) || empty($read['fleet_evidence_current']) ||
           !isset($read['provider_rows'][$provider]))
            return new WP_Error('mad4b_remote_recipient_not_verified','Recipient identity or current Fleet evidence is unavailable.');
        $row=$read['provider_rows'][$provider];
        // A current recipient vendor receipt and valid native state is a
        // completed outcome; don't demand another remote activation.
        if(!empty($row['recipient_vendor_receipt_and_current_license_valid'])){
            return [
                'contract'=>'mad4b.plugin-license-remote-activation-preflight.v1',
                'company_code'=>$company,'environment'=>$environment,'provider_id'=>$provider,
                'state'=>'ALREADY_ACTIVE_NATIVE_VENDOR_VERIFIED','blockers'=>[],
                'recipient_verified'=>true,'fleet_snapshot_fresh'=>true,
                'recipient_native_receipt_state'=>(string)$row['recipient_native_receipt_state'],
                'recipient_vendor_current_license_valid'=>true,
                'source_credential_transfer_executed'=>false,
                'company_settings_changed'=>false,
                'real_vendor_activation_performed'=>false,
                'remote_executor_invoked'=>false,
                'external_navigation_required'=>false,
                'sensitive_values_included'=>false,
            ];
        }
        $blockers=[];
        if(empty($row['recipient_native_activation_available']))$blockers[]='TARGET_VENDOR_ADAPTER_NOT_READY';
        if(($row['target_local_state']??'')==='license_not_configured')$blockers[]='TARGET_LICENSE_NOT_CONFIGURED';
        if(($row['source_transfer_status']??'')==='PRODUCTION_SECRET_TRANSPORT_NOT_ADMITTED')
            $blockers[]='SOURCE_PRODUCTION_CREDENTIAL_TRANSPORT_NOT_ADMITTED';
        if(($row['source_transfer_status']??'')==='NO_SOURCE_LICENSE_IN_NATIVE_STORE')
            $blockers[]='NO_SOURCE_VENDOR_CREDENTIAL';
        if($provider==='elementor_pro')$blockers[]='VENDOR_ACCOUNT_CONNECTION_REQUIRED';
        $runtime=(array)($read['remote_execution']??[]);
        if(empty($runtime['registry_enrolled']))
            $blockers[]='ORIGINAL313_PROVIDER_NOT_REGISTERED_OR_NOT_VERIFIED';
        elseif(empty($runtime['original306_dispatcher_published']))
            $blockers[]='ORIGINAL306_PROVIDER_DISPATCHER_NOT_PUBLISHED';
        elseif(empty($runtime['existing_public_signers_pinned']))
            $blockers[]='ORIGINAL306_SIGNER_PUBLIC_TRUST_NOT_VERIFIED';
        else {
            // Registration and public trust are live, but not a signed Job or
            // vendor-entitlement authorization for real secret transport.
            if(empty($runtime['company_credential_channel_production_admitted']))
                $blockers[]='PRODUCTION_CREDENTIAL_CHANNEL_NOT_ADMITTED';
            if(empty($runtime['vendor_entitlement_independently_verified']))
                $blockers[]='VENDOR_SITE_LICENSE_RIGHTS_NOT_INDEPENDENTLY_VERIFIED';
            if(empty($runtime['operational']))
                $blockers[]='REMOTE_PLUGIN_LICENSE_EXECUTOR_NOT_OPERATIONAL';
        }
        return [
            'contract'=>'mad4b.plugin-license-remote-activation-preflight.v1',
            'company_code'=>$company,'environment'=>$environment,'provider_id'=>$provider,
            'state'=>'REMOTE_ACTIVATION_BLOCKED',
            'blockers'=>array_values(array_unique($blockers)),
            'recipient_verified'=>true,'fleet_snapshot_fresh'=>true,
            'original313_registration_state'=>(string)($read['remote_execution']['state']??'not_verified'),
            'native_vendor_activation_on_recipient_available'=>!empty($row['recipient_native_activation_available']),
            'recipient_native_receipt_state'=>(string)$row['recipient_native_receipt_state'],
            'recipient_vendor_current_license_valid'=>!empty($row['recipient_vendor_receipt_and_current_license_valid']),
            'source_credential_transfer_executed'=>false,
            'company_settings_changed'=>false,
            'real_vendor_activation_performed'=>false,
            'remote_executor_invoked'=>false,
            'external_navigation_required'=>false,
            'sensitive_values_included'=>false,
        ];
    }
    public static function handle_remote_activation_preflight():void {
        if(!current_user_can('manage_options'))wp_die('Forbidden',403);
        check_admin_referer('mad4b_plugin_remote_activation_preflight');
        $company=sanitize_key((string)($_POST['company_code']??''));
        $environment=sanitize_key((string)($_POST['environment']??''));
        $provider=sanitize_key((string)($_POST['provider_id']??''));
        $result=self::remote_activation_preflight($company,$environment,$provider);
        $ticket=wp_generate_password(32,false,false);
        $key='mad4b_plugin_remote_preflight_'.get_current_user_id().'_'.substr(hash('sha256',$ticket),0,24);
        $stored=is_wp_error($result)?[
            'state'=>'PREFLIGHT_REJECTED',
            'provider_id'=>$provider,
            'blockers'=>[$result->get_error_code()],
            'real_vendor_activation_performed'=>false,
        ]:$result;
        set_transient($key,$stored,5*MINUTE_IN_SECONDS);
        // Redirect only to central admin; never to the recipient site.
        wp_safe_redirect(add_query_arg([
           'page'=>'mad4b-plugin-license-readiness',
           'company_code'=>$company,'environment'=>$environment,
           'remote_preflight_ticket'=>$ticket,
        ],admin_url('admin.php')));
        exit;
    }
    public static function render_readiness():void {
        if(!current_user_can('manage_options'))wp_die('Forbidden',403);
        $targets=MAD4B_Company_Credential_Manager::company_targets();
        $company=sanitize_key((string)($_GET['company_code']??''));
        $environment=sanitize_key((string)($_GET['environment']??''));
        if($company!=='' && $environment!==''){
            $allowed=false;foreach($targets as $t){
                if(($t['company_code']??null)===$company && ($t['environment']??null)===$environment){$allowed=true;break;}
            }
            if(!$allowed){$company='';$environment='';}
        }else{$company='';$environment='';}
        $read=self::readiness($company,$environment);
        $company=$read['company_code'];$environment=$read['environment'];
        echo '<div class="wrap"><h1>Company Plugin License Readiness</h1>';
        echo '<p>Independent vendor validation and subscription rights are required before activation. No license value is displayed or transferred.</p>';
        echo '<p><strong>Company:</strong> '.esc_html($company).
            ' &nbsp; <strong>Environment:</strong> '.esc_html($environment).
            ' &nbsp; <strong>Site evidence:</strong> '.esc_html((string)$read['state']).'</p>';
        echo '<form method="get"><input type="hidden" name="page" value="mad4b-plugin-license-readiness">';
        echo '<select name="company_code">';
        $companies=[];foreach($targets as $t)$companies[$t['company_code']]=true;
        foreach($companies as $name=>$unused)echo '<option value="'.esc_attr($name).'"'.selected($company,$name,false).'>'.esc_html($name).'</option>';
        echo '</select><select name="environment">';
        foreach(['production','staging','development','local'] as $name)echo '<option value="'.esc_attr($name).'"'.selected($environment,$name,false).'>'.esc_html($name).'</option>';
        echo '</select><button type="submit" class="button">Inspect license readiness</button></form>';
        $ticket=sanitize_text_field((string)($_GET['remote_preflight_ticket']??''));
        if(strlen($ticket)===32 && ctype_alnum($ticket)){
            $key='mad4b_plugin_remote_preflight_'.get_current_user_id().'_'.substr(hash('sha256',$ticket),0,24);
            $report=get_transient($key);
            if(is_array($report) && ($report['company_code']??$company)===$company &&
                ($report['environment']??$environment)===$environment){
                delete_transient($key);
                echo '<div class="notice notice-warning"><p><strong>Remote activation check: '.
                   esc_html((string)$report['state']).' — '.
                   esc_html((string)($report['provider_id']??'')).'</strong></p>';
                foreach((array)($report['blockers']??[]) as $reason)
                    echo '<p>'.esc_html((string)$reason).'</p>';
                echo '<p>No remote credential was transferred, no vendor activation executed, and no company license setting was changed.</p></div>';
            }
        }
        if(isset($_GET['request_status']) && in_array((string)$_GET['request_status'],['created','already_exists'],true)){
            $p=sanitize_key((string)($_GET['request_provider']??''));
            $current=self::transfer_requests($company,$environment);
            $grant=sanitize_text_field((string)($_GET['request_grant']??''));
            if(isset($current[$p]) && hash_equals($current[$p]['grant_id'],$grant)){
                echo '<div class="notice notice-success"><p><strong>License transfer request '.
                    esc_html((string)$_GET['request_status']).'.</strong> ' .
                    'Current state: '.esc_html($current[$p]['state']).
                    '. No license key has been copied. Next: verify vendor rights and available seats.</p></div>';
            }
        }
        echo '<div class="notice notice-warning"><p><strong>First-party remote operation registered; vendor license execution is still blocked.</strong> ' .
            'Original 313 Registry and Original 306 dispatcher are installed, using the existing Broker/Issuer public trust. The issuer envelope remains test-fixture only, and no independent vendor license entitlement is enrolled. ' .
            'The status-check button does not transfer or activate any license.</p></div>';
        $current=self::transfer_requests($company,$environment);
        echo '<div style="max-width:100%;overflow-x:auto"><table class="widefat striped" style="min-width:1050px"><thead><tr><th>Plugin</th><th>Central source</th><th>Target company</th><th>Remote execution</th><th>Credential channel</th><th>Vendor receipt</th><th>Transfer request</th><th>Action in central</th></tr></thead><tbody>';
        foreach((array)($read['provider_rows']??[]) as $id=>$row){
            $isAccount=($row['license_kind']??'')==='account_linked_license';
            $sourceMissing=in_array((string)($row['source_local_state']??''),
                ['LICENSE_NOT_CONFIGURED','PLUGIN_INACTIVE_LICENSE_UNVERIFIED','SOURCE_UNAVAILABLE'],true);
            $label=$isAccount?'طلب ربط الحساب / Request account link':'طلب نقل الترخيص / Request transfer';
            $existing=$current[$id]??null;
            echo '<tr><td><strong>'.esc_html(self::definitions()[$id]['label']).'</strong></td>'.
                 '<td>'.esc_html($row['source_local_state']).'</td>'.
                 '<td>'.esc_html($row['target_local_state']).'</td>'.
                 '<td>'.esc_html((string)($read['remote_execution']['state']??'not_verified')).'</td>'.
                 '<td>'.esc_html((string)$row['source_transfer_status']).'</td>'.
                 '<td>'.esc_html((string)$row['recipient_native_receipt_state']).'</td>'.
                 '<td>'.($existing?esc_html($existing['state'].' ('.$existing['mode'].')'):'Not requested').'</td>'.
                 '<td>';
            if(!empty($read['target_live_verified']) && !empty($read['fleet_evidence_current'])){
                echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
                wp_nonce_field('mad4b_plugin_remote_activation_preflight');
                foreach(['action'=>'mad4b_plugin_remote_activation_preflight',
                         'company_code'=>$company,'environment'=>$environment,
                         'provider_id'=>$id] as $field=>$value)
                    echo '<input type="hidden" name="'.esc_attr($field).'" value="'.esc_attr($value).'">';
                echo '<button class="button button-secondary" type="submit">فحص التنفيذ الريموت / Check remote readiness</button>';
                echo '</form>';
            }else echo 'Recipient not independently verified';
            echo '</td><td>';
            echo '<span class="description">No new DRAFT is required; real remote executor unavailable.</span> ';
            if($existing)echo '<a href="'.esc_url(admin_url('admin.php?page=mad4b-company-credentials')).'">View / revoke request</a>';
            echo '</td></tr>';
        }
        if(empty($read['provider_rows']))echo '<tr><td colspan="8">No verified live company evidence available; remote actions are unavailable.</td></tr>';
        echo '</tbody></table></div><p>Remote checks run and report here in the central admin without opening company WordPress. This release does not issue vendor activation commands or transmit live license keys. Actual remote activation requires an admitted provider executor and credential channel; neither is implied by a DRAFT grant.</p></div>';
    }
    public static function extend_central_catalog(array $original):array {
        foreach(self::definitions() as $id=>$def){
            $account=$def['kind']==='account_linked_license';
            $notes=$account
                ?'Elementor account-linked subscription and per-domain seat. Do not clone WordPress license options or access tokens.'
                :'License key must belong to the company or an entitled provider account; verify vendor terms and activation allowance before staging.';
            if(in_array($id,['wp_all_import_pro','wp_all_export_pro'],true))
                $notes='WP All Import/Export terms allow entitled client use but prohibit bundling in hosted services. Review the exact company relationship and vendor license before any activation.';
            $original[$id]=[
                'label'=>$def['label'],'secret_type'=>$account?'account_linked_license':'plugin_license',
                // Delegation is a metadata-only *draft* option, not evidence
                // that this provider's plan permits multisite/agency use.
                'allowed_modes'=>['company_owned','delegated_service'],
                'default_mode'=>'company_owned',
                'materialized_secret_available'=>false,
                'notes'=>$notes.' Delegated-service drafts require verified ownership, applicable site seats and vendor terms; no key transfer is admitted.',
            ];
        }
        return $original;
    }
}
add_filter('mad4b/company_credentials/provider_catalog',[
    'MAD4B_Plugin_License_Provider_Adapters','extend_central_catalog'],25);
add_filter('mad4b/company_credentials/local_adapter_catalog',[
    'MAD4B_Plugin_License_Provider_Adapters','extend_catalog'],25);
add_filter('mad4b/company_credentials/local_adapter_health',[
    'MAD4B_Plugin_License_Provider_Adapters','extend_health'],25);
add_filter('mad4b/company_credentials/local_adapter_qualification',[
    'MAD4B_Plugin_License_Provider_Adapters','extend_qualification'],25);

add_action('wp_abilities_api_init',['MAD4B_Plugin_License_Provider_Adapters','readiness_ability'],68);
add_action('admin_menu',['MAD4B_Plugin_License_Provider_Adapters','menu'],94);
add_action('admin_post_mad4b_plugin_license_transfer_request',['MAD4B_Plugin_License_Provider_Adapters','handle_transfer_request']);
add_action('admin_post_mad4b_plugin_remote_activation_preflight',['MAD4B_Plugin_License_Provider_Adapters','handle_remote_activation_preflight']);

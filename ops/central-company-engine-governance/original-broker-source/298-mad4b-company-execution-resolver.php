<?php
/**
 * Plugin Name: MAD4B Company Execution Resolver (Staging)
 * Description: Environment-separated, fail-closed company transport resolution and event-driven reconciliation.
 * Version: 0.1.0
 */
defined('ABSPATH') || exit;
final class MAD4B_Company_Execution_Resolver_V1 {
    const OPTION='mad4b_company_execution_resolver_v1';
    const API='mad4b-central/v1';
    const CRON='mad4b_company_execution_resolver_reconcile_v1';
    const ON_CHANGE='mad4b_company_execution_resolver_on_change_v1';
    const SCHEDULE='mad4b_company_resolver_15m';
    private static $events=['mad4b_central_companies_v1',
      'mad4b_tourism_company_site_registry_v1',
      'mad4b_portfolio_environment_sites_v1',
      'mad4b_environment_signed_proofs_v1'];
    public static function enabled():bool {
        return function_exists('wp_get_environment_type') &&
          wp_get_environment_type()==='staging' &&
          untrailingslashit(home_url('/'))==='https://tours.dev.mad4b.com';
    }
    public static function admin():bool {
        return self::enabled() && current_user_can('manage_options');
    }
    private static function deny($code,$message,$status=409) {
        return new WP_Error($code,$message,['status'=>$status]);
    }
    private static function valid_origin($url):bool {
        $u=wp_parse_url((string)$url);
        return is_array($u) && ($u['scheme']??'')==='https' &&
            !empty($u['host']) && !isset($u['user']) && !isset($u['pass']) &&
            !isset($u['port']) && !isset($u['query']) && !isset($u['fragment']) &&
            in_array(($u['path']??''),['','/'],true);
    }
    private static function vault():array {
        $out=[];
        if(!class_exists('MAD4B_Central_Simple_Connections_V1',false))return $out;
        $s=MAD4B_Central_Simple_Connections_V1::status();
        $d=$s instanceof WP_REST_Response ? $s->get_data() : [];
        foreach(($d['companies']??[]) as $row) {
            if(is_array($row) && is_string($row['company_id']??null))
                $out[$row['company_id']]=($row['configured']??false)===true;
        }
        return $out;
    }
    public static function discover():array {
        if(!self::enabled() || !class_exists('MAD4B_Central_Company_REST_V1',false))return [];
        // V2 is the single normalization path for Company -> Environments.
        // The legacy projection remains fail-closed only as a compatibility fallback.
        if(class_exists('MAD4B_Company_Environment_Registry_V2',false))
            return MAD4B_Company_Environment_Registry_V2::legacy_targets();
        $directory=MAD4B_Central_Company_REST_V1::directory();
        $saved=get_option('mad4b_central_companies_v1',[]);
        $bindings=get_option('mad4b_tourism_company_site_registry_v1',[]);
        $environments=get_option('mad4b_portfolio_environment_sites_v1',[]);
        $vault=self::vault();
        if(!is_array($saved))$saved=[];
        if(!is_array($bindings))$bindings=[];
        if(!is_array($environments))$environments=[];
        $results=[];
        foreach($directory as $id=>$item) {
            if(!preg_match('/^[a-z][a-z0-9-]{2,47}$/D',(string)$id))continue;
            $origin=(string)($item['url']??'');
            if(!self::valid_origin($origin))continue;
            $verified=$saved[$id]??[];
            $binding=$bindings[$id]??[];
            $same=is_array($binding) && ($binding['company_code']??'')===$id &&
                  ($binding['canonical_origin']??'')===$origin &&
                  ($binding['host_binding_verified']??false)===true;
            $v4=$same && ($binding['registration_mode']??'')==='p256_signed_site_profile_v4' &&
                wp_is_uuid((string)($binding['company_site_uuid']??'')) &&
                wp_is_uuid((string)($binding['native_site_profile_uuid']??''));
            $v3=is_array($verified) && ($verified['identity_protocol']??'')==='p256_mutual_signed_v3';
            $when=$v3 ? strtotime((string)($verified['p256_last_verified_at']??'')) : false;
            $fresh=$when!==false && $when<=time() && (time()-$when)<=86400;
            $local=$same && ($binding['registration_mode']??'')==='local_vps_verified_readback';
            $results[$id.':production']=[
                'company_id'=>$id,'environment'=>'production','canonical_origin'=>$origin,
                'site_uuid'=>($v4||$local)?$binding['company_site_uuid']:null,
                'profile_uuid'=>($v4||$local)?$binding['native_site_profile_uuid']:null,
                'identity'=>[
                    'site_profile_v4_verified'=>$v4,
                    'p256_v3_recent'=>$v3 && $fresh,
                    'local_vps_binding'=>$local],
                'channels'=>[
                    'native_mcp'=>['state'=>'external_session_only','centrally_authorized'=>false],
                    'signed_identity'=>['state'=>$v4?'v4_exact':(($v3 && $fresh)?'v3_recent':'pairing_required'),
                      'scope'=>'identity.read'],
                    'rest_vault'=>['state'=>!empty($vault[$id])?'configured_reference_only':'not_configured',
                      'scope'=>'identity.read','no_credentials_exported'=>true],
                    'wpcli_ssh'=>['state'=>$local?'local_identity_bound_executor_not_authorized':'no_approved_mutation_runner',
                      'remote_write_allowed'=>false],
                ],
                'writes_enabled'=>false
            ];
        }
        foreach($environments as $key=>$row) {
            if(!is_string($key)||!is_array($row))continue;
            $id=(string)($row['company_id']??'');
            $env=(string)($row['environment']??'');
            $origin=(string)($row['canonical_origin']??'');
            if(!isset($directory[$id]) || $env!=='staging' || $key!==$id.':staging' ||
               !self::valid_origin($origin) || !wp_is_uuid((string)($row['site_uuid']??''))) continue;
            // A candidate record is not signing evidence. Never promote it
            // into an executable identity channel from database flags alone.
            $proof=false;
            $results[$key]=[
                'company_id'=>$id,'environment'=>'staging','canonical_origin'=>$origin,
                'site_uuid'=>(string)$row['site_uuid'],
                'profile_uuid'=>null,
                'identity'=>['site_profile_v4_verified'=>$proof,'p256_v3_recent'=>false,
                    'local_vps_binding'=>false],
                'channels'=>[
                    'native_mcp'=>['state'=>'external_session_only','centrally_authorized'=>false],
                    'signed_identity'=>['state'=>$proof?'v4_exact':'separate_pairing_required','scope'=>'identity.read'],
                    'rest_vault'=>['state'=>'separate_staging_credentials_required','scope'=>'identity.read'],
                    'wpcli_ssh'=>['state'=>'diagnostic_only_not_write_authorized','remote_write_allowed'=>false],
                ],
                'writes_enabled'=>false
            ];
        }
        ksort($results,SORT_STRING);
        return $results;
    }
    public static function plan($company,$environment,$intent):array {
        $company=(string)$company;
        $environment=(string)$environment;
        $intent=(string)$intent;
        $records=self::discover();
        $key=$company.':'.$environment;
        if(!isset($records[$key]))return [
          'state'=>'TARGET_NOT_ENROLLED','execution_allowed'=>false,
          'blockers'=>['exact_company_environment_binding_missing']];
        $inspections=['identity.inspect','environment.inspect','provider.inspect','capability.inspect'];
        if(!in_array($intent,array_merge($inspections,
           ['identity.read','content.read','content.write','plugin.install']),true))
            return ['state'=>'INVALID_INTENT','execution_allowed'=>false,
                'blockers'=>['unsupported_operation']];
        $item=$records[$key];
        if(in_array($intent,$inspections,true)) {
            // Inspection here means local, already enrolled central metadata.
            // It NEVER probes or mutates the company's WordPress instance.
            // Remote checks require a separately admitted native executor.
            return [
                'contract'=>'mad4b.company.execution-plan.v1',
                'company_id'=>$company,'environment'=>$environment,
                'canonical_origin'=>$item['canonical_origin'],
                'site_uuid'=>$item['site_uuid'],'intent'=>$intent,
                'state'=>'READY_FOR_LOCAL_INSPECTION',
                'selected_channel'=>'central_company_registry',
                'candidate_order'=>['central_company_registry'],
                'execution_allowed'=>true,
                'local_only'=>true,'remote_site_probed'=>false,
                'remote_write_permitted'=>false,
                'blockers'=>[],
                'fallback_policy'=>'no_remote_execution_or_permission_escalation',
                'readback_required'=>false,
                'production_mutation'=>false,
            ];
        }
        $channels=$item['channels'];
        $proof=$item['identity'];
        $candidate=[];
        $blockers=[];
        $selected=null;
        if($intent==='identity.read') {
            if($proof['site_profile_v4_verified'])$selected='signed_identity_v4';
            elseif($proof['p256_v3_recent'])$selected='signed_identity_v3';
            elseif($environment==='staging' &&
              ($proof['local_vps_binding']??false)===true &&
              ($channels['wpcli_ssh']['state']??'')==='local_identity_only_executor_not_authorized')
                $selected='original_native_host_identity';
            elseif($channels['rest_vault']['state']==='configured_reference_only')$selected='rest_vault_reference';
            else $blockers[]='no_current_centrally_authorized_identity_proof';
            $candidate=['signed_identity_v4','signed_identity_v3',
                'original_native_host_identity','rest_vault_reference'];
        } elseif($intent==='content.read') {
            $candidate=['native_company_wp_public_rest','native_mcp','rest_vault','local_wpcli'];
            // This is a genuine but public-only provider, not an authenticated
            // remote content feed. The calling REST endpoint applies delegation.
            $delegated=false;
            if(class_exists('MAD4B_Company_Operation_Admission_V1')
                && class_exists('MAD4B_Company_Native_Content_Read_V1')
                && MAD4B_Company_Operation_Admission_V1::actor()){
                $admitted=MAD4B_Company_Operation_Admission_V1::assess($company,'content.read');
                $delegated=!is_wp_error($admitted) && !empty($admitted['policy_delegation_admitted']);
            }
            if($delegated) $selected='native_company_wp_public_rest';
            else $blockers[]='enrolled_manager_delegation_and_native_read_provider_required';
        } else {
            $candidate=['native_company_wordpress_write','native_mcp','rest_vault','local_wpcli'];
            $blockers[]='no_separately_authorized_exact_site_write_executor';
            $blockers[]='native_wordpress_capability_and_postcondition_required';
            // A future native write provider is admitted only by its own
            // independently verified, fresh, exact-site capability record.
            // A company assignment, connection credential or read proof never
            // substitutes for the provider's own execution evidence.
            if(class_exists('MAD4B_Company_Native_Write_Executor_V1',false)
               && method_exists('MAD4B_Company_Native_Write_Executor_V1','certified_status')){
                $e=MAD4B_Company_Native_Write_Executor_V1::certified_status($company,$environment,$intent);
                if(is_array($e)
                  && ($e['contract']??'')==='mad4b.company-native-write-executor.v1'
                  && ($e['company_code']??'')===$company
                  && ($e['environment']??'')===$environment
                  && ($e['canonical_origin']??'')===$item['canonical_origin']
                  && ($e['site_uuid']??'')===$item['site_uuid']
                  && ($e['intent']??'')===$intent
                  && ($e['native_wordpress_capability_verified']??false)===true
                  && ($e['executor_authenticated']??false)===true
                  && ($e['readback_handler_verified']??false)===true
                  && ($e['rollback_handler_verified']??false)===true
                  && is_string($e['receipt_sha256']??null)
                  && preg_match('/^[a-f0-9]{64}$/D',$e['receipt_sha256'])
                  && (int)($e['verified_at_epoch']??0)<=time()
                  && (int)($e['verified_at_epoch']??0)>time()-900){
                    $selected='native_company_wordpress_write';
                    $blockers=[];
                }
            }
        }
        $ready=$selected!==null;
        $write=$ready && in_array($intent,['content.write','plugin.install'],true);
        $read=$ready && $intent==='content.read';
        return [
            'contract'=>'mad4b.company.execution-plan.v1',
            'company_id'=>$company,'environment'=>$environment,'canonical_origin'=>$item['canonical_origin'],
            'site_uuid'=>$item['site_uuid'],'intent'=>$intent,
            'state'=>$ready?($write?'READY_FOR_EXACT_NATIVE_WRITE':($read?'READY_FOR_PUBLIC_CONTENT_READ':'READY_FOR_AUTHORIZED_IDENTITY_PROBE')):'BLOCKED_MISSING_AUTHORITY',
            'selected_channel'=>$selected,'candidate_order'=>$candidate,
            'execution_allowed'=>$ready,
            'read_scope'=>$read?'public_published_only':null,
            'remote_write_permitted'=>$write,
            'blockers'=>$blockers,'channels'=>$channels,
            'fallback_policy'=>'recheck_exact_binding_and_capabilities_no_authority_escalation',
            'readback_required'=>$write,
            'production_mutation'=>false,
        ];
    }
    public static function reconcile():array {
        if(class_exists('MAD4B_Company_Environment_Registry_V2',false))
            MAD4B_Company_Environment_Registry_V2::reconcile();
        $targets=self::discover();
        $out=[];
        foreach($targets as $key=>$target) {
            $intent=self::plan($target['company_id'],$target['environment'],'identity.read');
            $out[$key]=[
                'environment'=>$target['environment'],
                'canonical_origin'=>$target['canonical_origin'],
                'site_uuid'=>$target['site_uuid'],
                'identity_state'=>$intent['state'],
                'selected_channel'=>$intent['selected_channel'],
                'write_authorized'=>false,
            ];
        }
        $snapshot=['contract'=>'mad4b.company.execution-resolver.v1',
            'generated_at'=>gmdate('c'),'target_count'=>count($out),
            'targets'=>$out,'production_mutation'=>false,'remote_credentials_exported'=>false];
        update_option(self::OPTION,$snapshot,false);
        return $snapshot;
    }
    public static function rest_list() {
        return new WP_REST_Response(['contract'=>'mad4b.company.execution-resolver.v1',
            'targets'=>self::discover(),'snapshot'=>get_option(self::OPTION,[]),
            'live'=>true],200);
    }
    public static function rest_plan(WP_REST_Request $req) {
        $p=self::plan($req['company'],(string)$req->get_param('environment'),
                     (string)$req->get_param('intent'));
        return new WP_REST_Response($p,($p['state']==='INVALID_INTENT')?400:200);
    }
    public static function rest_reconcile() {
        return new WP_REST_Response(self::reconcile(),200);
    }
    public static function rest_verify_identity(WP_REST_Request $req) {
        $id=(string)$req['company'];
        $env=(string)$req->get_param('environment');
        $plan=self::plan($id,$env,'identity.read');
        if(empty($plan['execution_allowed']) || $env!=='production')
            return self::deny('mad4b_resolver_verified_channel_required',
                'No directly authorized central identity verifier for this exact target');
        if($plan['selected_channel']==='signed_identity_v3' &&
           class_exists('MAD4B_Central_PK_Pairing_V1',false) &&
           method_exists('MAD4B_Central_PK_Pairing_V1','verify')) {
            $r=new WP_REST_Request('POST','/mad4b-central/v1/companies/'.$id.'/verify-p256');
            $r->set_param('company',$id);
            $verified=MAD4B_Central_PK_Pairing_V1::verify($r);
            if(is_wp_error($verified))return $verified;
            self::reconcile();
            $response=$verified instanceof WP_REST_Response ? $verified->get_data():[];
            return new WP_REST_Response(['state'=>'IDENTITY_PROBE_COMPLETED',
                'channel'=>'signed_identity_v3','company_id'=>$id,'environment'=>$env,
                'site_uuid'=>$plan['site_uuid'],'verification_response_type'=>gettype($response),
                'readback'=>self::plan($id,$env,'identity.read'),
                'production_mutation'=>false],200);
        }
        return self::deny('mad4b_resolver_channel_not_executable',
            'Verification needs an installed scoped executor, not proof reuse');
    }
    public static function schedule_event($option) {
        if(!self::enabled())return;
        $option=(string)$option;
        if(!in_array($option,self::$events,true) &&
           strpos($option,'mad4b_central_simple_connection')!==0 &&
           strpos($option,'mad4b_central_company_rest_secret_v1_')!==0 &&
           strpos($option,'mad4b_environment_p256_trust_v1_')!==0) return;
        if(!wp_next_scheduled(self::ON_CHANGE))
            wp_schedule_single_event(time()+30,self::ON_CHANGE);
    }
    public static function boot():void {
        if(!self::enabled())return;
        add_filter('cron_schedules',static function($s){
            $s[self::SCHEDULE]=['interval'=>900,'display'=>'MAD4B Company Resolver every 15 minutes'];
            return $s;
        });
        add_action('init',static function(){
            if(!wp_next_scheduled(self::CRON))
                wp_schedule_event(time()+120,self::SCHEDULE,self::CRON);
        },50);
        add_action(self::CRON,[__CLASS__,'reconcile']);
        add_action(self::ON_CHANGE,[__CLASS__,'reconcile']);
        add_action('updated_option',static function($key){self::schedule_event($key);},10,1);
        add_action('added_option',static function($key){self::schedule_event($key);},10,1);
        add_action('deleted_option',static function($key){self::schedule_event($key);},10,1);
        add_action('rest_api_init',static function(){
            register_rest_route(self::API,'/execution-resolver',[
                'methods'=>'GET','permission_callback'=>[__CLASS__,'admin'],
                'callback'=>[__CLASS__,'rest_list']]);
            register_rest_route(self::API,'/execution-resolver/reconcile',[
                'methods'=>'POST','permission_callback'=>[__CLASS__,'admin'],
                'callback'=>[__CLASS__,'rest_reconcile']]);
            register_rest_route(self::API,'/execution-resolver/(?P<company>[a-z][a-z0-9-]{2,47})/plan',[
                'methods'=>'POST','permission_callback'=>[__CLASS__,'admin'],
                'callback'=>[__CLASS__,'rest_plan']]);
            register_rest_route(self::API,'/execution-resolver/(?P<company>[a-z][a-z0-9-]{2,47})/verify-identity',[
                'methods'=>'POST','permission_callback'=>[__CLASS__,'admin'],
                'callback'=>[__CLASS__,'rest_verify_identity']]);
        });
    }
}
MAD4B_Company_Execution_Resolver_V1::boot();

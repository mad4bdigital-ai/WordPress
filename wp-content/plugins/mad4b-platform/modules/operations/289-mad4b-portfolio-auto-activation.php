<?php
/**
 * Plugin Name: MAD4B Portfolio Provisioning Auto-Activation (Staging)
 * Description: Automatically reconcile provisioned companies through the enrolled central VPS WP-CLI operator, verifying existing pairing without widening grants.
 * Version: 0.1.0
 */
defined('ABSPATH') || exit;

final class MAD4B_Portfolio_Auto_Activation_V1 {
    const CONTRACT = 'mad4b.portfolio.auto-activation.v1';
    const STATUS = 'mad4b_portfolio_auto_activation_status_v1';
    const DIRTY = 'mad4b_portfolio_auto_activation_dirty_v1';
    const MAX_PER_RUN = 5; // Bounded network checks within the VPS cron deadline.
    const VERIFY_TTL = 1800;
    const FAILURE_BACKOFF = 900;

    private static function site():bool {
        return wp_get_environment_type()==='staging'
            && home_url('/')==='https://tours.dev.mad4b.com/'
            && class_exists('MAD4B_SCP_Site_Profile',false)
            && MAD4B_SCP_Site_Profile::origin_enrolled()
            && MAD4B_SCP_Site_Profile::site_urls_match_enrollment()
            && class_exists('MAD4B_Central_Company_REST_V1',false)
            && class_exists('MAD4B_Portfolio_VPS_MCP_Fallback_V1',false);
    }
    private static function actor():bool {
        return self::site() && defined('WP_CLI') && WP_CLI
            && !(defined('REST_REQUEST') && REST_REQUEST)
            && get_current_user_id()>0 && current_user_can('manage_options')
            && MAD4B_SCP_Site_Profile::user_is_enrolled(get_current_user_id());
    }
    private static function err($code) {
        return new WP_Error('mad4b_portfolio_activation_'.$code,'Portfolio auto-activation denied: '.$code);
    }
    private static function records():array {
        $v=get_option(self::STATUS,[]);
        return is_array($v)?$v:[];
    }
    private static function protocol($id):string {
        if(class_exists('MAD4B_Central_PK_Pairing_V1',false)
           && is_string(get_option(MAD4B_Central_PK_Pairing_V1::PRIVATE_OPTION,''))
           && get_option(MAD4B_Central_PK_Pairing_V1::PRIVATE_OPTION,'')!==''
           && is_string(get_option(MAD4B_Central_PK_Pairing_V1::trust_name($id),''))
           && get_option(MAD4B_Central_PK_Pairing_V1::trust_name($id),'')!=='') {
            return 'p256_mutual_signed_v3';
        }
        if(is_string($hmac=get_option(MAD4B_Central_Company_REST_V1::secret_option($id),''))
           && preg_match('/^[a-f0-9]{64}$/D',$hmac)===1) {
            return 'signed_hmac_identity_v1';
        }
        if(class_exists('MAD4B_Central_Simple_Connections_V1',false)) {
            $s=get_option(MAD4B_Central_Simple_Connections_V1::OPTION_PREFIX.str_replace('-','_',$id),false);
            if(is_array($s) && ($s['format']??'')==='aes-256-gcm-v1')return 'saved_app_password';
        }
        if(class_exists('MAD4B_Portfolio_Local_Readback_V1',false) &&
           MAD4B_Portfolio_Local_Readback_V1::verified($id))
            return 'vps_local_verified_read_only';
        return 'none';
    }
    public static function status():array {
        if(!self::site())return ['contract'=>self::CONTRACT,'state'=>'WRONG_ENVIRONMENT'];
        $directory=MAD4B_Central_Company_REST_V1::directory();
        $receipts=self::records();
        $rows=[];
        foreach($directory as $id=>$r) {
            $ready=($r['provisioning_verified']??false)===true;
            $rows[]=[
                'company_id'=>$id,
                'registration_source'=>$r['registration_source']??'unknown',
                'host_binding_verified'=>$ready,
                'pairing_protocol_available'=>$ready?self::protocol($id):'none',
                'state'=>$receipts[$id]['state']??($ready?'PENDING_AUTOMATIC_CHECK':'PENDING_HOST_BINDING'),
                'last_checked_at'=>$receipts[$id]['checked_at']??null,
                'last_success_at'=>$receipts[$id]['success_at']??null,
                'last_error'=>$receipts[$id]['reason']??null,
                'remote_writes_enabled'=>false
            ];
        }
        $pending=MAD4B_Portfolio_VPS_MCP_Fallback_V1::status();
        return [
            'contract'=>self::CONTRACT,'site'=>home_url('/'),
            'automatic_registration'=>true,'scheduled_vps_cli_reconciliation'=>true,
            'transport'=>'native_wp_cli_vps_client','company_count'=>count($rows),
            'companies'=>$rows,
            'pending_intake_count'=>is_array($pending)?($pending['pending_company_count']??0):0,
            'queue_event_dirty'=>(bool)get_option(self::DIRTY,false),
            'automatic_remote_write'=>false,'oauth_bearer_bypass'=>false,
            'fallback_on_401_or_403'=>false,
            'production_mutation'=>false
        ];
    }
    public static function reconcile($force=false) {
        if(!self::actor())return self::err('enrolled_local_cli_owner_required');
        if(!is_bool($force))return self::err('invalid_arguments');
        $companies=MAD4B_Central_Company_REST_V1::directory();
        if(count($companies)>1000)return self::err('company_registry_above_limit');
        $previous=self::records();$next=$previous;
        $dirty_ticket=get_option(self::DIRTY,false);
        $immediate=$force || (is_string($dirty_ticket) && $dirty_ticket!=='');
        $out=[];$processed=0;$now=time();
        foreach($companies as $id=>$company) {
            if(!is_string($id)||!preg_match('/^[a-z][a-z0-9-]{2,47}$/D',$id))continue;
            if($processed>=self::MAX_PER_RUN)break;
            $old=is_array($previous[$id]??null)?$previous[$id]:[];
            $last=(int)($old['last_attempt_epoch']??0);
            $priorSuccess=in_array(($old['state']??''),['ACTIVE_READ_ONLY','ACTIVE_LOCAL_READ_ONLY'],true);
            $currentProtocol=self::protocol($id);
            if(!$immediate && $last>0 &&
               ($old['protocol']??'')===$currentProtocol &&
               $now-$last<($priorSuccess?self::VERIFY_TTL:self::FAILURE_BACKOFF)) {
                $out[]=['company_id'=>$id,'state'=>'UNCHANGED','previous_state'=>$old['state']??'unknown'];
                continue;
            }
            $processed++;
            $protocol=self::protocol($id);
            $ready=($company['provisioning_verified']??false)===true;
            $result=['company_id'=>$id,'state'=>'','reason'=>null,'protocol'=>$protocol,
                'checked_at'=>gmdate('c'),'last_attempt_epoch'=>$now,
                'success_at'=>$old['success_at']??null,'remote_writes_enabled'=>false];
            if(!$ready) {
                $result['state']='PENDING_HOST_BINDING';
                $result['reason']='provisioning_host_identity_pending';
            } elseif($protocol==='none') {
                $result['state']='NEEDS_PAIRING';
                $result['reason']='no_existing_trusted_pairing_credentials';
            } elseif($protocol==='vps_local_verified_read_only') {
                // Independent, bounded VPS readback: not a P-256 pairing or
                // cross-transport write grant. No target-site mutation.
                $result['state']='ACTIVE_LOCAL_READ_ONLY';
                $result['success_at']=gmdate('c');
            } else {
                // Exactly one pre-existing trust protocol is used. An authentication
                // denial or mismatched signed identity never downgrades to weaker credentials.
                $request=new WP_REST_Request('POST','/mad4b-central/v1/companies/'.$id.'/verify');
                $request->set_param('company',$id);
                if($protocol==='p256_mutual_signed_v3') {
                    $response=MAD4B_Central_PK_Pairing_V1::verify($request);
                } elseif($protocol==='signed_hmac_identity_v1') {
                    $response=MAD4B_Central_Company_REST_V1::verify($request);
                } else {
                    $response=MAD4B_Central_Simple_Connections_V1::test_saved($request);
                }
                $payload=$response instanceof WP_REST_Response?$response->get_data():null;
                $verified=!is_wp_error($response) && $response instanceof WP_REST_Response
                    && $response->get_status()===200 && is_array($payload)
                    && ($payload['company_id']??'')===$id
                    && (
                        ($protocol==='p256_mutual_signed_v3'
                            && ($payload['mutual_signatures_verified']??false)===true)
                        || ($protocol==='signed_hmac_identity_v1'
                            && ($payload['remote_rest_authenticated']??false)===true)
                        || ($protocol==='saved_app_password'
                            && ($payload['connected']??false)===true)
                    );
                if($verified) {
                    $result['state']='ACTIVE_READ_ONLY';
                    $result['success_at']=gmdate('c');
                } else {
                    $result['state']='BLOCKED_CONNECTION';
                    $result['reason']=is_wp_error($response)
                        ?sanitize_key($response->get_error_code())
                        :'remote_identity_verification_failed';
                }
            }
            $next[$id]=$result;
            $out[]=['company_id'=>$id,'state'=>$result['state'],'reason'=>$result['reason'],
                    'protocol'=>$protocol];
        }
        // Store only short non-secret diagnostics. Repeated successful reconciliation
        // does not write a new status option on every invocation.
        if($next!==$previous && !update_option(self::STATUS,$next,false)
           && get_option(self::STATUS,[])!==$next)return self::err('receipt_persistence_failed');
        if($processed>0 && $dirty_ticket!==false &&
           get_option(self::DIRTY,false)===$dirty_ticket)
            update_option(self::DIRTY,false,false);
        return [
            'contract'=>self::CONTRACT,'processed'=>$processed,
            'checked_count'=>count($out),'records'=>$out,
            'read_only_activation'=>true,
            'remote_permissions_not_granted'=>true,'production_mutation'=>false,
            'next_recheck_seconds'=>self::FAILURE_BACKOFF
        ];
    }
    public static function dirty_event($option,$old=null,$value=null):void {
        if(!self::site() || !is_string($option))return;
        $relevant=$option==='mad4b_tourism_company_site_registry_v1'
            || $option==='mad4b_central_pk_private_v1'
            || str_starts_with($option,'mad4b_central_pk_remote_public_v1_')
            || str_starts_with($option,'mad4b_central_company_rest_secret_v1_')
            || str_starts_with($option,'mad4b_simple_connection_');
        if($relevant)update_option(self::DIRTY,wp_generate_uuid4(),false);
    }
    public static function rest_permission() {
        return self::site() && is_user_logged_in() &&
            current_user_can('manage_options') && get_current_user_id()>0 &&
            MAD4B_SCP_Site_Profile::user_is_enrolled(get_current_user_id());
    }
    public static function rest():void {
        if(!self::site())return;
        register_rest_route('mad4b-central/v1','/portfolio-autoactivation/status',[
            'methods'=>'GET','permission_callback'=>[__CLASS__,'rest_permission'],
            'callback'=>static fn()=>rest_ensure_response(self::status())
        ]);
        register_rest_route('mad4b-central/v1','/portfolio-autoactivation/request-check',[
            'methods'=>'POST','permission_callback'=>[__CLASS__,'rest_permission'],
            'callback'=>static function(){
                update_option(self::DIRTY,wp_generate_uuid4(),false);
                return rest_ensure_response([
                    'contract'=>self::CONTRACT,'queued'=>true,
                    'executor'=>'vps_root_wp_cli_next_tick',
                    'write_authorized'=>false
                ]);
            }
        ]);
    }
    public static function cli_activate($args=[],$assoc=[]):void {
        $force=is_array($assoc) && isset($assoc['force']);
        $r=self::reconcile($force);
        if(is_wp_error($r))WP_CLI::error($r->get_error_code());
        WP_CLI::line(wp_json_encode($r,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
    }
    public static function cli_status($args=[],$assoc=[]):void {
        if(!self::actor())WP_CLI::error('enrolled_local_cli_owner_required');
        WP_CLI::line(wp_json_encode(self::status(),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
    }
    public static function boot():void {
        add_action('updated_option',[__CLASS__,'dirty_event'],10,3);
        add_action('added_option',[__CLASS__,'dirty_event'],10,2);
        add_action('rest_api_init',[__CLASS__,'rest'],60);
        if(defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')){
            WP_CLI::add_command('mad4b portfolio auto-activate',[__CLASS__,'cli_activate']);
            WP_CLI::add_command('mad4b portfolio activation-status',[__CLASS__,'cli_status']);
        }
    }
}
MAD4B_Portfolio_Auto_Activation_V1::boot();


<?php
/**
 * Plugin Name: MAD4B Central VPS Broker Dispatch and Claim
 * Description: Central authenticated dispatch; independent Ed25519-attested host broker claim. Neither grants SSH privilege nor executes shell commands.
 * Version: 0.2.0
 */
defined('ABSPATH') || exit;

final class MAD4B_Central_VPS_Broker_V1 {
    const CONTRACT = 'mad4b.central-vps-broker.v1';
    const JOB = 'mad4b_central_broker_job_v1_';
    const CLAIM = 'mad4b_central_broker_claim_v1_';
    const ROUTE_GATE = 'mad4b_central_broker_route_gate_v1_';
    const PUBKEY = 'mad4b_central_broker_signing_public_v1';
    const HOST_ID = 'mad4b_central_broker_host_id_v1';
    const HEARTBEAT = 'mad4b_central_broker_heartbeat_v1';
    const HEARTBEAT_NONCE = 'mad4b_central_broker_heartbeat_nonce_v1_';

    private static function deny(string $why, int $http=403) {
        return new WP_Error('mad4b_broker_'.$why,
            'Central host broker: '.$why, array('status'=>$http, 'contract'=>self::CONTRACT));
    }

    private static function fields(string $company, string $id) {
        if (!preg_match('/^[a-z][a-z0-9-]{2,47}$/D', $company)
            || !preg_match('/^[a-f0-9]{32}$/D', $id)) return self::deny('scope_invalid',422);
        $request=get_option(MAD4B_Central_SSH_Command_Intake_V1::REQUEST_PREFIX.$id,false);
        if (!is_array($request) || ($request['company']??'')!==$company
            || ($request['status']??'')!=='ADMITTED_PENDING_INDEPENDENT_HOST_AUTHORITY'
            || ($request['execution_performed']??true)!==false
            || (int)($request['expires_epoch']??0)<=time())
            return self::deny('request_expired_or_invalid',409);
        $grant=get_option(MAD4B_Central_SSH_Command_Intake_V1::PREFIX.$request['grant_id'],false);
        if (!is_array($grant) || ($grant['state']??'')!=='CENTRAL_OPERATION_SCOPE_GRANTED'
            || ($grant['company']??'')!==$company
            || ($grant['central_principal_id']??0)!==($request['central_principal_id']??-1)
            || ($grant['operation']??'')!==($request['operation']??'')
            || (isset($grant['expires_epoch']) && (int)$grant['expires_epoch']<=time()))
            return self::deny('grant_not_active',409);
        if (($request['grant_mode']??'one_time')==='persistent') {
            $transport=get_option(MAD4B_Central_SSH_Command_Intake_V1::TRANSPORT_PREFIX.
                (string)($request['transport_id']??''),false);
            if (!is_array($transport)
                || ($transport['state']??'')!=='PENDING_INDEPENDENT_SSH_VERIFICATION'
                || ($transport['company']??'')!==$company
                || ($transport['host_id']??'')!==($request['target_host_id']??'')
                || ($transport['site_uuid']??'')!==($request['site_uuid']??''))
                return self::deny('transport_revoked_or_invalid',409);
        }
        if (!class_exists('MAD4B_Company_Operation_Admission_V1',false)
            || !class_exists('MAD4B_Manager_Auto_Write_V1',false))
            return self::deny('company_policy_missing',503);
        $scope=MAD4B_Manager_Auto_Write_V1::company_target($company);
        if (($scope['state']??'')!=='REGISTERED'
            || ($scope['site_uuid']??'')!==($request['site_uuid']??'')
            || ($scope['canonical_origin']??'')!==($request['canonical_origin']??'')
            || ($scope['environment']??'')!==($request['environment']??''))
            return self::deny('company_binding_changed',409);
        $uid=(int)($request['central_principal_id']??0);
        $manager=MAD4B_Company_Operation_Admission_V1::manager_id();
        if ($manager==='' || $uid<1 || !class_exists('MAD4B_Company_Portfolio_Manager_V1',false)
            || !MAD4B_Company_Portfolio_Manager_V1::has_delegation(
                $manager,$company,'operations_manage',$uid))
            return self::deny('delegation_revoked',409);
        return $request;
    }

    public static function dispatch(WP_REST_Request $r) {
        if (!MAD4B_Central_SSH_Command_Intake_V1::permitted($r))
            return self::deny('authenticated_owner_required');
        $company=(string)$r['company'];
        $json=$r->get_json_params();
        if (!is_array($json) || array_keys($json)!==array('request_id'))
            return self::deny('request_shape_invalid',422);
        $id=(string)$json['request_id'];
        $source=self::fields($company,$id);
        if (is_wp_error($source))return $source;
        if ((int)($source['central_principal_id']??0)!==get_current_user_id())
            return self::deny('principal_mismatch');
        // The physical Broker signing host is distinct from the target SSH
        // host. Never interpret broker identity as remote target authority.
        $broker_host=(string)get_option(self::HOST_ID,'');
        if ($broker_host!=='srv2030908')return self::deny('broker_host_not_enrolled',503);
        if (($source['target_host_id']??'')!==$broker_host) {
            if (!in_array($source['target_host_id']??'',array('hosting-1','hosting-2','hosting-3'),true)
              || ($source['grant_mode']??'')!=='persistent'
              || !preg_match('/^[a-f0-9]{32}$/D',(string)($source['transport_id']??'')))
                return self::deny('remote_registry_transport_required',403);
            $transport=get_option(MAD4B_Central_SSH_Command_Intake_V1::TRANSPORT_PREFIX.
                (string)$source['transport_id'],false);
            if (!is_array($transport)
                || ($transport['state']??'')!=='PENDING_INDEPENDENT_SSH_VERIFICATION'
                || ($transport['host_id']??'')!==($source['target_host_id']??'')
                || ($transport['company']??'')!==$company
                || ($transport['site_uuid']??'')!==($source['site_uuid']??''))
                return self::deny('remote_transport_scope_invalid',403);
        }
        $existing=get_option(self::JOB.$id,null);
        if (is_array($existing))
            return new WP_REST_Response($existing,200);
        $job=array(
            'contract'=>self::CONTRACT,
            'job_id'=>$id, 'company'=>$company,
            'operation'=>$source['operation'],
            'site_uuid'=>$source['site_uuid'],
            'canonical_origin'=>$source['canonical_origin'],
            'environment'=>$source['environment'],
            'broker_host_id'=>$broker_host,
            'target_host_id'=>$source['target_host_id'],
            'target_ssh_principal'=>$source['target_ssh_principal'],
            'grant_id'=>$source['grant_id'],
            'grant_mode'=>$source['grant_mode'],
            'transport_id'=>($source['transport_id']??null),
            'central_principal_id'=>$source['central_principal_id'],
            'correlation_id'=>$source['correlation_id'],
            'command_sha256'=>$source['command_sha256'],
            'target_scope'=>$source['target_scope'],
            'command_format'=>($source['command_format']??'maintenance.shell.text.v1'),
            'created_epoch'=>time(), 'expires_epoch'=>$source['expires_epoch'],
            'state'=>'QUEUED_FOR_VPS_BROKER_CLAIM',
            'executed'=>false,
            'independent_host_permission_verified'=>false
        );
        if (!add_option(self::JOB.$id,$job,'',false)) {
            $found=get_option(self::JOB.$id,false);
            return is_array($found)?rest_ensure_response($found):self::deny('dispatch_conflict',409);
        }
        return new WP_REST_Response($job,202);
    }

    public static function job(WP_REST_Request $r) {
        if (!MAD4B_Central_SSH_Command_Intake_V1::permitted($r))
            return self::deny('authenticated_owner_required');
        $id=(string)$r['job_id']; $row=get_option(self::JOB.$id,false);
        if (!is_array($row)||($row['company']??'')!==(string)$r['company']
            || (int)($row['central_principal_id']??0)!==get_current_user_id())
            return self::deny('job_not_found',404);
        return rest_ensure_response($row);
    }

    public static function pending(int $limit=10):array {
        if (!defined('WP_CLI')||!WP_CLI || !class_exists('WP_CLI')) return array();
        global $wpdb;
        $limit=max(1,min(30,$limit));
        $like=$wpdb->esc_like(self::JOB).'%';
        $names=$wpdb->get_col($wpdb->prepare(
            'SELECT option_name FROM '.$wpdb->options.' WHERE option_name LIKE %s ORDER BY option_id DESC LIMIT 200',
            $like));
        $result=array();
        foreach((array)$names as $name) {
            $row=get_option($name,false);
            if (!is_array($row)||($row['state']??'')!=='QUEUED_FOR_VPS_BROKER_CLAIM')continue;
            if (($row['expires_epoch']??0)<=time())continue;
            $result[]=array(
                'job_id'=>$row['job_id'], 'company'=>$row['company'],
                'site_uuid'=>$row['site_uuid'], 'environment'=>$row['environment'],
                'target_host_id'=>$row['target_host_id'],
                'broker_host_id'=>$row['broker_host_id']??$row['target_host_id'],
                'expires_epoch'=>$row['expires_epoch']);
            if (count($result)>=$limit)break;
        }
        return $result;
    }

    private static function verify_host_claim(array $job, array $assoc):bool {
        if (!function_exists('sodium_crypto_sign_verify_detached'))return false;
        $pub=base64_decode((string)get_option(self::PUBKEY,''),true);
        $sig=base64_decode((string)($assoc['signature']??''),true);
        $nonce=(string)($assoc['nonce']??'');
        $iat=(int)($assoc['issued']??0);
        $exp=(int)($assoc['expires']??0);
        if (!is_string($pub)||strlen($pub)!==SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            || !is_string($sig)||strlen($sig)!==SODIUM_CRYPTO_SIGN_BYTES
            || !preg_match('/^[a-f0-9]{32}$/D',$nonce)
            || $iat<time()-60||$iat>time()+10 || $exp<$iat||$exp>$iat+60||$exp<time())
            return false;
        $broker_host=$job['broker_host_id']??$job['target_host_id'];
        if ($broker_host!==(string)get_option(self::HOST_ID,'')) return false;
        $fields=array(self::CONTRACT,'claim',$job['job_id'],$job['company'],
            $job['site_uuid'],$job['environment'],$broker_host,$nonce,
            (string)$iat,(string)$exp);
        return sodium_crypto_sign_verify_detached($sig,implode("\n",$fields),$pub);
    }


    private static function verify_heartbeat(array $assoc):bool {
        if (!function_exists('sodium_crypto_sign_verify_detached')) return false;
        $host=(string)($assoc['host']??'');
        $configured=(string)get_option(self::HOST_ID,'');
        $nonce=(string)($assoc['nonce']??'');
        $iat=(int)($assoc['issued']??0);
        $exp=(int)($assoc['expires']??0);
        $pub=base64_decode((string)get_option(self::PUBKEY,''),true);
        $sig=base64_decode((string)($assoc['signature']??''),true);
        if ($host==='' || $host!==$configured
            || !preg_match('/^[a-f0-9]{32}$/D',$nonce)
            || $iat<time()-60 || $iat>time()+10
            || $exp<$iat || $exp>$iat+60 || $exp<time()
            || !is_string($pub) || strlen($pub)!==SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            || !is_string($sig) || strlen($sig)!==SODIUM_CRYPTO_SIGN_BYTES)
            return false;
        $msg=implode("\n",array(self::CONTRACT,'heartbeat',$host,
            $nonce,(string)$iat,(string)$exp));
        return sodium_crypto_sign_verify_detached($sig,$msg,$pub);
    }

    public static function status(WP_REST_Request $r) {
        if (!MAD4B_Central_SSH_Command_Intake_V1::permitted($r))
            return self::deny('authenticated_owner_required');
        $heartbeat=get_option(self::HEARTBEAT,array());
        if (!is_array($heartbeat)) $heartbeat=array();
        return rest_ensure_response(array(
            'contract'=>self::CONTRACT,
            'host_id'=>(string)get_option(self::HOST_ID,''),
            'last_signed_heartbeat_epoch'=>(int)($heartbeat['at']??0),
            'heartbeat_fresh'=>(int)($heartbeat['at']??0)>time()-600,
            'host_authenticated_by_pinned_public_key'=>!empty($heartbeat['verified']),
            'dispatch_route_active'=>true,
            'claim_route_available'=>true,
            'host_command_execution_authorized_by_heartbeat'=>false,
            'ssh_transport_session_verified_by_heartbeat'=>false,
            'production_mutation'=>false
        ));
    }

    public static function cli($args,array $assoc):void {
        if (!defined('WP_CLI')||!WP_CLI || !class_exists('WP_CLI'))
            return;
        if (get_current_user_id()<1 || !current_user_can('manage_options')
            || !class_exists('MAD4B_Central_Dual_Lane_Registry_V1',false)
            || !MAD4B_Central_Dual_Lane_Registry_V1::central())
            WP_CLI::error('Central WP-CLI host principal required');
        $action=(string)($args[0]??'');
        if ($action==='heartbeat') {
            if (!self::verify_heartbeat($assoc))
                WP_CLI::error('signed_host_heartbeat_rejected');
            $nonce=(string)$assoc['nonce'];
            if (!add_option(self::HEARTBEAT_NONCE.$nonce,time(),'',false))
                WP_CLI::error('signed_host_heartbeat_replay');
            $row=array('contract'=>self::CONTRACT,'host_id'=>(string)$assoc['host'],
                'at'=>time(),'verified'=>true,'claim_capable'=>true,
                'execution_authority_granted'=>false);
            if (!update_option(self::HEARTBEAT,$row,false))
                WP_CLI::error('heartbeat_storage_failed');
            WP_CLI::line(wp_json_encode($row,JSON_UNESCAPED_SLASHES));
            return;
        }
        if ($action==='pending') {
            WP_CLI::line(wp_json_encode(array('contract'=>self::CONTRACT,
                'pending'=>self::pending((int)($assoc['limit']??10)))));
            return;
        }
        if ($action!=='claim')WP_CLI::error('Supported broker commands: pending, claim, heartbeat');
        $id=(string)($assoc['job']??'');
        if (!preg_match('/^[a-f0-9]{32}$/D',$id))WP_CLI::error('invalid_job_id');
        $job=get_option(self::JOB.$id,null);
        if (!is_array($job)||($job['state']??'')!=='QUEUED_FOR_VPS_BROKER_CLAIM')
            WP_CLI::error('job_not_queued');
        $source=self::fields($job['company'],$id);
        if (is_wp_error($source))WP_CLI::error($source->get_error_code());
        if (!self::verify_host_claim($job,$assoc))
            WP_CLI::error('broker_signature_invalid');
        // Claim and signed pre-dispatch recovery compete for the SAME durable
        // exclusive reservation. Once one wins, the other can never dispatch.
        if (!add_option(self::ROUTE_GATE.$id,
            array('decision'=>'CLAIM','at'=>time()),'',false))
            WP_CLI::error('job_route_reserved_or_recovery_started');
        $claim=array('contract'=>self::CONTRACT,'job_id'=>$id,
            'host_id'=>$job['broker_host_id']??$job['target_host_id'],
            'nonce'=>$assoc['nonce'],'claimed_epoch'=>time(),
            'state'=>'BROKER_CLAIMED_PENDING_INDEPENDENT_HOST_AUTHORITY',
            'production_mutation_performed'=>false);
        if (!add_option(self::CLAIM.$id,$claim,'',false))
            WP_CLI::error('job_already_claimed');
        $job['state']=$claim['state'];
        $job['claimed_epoch']=$claim['claimed_epoch'];
        $job['independent_host_permission_verified']=false;
        if (!update_option(self::JOB.$id,$job,false)) WP_CLI::error('claim_update_failed');
        WP_CLI::line(wp_json_encode($claim,JSON_UNESCAPED_SLASHES));
    }

    public static function routes():void {
        if (!class_exists('MAD4B_Central_Dual_Lane_Registry_V1',false)
            || !MAD4B_Central_Dual_Lane_Registry_V1::central())return;
        $base='/central-execution-lanes/(?P<company>[a-z][a-z0-9-]{2,47})';
        register_rest_route('mad4b/v1',$base.'/broker-status',array(
            'methods'=>'GET',
            'permission_callback'=>array('MAD4B_Central_SSH_Command_Intake_V1','permitted'),
            'callback'=>array(__CLASS__,'status')));
        register_rest_route('mad4b/v1',$base.'/broker-dispatch',array(
            'methods'=>'POST','permission_callback'=>array('MAD4B_Central_SSH_Command_Intake_V1','permitted'),
            'callback'=>array(__CLASS__,'dispatch')));
        register_rest_route('mad4b/v1',$base.'/broker-jobs/(?P<job_id>[a-f0-9]{32})',array(
            'methods'=>'GET','permission_callback'=>array('MAD4B_Central_SSH_Command_Intake_V1','permitted'),
            'callback'=>array(__CLASS__,'job')));
    }
    public static function boot():void {
        add_action('rest_api_init',array(__CLASS__,'routes'),95);
        if (defined('WP_CLI')&&WP_CLI&&class_exists('WP_CLI'))
            WP_CLI::add_command('mad4b broker',array(__CLASS__,'cli'));
    }
}
MAD4B_Central_VPS_Broker_V1::boot();
require_once __DIR__.'/306-mad4b-original-core-recovery.php';

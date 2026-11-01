<?php
/**
 * Original Manager CLI adapter for a signed Native VPS SSH principal.
 * Staging only. Signature authenticates the caller, NEVER grants a company
 * assignment, issues 305 grants, or executes remote shell.
 */
defined('ABSPATH') || exit;
final class MAD4B_Company_Manager_SSH_Principal_V1 {
    const CONTRACT='mad4b.company-manager-ssh-operation.v1';
    const NONCE='mad4b_company_manager_ssh_nonce_v1_';
    const PUBKEY='mad4b_central_broker_signing_public_v1';

    private static function error($why) {
        return new WP_Error('mad4b_manager_ssh_'.$why,'Signed manager CLI refused: '.$why,['status'=>403]);
    }
    public static function evaluate($encoded, $now=null) {
        if (!defined('WP_CLI') || !WP_CLI ||
            !class_exists('MAD4B_Company_Portfolio_Manager_V1',false) ||
            !class_exists('MAD4B_Company_Operation_Admission_V1',false) ||
            !class_exists('MAD4B_Native_VPS_Principal_V1',false) ||
            !MAD4B_Company_Portfolio_Manager_V1::staging())
            return self::error('staging_cli_only');
        if (!is_string($encoded) || strlen($encoded)>12000 ||
            !preg_match('/^[A-Za-z0-9+\/=]+$/D',$encoded)) return self::error('envelope_format');
        $raw=base64_decode($encoded,true);
        if (!is_string($raw) || strlen($raw)>8500) return self::error('envelope_invalid');
        $e=json_decode($raw,true);
        if (!is_array($e) || array_keys($e)!==['payload','signature'] ||
            !is_string($e['payload']) || !is_string($e['signature']))
            return self::error('envelope_schema');
        $bytes=base64_decode($e['payload'],true);
        $signature=base64_decode($e['signature'],true);
        $pub=base64_decode((string)get_option(self::PUBKEY,''),true);
        if (!is_string($bytes) || strlen($bytes)>5000 ||
            !is_string($signature) || strlen($signature)!==64 ||
            !is_string($pub) || strlen($pub)!==32 ||
            !function_exists('sodium_crypto_sign_verify_detached') ||
            !sodium_crypto_sign_verify_detached($signature,self::CONTRACT."\n".$bytes,$pub))
            return self::error('signature_invalid');
        $v=json_decode($bytes,true);
        $required=['contract','operation','company','environment','site_uuid',
            'canonical_origin','binding_sha256','manager_id','actor_user_id',
            'expected_revision','rights','reason','expected_proposal_sha256',
            'issued','expires','nonce'];
        if (!is_array($v) || array_keys($v)!==$required ||
            $v['contract']!==self::CONTRACT ||
            $v['environment']!=='staging' ||
            !in_array($v['operation'],['propose','approve','activate'],true) ||
            !is_int($v['actor_user_id']) || $v['actor_user_id']<1 ||
            !is_int($v['expected_revision']) || $v['expected_revision']<0 ||
            !is_int($v['issued']) || !is_int($v['expires']) ||
            !is_string($v['nonce']) ||
            preg_match('/^[a-f0-9]{32}$/D',$v['nonce'])!==1)
            return self::error('scope_schema');
        $time=$now===null?time():$now;
        if ($v['issued']>$time+5 || $v['expires']<$time ||
            $v['expires']<=$v['issued'] || $v['expires']-$v['issued']>60)
            return self::error('proof_expired');
        $user=(int)get_current_user_id();
        if ($user!==$v['actor_user_id'] || !current_user_can('manage_options') ||
            !current_user_can('activate_plugins') || !current_user_can('install_plugins'))
            return self::error('wordpress_actor_not_authenticated');
        $manager=MAD4B_Company_Operation_Admission_V1::manager_id();
        if (!is_string($manager) || $manager!==$v['manager_id'])
            return self::error('manager_mismatch');
        $managers=get_option(MAD4B_Company_Portfolio_Manager_V1::MANAGERS,[]);
        $members=get_option(MAD4B_Company_Portfolio_Manager_V1::MEMBERS,[]);
        if (($managers[$manager]['state']??null)!=='registered' ||
            ($members[$manager.'|'.$user]['state']??null)!=='active')
            return self::error('manager_membership_not_active');
        $scope=MAD4B_Native_VPS_Principal_V1::scope();
        $principal=MAD4B_Native_VPS_Principal_V1::existing();
        if (!is_array($scope) || !is_array($principal) ||
            ($scope['contract']??null)!=='mad4b.central-host-scope.v1' ||
            ($scope['state']??null)!=='CENTRAL_HOST_IDENTITY_VERIFIED' ||
            ($scope['environment']??null)!=='staging' ||
            ($scope['workload']??null)!==$v['company'] ||
            ($scope['site_uuid']??null)!==$v['site_uuid'] ||
            ($scope['canonical_origin']??null)!==$v['canonical_origin'] ||
            !MAD4B_Native_VPS_Principal_V1::ledger($principal,$scope))
            return self::error('native_host_scope_unverified');
        $binding=MAD4B_Company_Portfolio_Manager_V1::company_binding((string)$v['company']);
        if (is_wp_error($binding) ||
            ($binding['company_site_uuid']??null)!==$v['site_uuid'] ||
            !is_string($v['binding_sha256']) ||
            !hash_equals((string)($binding['binding_sha256']??''),$v['binding_sha256']))
            return self::error('company_binding_mismatch');
        if ($v['operation']==='propose') {
            if ($v['expected_revision']<0 || !is_array($v['rights']) ||
                !array_is_list($v['rights']) ||
                !in_array('operations_manage',$v['rights'],true) ||
                is_wp_error(MAD4B_Company_Portfolio_Manager_V1::normalize_rights($v['rights'])) ||
                !is_string($v['reason']) || strlen(trim($v['reason']))<12 ||
                strlen($v['reason'])>1200 || $v['expected_proposal_sha256']!==null)
                return self::error('proposal_invalid');
        } else {
            if ($v['rights']!==null || $v['reason']!==null ||
                ($v['operation']==='approve' &&
                 (!is_string($v['expected_proposal_sha256']) ||
                  !preg_match('/^[a-f0-9]{64}$/D',$v['expected_proposal_sha256']))) ||
                ($v['operation']==='activate' && $v['expected_proposal_sha256']!==null))
                return self::error('transition_invalid');
        }
        return $v;
    }
    public static function apply($encoded) {
        $v=self::evaluate($encoded);
        if (is_wp_error($v))return $v;
        $nonceKey=self::NONCE.hash('sha256',$v['nonce'].'|'.$v['manager_id'].'|'.$v['company']);
        if (!add_option($nonceKey,['issued'=>$v['issued'],'expires'=>$v['expires'],
            'operation'=>$v['operation'],'actor_user_id'=>$v['actor_user_id']],'',false))
            return self::error('nonce_already_used');
        // Original manager provider implements locked exact revisions and audit.
        if ($v['operation']==='propose') {
            return MAD4B_Company_Portfolio_Manager_V1::propose([
                'manager_id'=>$v['manager_id'],'company_code'=>$v['company'],
                'rights'=>$v['rights'],'reason'=>$v['reason'],
                'expected_revision'=>$v['expected_revision']]);
        }
        return MAD4B_Company_Portfolio_Manager_V1::change_assignment([
            'manager_id'=>$v['manager_id'],'company_code'=>$v['company'],
            'action'=>$v['operation'],'expected_revision'=>$v['expected_revision'],
            'expected_proposal_sha256'=>$v['expected_proposal_sha256']??'']);
    }
    public static function cli($args,$assoc) {
        $action=(string)($args[0]??'');
        if ($action!=='apply')WP_CLI::error('USAGE: wp mad4b manager-ssh apply --proof=<signed-envelope>');
        $out=self::apply($assoc['proof']??null);
        if (is_wp_error($out))WP_CLI::error($out->get_error_code());
        WP_CLI::line(wp_json_encode([
            'contract'=>self::CONTRACT,'state'=>$out['state']??'UNKNOWN',
            'revision'=>$out['revision']??null,
            'proposal_sha256'=>$out['proposal_sha256']??null,
            'approval_sha256'=>$out['approval_sha256']??null,
            'company_code'=>$out['company_code']??null,
            'remote_execution_performed'=>false]));
    }
    public static function boot() {
        if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI'))
            WP_CLI::add_command('mad4b manager-ssh',[__CLASS__,'cli']);
    }
}
MAD4B_Company_Manager_SSH_Principal_V1::boot();

<?php
/**
 * MAD4B Staging SSH-authenticated Company Portfolio operations.
 * SSH authenticates the operator at the VPS boundary. A separate, short-lived,
 * host-pinned Ed25519 envelope supplies a WordPress actor for a SINGLE action.
 * Neither the SSH connection nor an enrolled host ledger grants manager rights.
 */
defined('ABSPATH') || exit;
final class MAD4B_Company_Manager_SSH_Principal_V1 {
    const CONTRACT='mad4b.company-manager.ssh-signed-action.v1';
    const NONCE_PREFIX='mad4b_manager_ssh_nonce_v1_';
    const PINNED_PUBKEY_OPTION='mad4b_central_broker_signing_public_v1';
    const SIGNED_FIELDS=['contract','action','company_code','environment',
        'manager_id','actor_user_id','site_uuid','canonical_origin',
        'company_binding_sha256','host_id','expected_revision',
        'expected_proposal_sha256','rights_csv','reason',
        'issued_epoch','expires_epoch','nonce'];
    const ACTIONS=['propose','approve','activate'];
    private static function fail(string $reason): WP_Error {
        return new WP_Error('mad4b_manager_ssh_'.$reason, 'Signed SSH manager action denied: '.$reason, ['status'=>403]);
    }
    public static function signed_bytes(array $p): string {
        $values=[];
        foreach(self::SIGNED_FIELDS as $k) {
            if(!array_key_exists($k,$p) || (!is_string($p[$k]) && !is_int($p[$k])))
                throw new InvalidArgumentException('MISSING_OR_INVALID_SIGNED_FIELD');
            $v=(string)$p[$k];
            if(str_contains($v,"\n") || str_contains($v,"\r"))
                throw new InvalidArgumentException('MULTILINE_SIGNED_FIELD');
            $values[]=$v;
        }
        return implode("\n",$values);
    }
    private static function verified_actor(array $p) {
        if(!defined('WP_CLI') || !WP_CLI ||
            !class_exists('MAD4B_Company_Portfolio_Manager_V1',false) ||
            !MAD4B_Company_Portfolio_Manager_V1::staging())
            return self::fail('staging_wpcli_required');
        if(($p['contract']??null)!==self::CONTRACT ||
            !in_array($p['action']??null,self::ACTIONS,true) ||
            ($p['environment']??null)!=='staging' ||
            !is_string($p['company_code']??null) ||
            !preg_match('/^[a-z][a-z0-9-]{2,47}$/D',$p['company_code']) ||
            !wp_is_uuid((string)($p['manager_id']??'')) ||
            !wp_is_uuid((string)($p['site_uuid']??'')) ||
            !preg_match('/^[a-f0-9]{64}$/D',(string)($p['company_binding_sha256']??'') ||
            !preg_match('/^[a-f0-9]{32}$/D',(string)($p['nonce']??'') ||
            !is_int($p['actor_user_id']??null) || $p['actor_user_id']<1 ||
            !is_int($p['expected_revision']??null) || $p['expected_revision']<0 ||
            !is_int($p['issued_epoch']??null) || !is_int($p['expires_epoch']??null))
            return self::fail('scope_or_envelope_invalid');
        $now=time();
        if($p['issued_epoch']>$now+5 || $p['issued_epoch']<$now-60 ||
           $p['expires_epoch']<=$now ||
           $p['expires_epoch']>$p['issued_epoch']+60)
            return self::fail('signature_expired_or_future');
        $user_id=$p['actor_user_id'];
        $u=get_userdata($user_id);
        if(!$u || !user_can($u,'manage_options') ||
            !user_can($u,'activate_plugins') || !user_can($u,'install_plugins'))
            return self::fail('wordpress_admin_actor_not_enrolled');
        $m=get_option(MAD4B_Company_Portfolio_Manager_V1::MANAGERS,[]);
        $members=get_option(MAD4B_Company_Portfolio_Manager_V1::MEMBERS,[]);
        if(($m[$p['manager_id']]['state']??'')!=='registered' ||
            ($members[$p['manager_id'].'|'.$user_id]['state']??'')!=='active')
            return self::fail('manager_membership_not_active');
        $binding=MAD4B_Company_Portfolio_Manager_V1::company_binding($p['company_code']);
        if(is_wp_error($binding) ||
            ($binding['company_site_uuid']??'')!==$p['site_uuid'] ||
            !hash_equals((string)($binding['binding_sha256']??''),$p['company_binding_sha256']))
            return self::fail('company_binding_mismatch');
        if(!class_exists('MAD4B_Native_VPS_Principal_V1',false))
            return self::fail('original_native_principal_missing');
        $scope=MAD4B_Native_VPS_Principal_V1::scope();
        $original=MAD4B_Native_VPS_Principal_V1::existing();
        if(!is_array($scope) || !is_array($original) ||
            !MAD4B_Native_VPS_Principal_V1::ledger($original,$scope) ||
            ($scope['contract']??'')!=='mad4b.central-host-scope.v1' ||
            ($scope['environment']??'')!=='staging' ||
            ($scope['workload']??'')!==$p['company_code'] ||
            ($scope['site_uuid']??'')!==$p['site_uuid'] ||
            ($scope['canonical_origin']??'')!==$p['canonical_origin'] ||
            ($scope['host_id']??'')!==$p['host_id'] ||
            ($scope['owner_enrolled']??false)!==true ||
            untrailingslashit(home_url('/'))!==$p['canonical_origin'])
            return self::fail('original_host_scope_invalid');
        // Use the already-pinned Original Core signing key; never accept a
        // public key, claimed fingerprint, or key path from the request.
        $encoded=get_option(self::PINNED_PUBKEY_OPTION,false);
        $key=is_string($encoded)?base64_decode($encoded,true):false;
        $sig=isset($p['signature'])&&is_string($p['signature'])?
             base64_decode($p['signature'],true):false;
        if(!is_string($key) || strlen($key)!==SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES ||
            !is_string($sig) || strlen($sig)!==SODIUM_CRYPTO_SIGN_BYTES)
            return self::fail('pinned_signing_key_unavailable');
        try {$signed=self::signed_bytes($p);} catch(Throwable $e) {
            return self::fail('signed_field_invalid');
        }
        if(!sodium_crypto_sign_verify_detached($sig,$signed,$key))
            return self::fail('signature_invalid');
        if(($p['action']==='propose' && ($p['expected_revision']!==0 ||
              !is_string($p['reason']??null) ||
              strlen($p['reason'])<12 || strlen($p['reason'])>1200 ||
              !is_string($p['rights_csv']??null) || $p['rights_csv']==='')) ||
            ($p['action']!=='propose' &&
              ($p['rights_csv']??null)!==''))
            return self::fail('action_payload_mismatch');
        if(!is_string($p['expected_proposal_sha256']??null) ||
           ($p['action']==='approve' &&
             !preg_match('/^[a-f0-9]{64}$/D',$p['expected_proposal_sha256'])) ||
           ($p['action']!=='approve' && $p['expected_proposal_sha256']!==''))
            return self::fail('approval_proposal_hash_missing');
        if(($p['action']!=='propose' && $p['reason']!=='') ||
            ($p['action']!=='propose' && $p['expected_revision']<1))
            return self::fail('action_arguments_invalid');
        // MySQL's option_name unique key makes first consumption atomic.
        $nonce_key=self::NONCE_PREFIX.hash('sha256',$p['host_id'].'|'.$p['nonce']);
        if(!add_option($nonce_key,['issued'=>$now,'company'=>$p['company_code'],
            'actor'=>$user_id,'action'=>$p['action']], '',false))
            return self::fail('nonce_replay');
        return $user_id;
    }
    public static function apply(array $p) {
        $actor=self::verified_actor($p);
        if(is_wp_error($actor))return $actor;
        // This process has already been authenticated by SSH and an exact
        // signed request. Do not use WP --user or a caller-selected identity.
        wp_set_current_user($actor);
        if(!current_user_can('manage_options') ||
           !current_user_can('activate_plugins'))return self::fail('actor_caps_changed');
        $base=['manager_id'=>$p['manager_id'],
            'company_code'=>$p['company_code'],
            'expected_revision'=>$p['expected_revision']];
        if($p['action']==='propose') {
            $rights=explode(',',$p['rights_csv']);
            if($rights!==array_values(array_unique($rights)) ||
                array_diff($rights,MAD4B_Company_Portfolio_Manager_V1::RIGHTS))
                return self::fail('rights_not_permitted');
            return MAD4B_Company_Portfolio_Manager_V1::propose($base+[
                'rights'=>$rights,'reason'=>$p['reason']]);
        }
        return MAD4B_Company_Portfolio_Manager_V1::change_assignment($base+[
            'action'=>$p['action'],
            'expected_proposal_sha256'=>$p['expected_proposal_sha256']]);
    }
    public static function cli($args,$assoc): void {
        if(($args[0]??'')!=='apply' ||
            !is_string($assoc['envelope']??null))
            WP_CLI::error('USAGE: wp mad4b manager-ssh apply --envelope=<base64>');
        $raw=base64_decode($assoc['envelope'],true);
        $p=is_string($raw)&&strlen($raw)<=6000?json_decode($raw,true):null;
        if(!is_array($p))WP_CLI::error('INVALID_SSH_ENVELOPE');
        $r=self::apply($p);
        if(is_wp_error($r))WP_CLI::error($r->get_error_code());
        WP_CLI::line(wp_json_encode([
            'contract'=>self::CONTRACT.'.receipt',
            'action'=>$p['action'],'company'=>$p['company_code'],
            'manager_id'=>$p['manager_id'],'state'=>$r['state']??null,
            'revision'=>$r['revision']??null,
            'proposal_sha256'=>$r['proposal_sha256']??null,
            'signed_ssh_principal_verified'=>true,
            'executed_native_ssh_job'=>false,
            'production_mutated'=>false]));
    }
    public static function boot(): void {
        if(defined('WP_CLI')&&WP_CLI && class_exists('WP_CLI'))
            WP_CLI::add_command('mad4b manager-ssh',[__CLASS__,'cli']);
    }
}
MAD4B_Company_Manager_SSH_Principal_V1::boot();

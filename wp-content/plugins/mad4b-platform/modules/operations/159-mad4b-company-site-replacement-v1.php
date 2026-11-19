<?php
/**
 * Plugin Name: MAD4B Company Site Replacement State (Central)
 * Description: Quarantined replacements and historical identities. No write grants or auto-enrollment.
 * Version: 0.1.0
 */
defined('ABSPATH') || exit;
final class MAD4B_Company_Site_Replacement_V1 {
    const OPTION='mad4b_company_site_replacements_v1';
    const CONTRACT='mad4b.company-site-replacement.v1';
    public static function enabled():bool {
        return function_exists('wp_get_environment_type') && wp_get_environment_type()==='staging'
          && untrailingslashit(home_url('/'))==='https://tours.dev.mad4b.com';
    }
    public static function records():array {
        if(!self::enabled())return [];
        $r=get_option(self::OPTION,[]);
        return is_array($r)?$r:[];
    }
    public static function directory_candidates():array {
        $out=[];
        foreach(self::records() as $company=>$row) {
            if(!is_string($company) || !preg_match('/^[a-z][a-z0-9-]{2,47}$/D',$company)
              || !is_array($row) || ($row['contract']??'')!==self::CONTRACT
              || ($row['company_id']??'')!==$company || ($row['environment']??'')!=='production'
              || !in_array($row['state']??'',['PENDING_NATIVE_SITE_PROFILE','PENDING_NATIVE_REGISTRATION','PENDING_NATIVE_305','PENDING_RECONCILE'],true)
              || !wp_is_uuid((string)($row['site_uuid']??'')))continue;
            $u=wp_parse_url((string)($row['canonical_origin']??''));
            if(!is_array($u)||($u['scheme']??'')!=='https'||empty($u['host'])||isset($u['user'])
              ||isset($u['pass'])||isset($u['port'])||isset($u['query'])||isset($u['fragment'])
              || !in_array($u['path']??'',['','/'],true))continue;
            $out[$company]=['name'=>(string)($row['display_name']??$company),
              'url'=>'https://'.$u['host'],'host'=>$u['host'],
              'feature_variant'=>'replacement_pending',
              'provisioning_verified'=>false,
              'registration_source'=>'replacement_pending_non_authorizing',
              'replacement_target_site_uuid'=>$row['site_uuid']];
        }
        return $out;
    }
    /** Explicit operator observation of an ORIGINAL site profile on a replaced VPS site.
     * This records a read-only fact. It does not verify P-256/305, change an
     * approved registry binding, or grant publication/remote-write access.
     */
    public static function record_site_profile_readback(array $proof):array {
        if(!self::enabled() || !defined('WP_CLI') || !WP_CLI
          || !current_user_can('manage_options'))
            return ['state'=>'DENIED','reason'=>'ORIGINAL_CENTRAL_WPCLI_OPERATOR_REQUIRED'];
        $company=(string)($proof['company_id']??'');
        $all=self::records();$prior=$all[$company]??null;
        if(!is_array($prior)||($prior['contract']??'')!==self::CONTRACT
          || ($prior['environment']??'')!=='production'
          || !in_array(($prior['state']??''),['PENDING_NATIVE_SITE_PROFILE','PENDING_NATIVE_305'],true)
          || ($proof['contract']??'')!=='mad4b.site-profile.v2'
          || ($proof['readback_method']??'')!=='root_vps_wpcli_read_only'
          || ($proof['environment']??'')!=='production'
          || ($proof['site_uuid']??'')!==($prior['site_uuid']??'')
          || ($proof['canonical_origin']??'')!==($prior['canonical_origin']??'')
          || !wp_is_uuid((string)($proof['site_uuid']??''))
          || !preg_match('/^[a-f0-9]{64}$/D',(string)($proof['profile_digest']??''))
          || (int)($proof['revision']??0)<1
          || ($proof['write_enabled']??true)!==false
          || abs(time()-(int)($proof['observed_at_epoch']??0))>120)
            return ['state'=>'DENIED','reason'=>'ORIGINAL_PROFILE_EXACT_READBACK_REQUIRED'];
        if(($prior['state']??'')==='PENDING_NATIVE_305') {
            $ok=($prior['native_site_profile_uuid']??null)===$proof['site_uuid']
              && ($prior['native_profile_digest']??'')===$proof['profile_digest'];
            return ['state'=>$ok?'PENDING_NATIVE_305':'DENIED',
              'reason'=>$ok?'IDEMPOTENT':'PROFILE_OBSERVATION_CONFLICT','write_authorized'=>false];
        }
        $next=$prior;
        $next['native_site_profile_uuid']=$proof['site_uuid'];
        $next['native_profile_digest']=$proof['profile_digest'];
        $next['native_profile_revision']=(int)$proof['revision'];
        $next['profile_observation_method']='root_vps_wpcli_read_only';
        $next['profile_observed_at_epoch']=(int)$proof['observed_at_epoch'];
        $next['native_305_verified']=false;
        $next['active_original_305_inherited']=false;
        $next['publication_authorized']=false;
        $next['state']='PENDING_NATIVE_305';
        // Compare the original read before mutation and verify exact readback.
        $latest=self::records();
        if(($latest[$company]??null)!==$prior)
            return ['state'=>'DENIED','reason'=>'CONCURRENT_REPLACEMENT_STATE_CHANGED'];
        $latest[$company]=$next;
        if(!update_option(self::OPTION,$latest,false) && get_option(self::OPTION,[])!==$latest)
            return ['state'=>'DENIED','reason'=>'OBSERVATION_STORE_FAILED'];
        $r=self::records()[$company]??[];
        if($r!==$next) return ['state'=>'DENIED','reason'=>'OBSERVATION_READBACK_UNCERTAIN'];
        return ['state'=>'PENDING_NATIVE_305','company_id'=>$company,
          'observed_site_uuid'=>$proof['site_uuid'],'profile_digest'=>$proof['profile_digest'],
          'native_305_verified'=>false,'publication_authorized'=>false,'write_authorized'=>false];
    }
    /** Bind a verified replacement profile as identity only, not as a 305 grant.
     * Requires a fresh, independent target WP-CLI readback and central operator.
     */
    public static function register_verified_profile_binding(array $proof):array {
        if(!self::enabled() || !defined('WP_CLI') || !WP_CLI || !current_user_can('manage_options'))
            return ['state'=>'DENIED','reason'=>'CENTRAL_OPERATOR_REQUIRED'];
        $company=(string)($proof['company_id']??'');
        $replacement=self::records()[$company]??null;
        if(!is_array($replacement) || ($replacement['contract']??'')!==self::CONTRACT
            || ($replacement['environment']??'')!=='production'
            || ($replacement['state']??'')!=='PENDING_NATIVE_305'
            || ($replacement['native_305_verified']??true)!==false
            || ($replacement['site_uuid']??'')!==($proof['site_uuid']??null)
            || ($replacement['native_site_profile_uuid']??'')!==($proof['site_uuid']??null)
            || ($replacement['native_profile_digest']??'')!==($proof['profile_digest']??null)
            || ($replacement['canonical_origin']??'')!==($proof['canonical_origin']??null)
            || ($proof['readback_method']??'')!=='original_target_wpcli'
            || ($proof['environment']??'')!=='production'
            || ($proof['write_enabled']??true)!==false
            || ($proof['origin_enrolled']??false)!==true
            || ($proof['host_identity_matched']??false)!==true
            || (int)($proof['revision']??0)!==(int)($replacement['native_profile_revision']??-1)
            || abs(time()-(int)($proof['observed_at_epoch']??0))>120
            || !wp_is_uuid((string)($proof['site_uuid']??''))
            || !preg_match('/^[a-f0-9]{64}$/D',(string)($proof['profile_digest']??'')))
            return ['state'=>'DENIED','reason'=>'EXACT_FRESH_REPLACEMENT_PROFILE_READBACK_REQUIRED'];
        global $wpdb;
        $lock='mad4b:cutover-bind:'.substr(hash('sha256',$company),0,35);
        if((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,3)',$lock))!==1)
            return ['state'=>'DENIED','reason'=>'REGISTRY_LOCK_UNAVAILABLE'];
        try {
            $all=get_option('mad4b_tourism_company_site_registry_v1',[]);
            if(!is_array($all))return ['state'=>'DENIED','reason'=>'REGISTRY_INVALID'];
            $old=$all[$company]??null;
            if(is_array($old)) {
                if(($old['company_site_uuid']??null)===$proof['site_uuid']
                    && ($old['native_profile_digest']??null)===$proof['profile_digest']
                    && ($old['registration_mode']??null)==='local_vps_verified_readback')
                    return ['state'=>'BINDING_VERIFIED','site_uuid'=>$proof['site_uuid'],
                        'already_registered'=>true,'authority_transferred'=>false];
                return ['state'=>'DENIED','reason'=>'COMPANY_BINDING_CONFLICT'];
            }
            if($old!==null)return ['state'=>'DENIED','reason'=>'INVALID_BINDING_SLOT'];
            $row=[
                'company_code'=>$company,
                'canonical_origin'=>$proof['canonical_origin'],
                'company_site_uuid'=>$proof['site_uuid'],
                'native_site_profile_uuid'=>$proof['site_uuid'],
                'native_profile_digest'=>$proof['profile_digest'],
                'host_binding_verified'=>true,
                'registration_mode'=>'local_vps_verified_readback',
                'environment'=>'production',
                'identity_only'=>true,
                'native_305_verified'=>false,
                'native_write_authorized'=>false,
                'publication_authorized'=>false,
                'previous_site_uuid'=>$replacement['history'][0]['site_uuid']??null,
                'verified_at'=>gmdate('c'),
            ];
            $all[$company]=$row;
            if(!update_option('mad4b_tourism_company_site_registry_v1',$all,false)
               && get_option('mad4b_tourism_company_site_registry_v1',[])!==$all)
                return ['state'=>'DENIED','reason'=>'REGISTRY_PERSISTENCE_FAILED'];
            $check=get_option('mad4b_tourism_company_site_registry_v1',[])[$company]??null;
            if($check!==$row)return ['state'=>'DENIED','reason'=>'REGISTRY_READBACK_FAILED'];
            return ['state'=>'BINDING_VERIFIED','site_uuid'=>$row['company_site_uuid'],
                'already_registered'=>false,'authority_transferred'=>false,
                'native_305_verified'=>false,'publication_authorized'=>false];
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));
        }
    }
    /** Original root-signed replacement readback. Separates native access
     * cutover from content reconciliation and commercial publication.
     */
    public static function observe_signed_native_cutover(array $p):array {
        $fields=['contract','action','company_id','environment','site_uuid',
            'canonical_origin','profile_revision','profile_digest',
            'host_binding_state','issued','expires','nonce'];
        if(!self::enabled()||!defined('WP_CLI')||!WP_CLI||!current_user_can('manage_options')
           ||array_keys($p)!==array_merge($fields,['signature']))
            return ['state'=>'DENIED','reason'=>'ORIGINAL_CENTRAL_SIGNED_OPERATOR_REQUIRED'];
        $now=time();$company=(string)$p['company_id'];$env=(string)$p['environment'];
        if($p['contract']!=='mad4b.company-native-cutover-observation.v1'
           ||$p['action']!=='observe'||!preg_match('/^[a-z][a-z0-9-]{2,47}$/D',$company)
           ||$env!=='production'||!wp_is_uuid((string)$p['site_uuid'])
           ||!is_int($p['profile_revision'])||$p['profile_revision']<1
           ||!preg_match('/^[a-f0-9]{64}$/D',(string)$p['profile_digest'])
           ||$p['host_binding_state']!=='PRODUCTION_HOST_BINDING_VERIFIED'
           ||!is_int($p['issued'])||!is_int($p['expires'])
           ||$p['issued']<$now-45||$p['issued']>$now+5
           ||$p['expires']<$now||$p['expires']>$p['issued']+60
           ||!preg_match('/^[a-f0-9]{32}$/D',(string)$p['nonce']))
            return ['state'=>'DENIED','reason'=>'EXACT_CUTOVER_PROOF_SCOPE_INVALID'];
        $pub=base64_decode((string)get_option(MAD4B_Central_VPS_Broker_V1::PUBKEY,''),true);
        $sig=base64_decode((string)$p['signature'],true);
        $message=implode("\n",array_map(static fn($f)=>(string)$p[$f],$fields));
        if(!is_string($pub)||strlen($pub)!==SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
           ||!is_string($sig)||strlen($sig)!==SODIUM_CRYPTO_SIGN_BYTES
           ||!sodium_crypto_sign_verify_detached($sig,$message,$pub))
            return ['state'=>'DENIED','reason'=>'PINNED_NATIVE_HOST_SIGNATURE_INVALID'];
        $native=MAD4B_Company_Native_WPCLI_Enrollment_V1::readback($company,$env);
        $site=MAD4B_Company_Environment_Registry_V2::target($company,$env);
        if(($native['state']??'')!=='ORIGINAL_305_PERSISTENT_PAIR_ENROLLED'
            ||($native['full_wpcli_active']??false)!==true
            ||($native['host_authority_verified']??false)!==true
            ||($native['site_uuid']??'')!==$p['site_uuid']
            ||!is_array($site)||($site['site_uuid']??'')!==$p['site_uuid']
            ||($site['canonical_origin']??'')!==$p['canonical_origin'])
            return ['state'=>'DENIED','reason'=>'ORIGINAL_REGISTERED_NATIVE_ACCESS_INVALID'];
        global $wpdb;
        $lock='mad4b:technical-cutover:'.substr(hash('sha256',$company),0,30);
        if((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,3)',$lock))!==1)
            return ['state'=>'DENIED','reason'=>'CUTOVER_OBSERVATION_LOCK_BUSY'];
        try {
            $rows=self::records();$before=$rows[$company]??null;
            if(!is_array($before)||($before['site_uuid']??'')!==$p['site_uuid']
                ||($before['canonical_origin']??'')!==$p['canonical_origin']
                ||!in_array(($before['state']??''),['PENDING_NATIVE_305','PENDING_RECONCILE'],true)
                ||($before['native_site_profile_uuid']??'')!==$p['site_uuid']
                ||$p['profile_revision']<(int)($before['native_profile_revision']??0))
                return ['state'=>'DENIED','reason'=>'ORIGINAL_REPLACEMENT_SCOPE_OR_REVISION_INVALID'];
            if(($before['state']??'')==='PENDING_RECONCILE'
                &&(int)($before['native_profile_revision']??0)===$p['profile_revision']
                &&($before['native_profile_digest']??'')===$p['profile_digest']
                &&($before['native_305_verified']??false)===true)
                return ['state'=>'PENDING_RECONCILE','idempotent'=>true,'publication_authorized'=>false];
            if((int)($before['native_profile_revision']??0)===$p['profile_revision']
                &&($before['native_profile_digest']??'')!==$p['profile_digest'])
                return ['state'=>'DENIED','reason'=>'SAME_REVISION_PROFILE_DIGEST_DRIFT'];
            if(!add_option('mad4b_native_cutover_nonce_v1_'.$p['nonce'],
                ['site_uuid'=>$p['site_uuid'],'receipt_sha256'=>hash('sha256',$message)],'',false))
                return ['state'=>'DENIED','reason'=>'NATIVE_CUTOVER_PROOF_REPLAY'];
            $after=$before;
            $after['native_profile_revision']=$p['profile_revision'];
            $after['native_profile_digest']=$p['profile_digest'];
            $after['native_305_verified']=true;
            $after['native_access_cutover_verified']=true;
            $after['native_host_binding_verified']=true;
            $after['native_cutover_observed_at_epoch']=$now;
            $after['native_cutover_receipt_sha256']=hash('sha256',$message);
            $after['state']='PENDING_RECONCILE';
            $after['publication_authorized']=false;
            $after['commercial_authorized']=false;
            if(self::records()[$company]!==$before)
                return ['state'=>'DENIED','reason'=>'CONCURRENT_CUTOVER_CHANGE'];
            if(!class_exists('MAD4B_SCP_Audit') || is_wp_error(MAD4B_SCP_Audit::record(
                'mad4b/company-native-cutover-observed',
                ['company'=>$company,'site_uuid'=>$p['site_uuid'],
                 'profile_revision'=>$p['profile_revision'],
                 'proof_sha256'=>hash('sha256',$message),
                 'publication_authorized'=>false],'ok')))
                return ['state'=>'DENIED','reason'=>'NATIVE_CUTOVER_AUDIT_UNAVAILABLE'];
            $rows[$company]=$after;
            if(!update_option(self::OPTION,$rows,false))
                return ['state'=>'DENIED','reason'=>'NATIVE_CUTOVER_LEDGER_SAVE_FAILED'];
            if((self::records()[$company]??null)!==$after)
                return ['state'=>'DENIED','reason'=>'NATIVE_CUTOVER_LEDGER_READBACK_MISMATCH'];
            return ['state'=>'PENDING_RECONCILE','company_id'=>$company,
                'site_uuid'=>$p['site_uuid'],'profile_revision'=>$p['profile_revision'],
                'native_access_cutover_verified'=>true,
                'publication_authorized'=>false,'commercial_authorized'=>false];
        } finally { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock)); }
    }

    public static function stage_state($company):array {
        $r=self::records()[$company]??null;
        if(!is_array($r))return ['state'=>'NO_REPLACEMENT'];
        return ['contract'=>self::CONTRACT,'company_id'=>$company,
          'state'=>$r['state']??'UNKNOWN',
          'new_site_uuid'=>$r['site_uuid']??null,
          'canonical_origin'=>$r['canonical_origin']??null,
          'old_site_in_history'=>!empty($r['history']),
          'site_profile_enrolled'=>!empty($r['native_site_profile_uuid']),
          'native_305_auto_inherited'=>false,
          'published_eligible'=>false];
    }
}

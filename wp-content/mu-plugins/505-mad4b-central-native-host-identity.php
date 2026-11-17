<?php
/**
 * MAD4B Central pinned-SSH Production site identity registration.
 * Registers exact readback as read-only identity; never grants 305/312/313.
 */
defined('ABSPATH') || exit;
final class MAD4B_Central_Native_Host_Identity_V1 {
    const CONTRACT='mad4b.central.pinned-ssh-site-readback-registration.v1';
    const OPTION='mad4b_tourism_company_site_registry_v1';
    public static function register(array $proof) {
        if ( ! defined('WP_CLI') || ! WP_CLI || PHP_SAPI!=='cli'
            || (int)get_current_user_id()!==0
            || (string)getenv('MAD4B_CENTRAL_SSH_REGISTRATION')!=='1'
            || !defined('DB_NAME') || DB_NAME!=='etg_commerce_staging'
            || wp_get_environment_type()!=='staging'
            || untrailingslashit(home_url('/'))!=='https://tours.dev.mad4b.com' )
            return new WP_Error('mad4b_central_native_host_admission_denied','Pinned Central Host CLI is required.');

        $company=$proof['company']??null;
        $origin=$proof['origin']??null;
        $uuid=$proof['site_uuid']??null;
        $digest=$proof['profile_digest']??null;
        if ( ! is_string($company) || ! preg_match('/^[a-z][a-z0-9-]{2,47}$/D',$company)
            || ! is_string($origin) || ! is_string($uuid) || ! wp_is_uuid($uuid)
            || ! is_string($digest) || ! preg_match('/^[a-f0-9]{64}$/D',$digest)
            || ($proof['environment']??null)!=='production'
            || ($proof['host_transport']??null)!=='pinned_hostinger_ssh_wpcli'
            || ($proof['source_contract']??null)!=='mad4b.central.native-site-profile.saved.v1'
            || ($proof['profile_audit_verified']??false)!==true
            || ($proof['write_enabled']??true)!==false
            || (int)($proof['revision']??0)!==1
            || !class_exists('MAD4B_Central_Company_REST_V1',false) )
            return new WP_Error('mad4b_central_host_readback_invalid','Exact Production readback required.');

        $directory=MAD4B_Central_Company_REST_V1::directory();
        if ( ! is_array($directory) || ! isset($directory[$company])
            || ($directory[$company]['url']??null)!==$origin )
            return new WP_Error('mad4b_central_host_company_origin_unrecognized','Company origin differs from Central source directory.');
        if ( !class_exists('MAD4B_SCP_Audit',false)
            || empty(MAD4B_SCP_Audit::storage_status()['ready']) )
            return new WP_Error('mad4b_central_host_audit_unready','Central append-only audit required.');

        global $wpdb;
        $lock='mad4b:central-native-identity:'.substr(hash('sha256',$company),0,32);
        if ( !isset($wpdb) || (int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,3)',$lock))!==1 )
            return new WP_Error('mad4b_central_host_lock_unavailable','Exact company registry lock unavailable.');
        try {
            $before=get_option(self::OPTION,array());
            if(!is_array($before))return new WP_Error('mad4b_central_host_registry_invalid','Company registry malformed.');
            $prior=$before[$company]??null;
            if (is_array($prior) && ($prior['company_code']??null)===$company
                && ($prior['canonical_origin']??null)===$origin
                && ($prior['company_site_uuid']??null)===$uuid
                && ($prior['native_site_profile_uuid']??null)===$uuid
                && ($prior['native_profile_digest']??null)===$digest
                && ($prior['registration_mode']??null)==='local_vps_verified_readback'
                && ($prior['identity_only']??false)===true)
                return array('state'=>'ALREADY_REGISTERED','company'=>$company,'site_uuid'=>$uuid,
                    'write_authorized'=>false,'source'=>self::CONTRACT);
            if($prior!==null)return new WP_Error('mad4b_central_host_existing_binding_conflict','Existing company identity cannot be replaced automatically.');

            $row=array(
                'company_code'=>$company,
                'canonical_origin'=>$origin,
                'company_site_uuid'=>$uuid,
                'native_site_profile_uuid'=>$uuid,
                'native_profile_digest'=>$digest,
                'host_binding_verified'=>true,
                'registration_mode'=>'local_vps_verified_readback',
                'environment'=>'production',
                'identity_only'=>true,
                'preferred_identity_provisioner'=>'central_native_pinned_ssh',
                'wordpress_admin_fallback_available'=>true,
                'native_305_verified'=>false,
                'native_write_authorized'=>false,
                'publication_authorized'=>false,
                'source_contract'=>self::CONTRACT,
                'verified_at'=>gmdate('c')
            );
            $after=$before;
            $after[$company]=$row;
            if(!update_option(self::OPTION,$after,false)
                && get_option(self::OPTION,array())!==$after)
                return new WP_Error('mad4b_central_host_registry_save_failed','Central registry write failed.');
            if(get_option(self::OPTION,array())!==$after)
                return new WP_Error('mad4b_central_host_registry_readback_mismatch','Central registry readback mismatch.');
            $audit=MAD4B_SCP_Audit::record('mad4b/central-pinned-ssh-site-readback-registered',
                array('company'=>$company,'origin'=>$origin,'site_uuid'=>$uuid,
                    'native_profile_digest'=>$digest,'authority'=>'central_native_pinned_ssh',
                    'write_authorized'=>false,'original313_signed'=>false),'ok');
            if(is_wp_error($audit)){
                if(get_option(self::OPTION,array())===$after)update_option(self::OPTION,$before,false);
                return new WP_Error('mad4b_central_host_registry_audit_failed','Registration audit failed; registry restored if still current.');
            }
            return array('state'=>'EXACT_READ_ONLY_HOST_IDENTITY_REGISTERED',
                'company'=>$company,'site_uuid'=>$uuid,
                'identity_verified'=>true,'write_authorized'=>false,'original313_signed'=>false,
                'source'=>self::CONTRACT);
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));
        }
    }
}

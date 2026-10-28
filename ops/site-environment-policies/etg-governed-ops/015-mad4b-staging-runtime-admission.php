<?php
/**
 * Plugin Name: MAD4B Staging Runtime Admission
 * Description: Defense-in-depth for marked provider HTTP requests; no provider mutations.
 * Version: 0.1.0
 */
if (!defined('ABSPATH') || !function_exists('wp_get_environment_type')) return;
if (wp_get_environment_type() !== 'staging') return;

final class MAD4B_ETG_Runtime_Admission {
    const OPTION = 'mad4b_staging_external_provider_profiles_v1';

    /** No customer profile may promote unverified host evidence to Production. */
    public static function environment_status() {
        $raw = wp_get_environment_type();
        $host = defined('WP_ENVIRONMENT_TYPE') ? (string) WP_ENVIRONMENT_TYPE : '';
        $effective = class_exists('MAD4B_SCP_Environment', false)
            ? (string) MAD4B_SCP_Environment::effective() : '';
        $resolution = class_exists('MAD4B_SCP_Site_Profile', false)
            ? MAD4B_SCP_Site_Profile::environment_resolution() : array();
        $profile_ok = is_array($resolution)
            && !empty($resolution['profile_environment_authoritative'])
            && empty($resolution['wordpress_profile_mismatch'])
            && (string)($resolution['effective_environment'] ?? '') === $effective;
        return array('contract'=>'mad4b.etg-runtime-environment.v1',
            'native'=>$raw,'host'=>$host,'effective'=>$effective,
            'verified'=>$raw==='staging' && $host==='staging'
                && $effective==='staging' && $profile_ok);
    }

    /** Provider records are data, not authority: a separate certificate is mandatory. */
    public static function certified_provider($provider, $purpose, $url) {
        $profiles=get_option(self::OPTION, array());
        if(!is_array($profiles) || count($profiles)>100)return false;
        if(!is_string($provider)||!preg_match('/^[a-z0-9_-]{2,64}$/D',$provider))return false;
        if(!isset($profiles[$provider]) || !is_array($profiles[$provider]))return false;
        $p=$profiles[$provider];
        if(($p['state']??'')!=='certified' || empty($p['certificate_sha256'])
          || !preg_match('/^[a-f0-9]{64}$/D',(string)$p['certificate_sha256']))return false;
        if(($p['purpose']??'')!==$purpose || !is_string($p['origin']??null))return false;
        if(!class_exists('MAD4B_SCP_Provider_Transport_Registry',false)
          || !MAD4B_SCP_Provider_Transport_Registry::descriptors($provider))return false;
        if(!class_exists('MAD4B_SCP_Provider_Compatibility_Certification',false)
          || !MAD4B_SCP_Provider_Compatibility_Certification::supports_provider($provider))return false;
        // Native facade resolves the actual registered adapter; assess_provider()
        // without its adapter argument would always report unavailable.
        $assessment=MAD4B_SCP_Provider_Compatibility_Certification::capability_certification(
            array('provider_id'=>$provider));
        if(!is_array($assessment) || ($assessment['compatibility_state']??'')!=='certified')return false;
        $eligible=false;
        foreach((array)($assessment['capabilities']??array()) as $capability){
            if(!empty($capability['read_eligible']) || !empty($capability['write_eligible'])){
                $eligible=true;break;
            }
        }
        if(!$eligible)return false;
        $actual=MAD4B_SCP_Provider_Compatibility_Certification::certification_generation_sha256($provider);
        if(!is_string($actual) || !hash_equals($actual,(string)$p['certificate_sha256']))return false;
        $a=wp_parse_url($url);$b=wp_parse_url($p['origin']);
        if(!is_array($a)||!is_array($b))return false;
        if(strtolower((string)($a['scheme']??''))!=='https'
          || strtolower((string)($b['scheme']??''))!=='https'
          || strtolower((string)($a['host']??''))!==strtolower((string)($b['host']??'')))return false;
        $port_a=(int)($a['port']??443);$port_b=(int)($b['port']??443);
        return $port_a===$port_b;
    }

    /** Mutating MAD4B REST work must not cross a stale site/environment binding. */
    public static function rest_write($result, $server, $request) {
        if(null!==$result)return $result;
        if(!is_object($request)||!method_exists($request,'get_method')
            ||!method_exists($request,'get_route'))return $result;
        if(!in_array(strtoupper((string)$request->get_method()),
            array('POST','PUT','PATCH','DELETE'),true))return $result;
        $route=(string)$request->get_route();
        if(!preg_match('#^/(?:mad4b(?:[/-]|$)|mcp/mad4b(?:[/-]|$))#',$route))return $result;
        if(self::environment_status()['verified'])return $result;
        return new WP_Error('mad4b_etg_rest_environment_unverified',
            'MAD4B write requires exact Staging Site Profile and host binding.',
            array('status'=>423));
    }

    public static function http($pre, $args, $url) {
        if(false!==$pre && null!==$pre)return $pre;
        if(!is_array($args)||empty($args['mad4b_egress_purpose']))return $pre;
        if(!self::environment_status()['verified'])
            return new WP_Error('mad4b_etg_provider_environment_unverified','Exact Staging environment required.');
        $purpose=sanitize_key((string)$args['mad4b_egress_purpose']);
        if(isset($args['mad4b_provider_id']) &&
           !self::certified_provider((string)$args['mad4b_provider_id'],$purpose,(string)$url))
            return new WP_Error('mad4b_etg_provider_certification_required','Provider certification or endpoint binding is missing.');
        // Reuse native, tested Egress Policy. Do not implement a competing DNS/TLS engine.
        if(!class_exists('MAD4B_SCP_Egress_Policy',false))
            return new WP_Error('mad4b_etg_egress_engine_missing','Central outbound Egress Policy unavailable.');
        $health=MAD4B_SCP_Egress_Policy::status();
        if(empty($health['ready']))
            return new WP_Error('mad4b_etg_egress_policy_unready','Central outbound policy is not ready.');
        return MAD4B_SCP_Egress_Policy::enforce($pre,$args,$url);
    }
}
add_filter('pre_http_request',array('MAD4B_ETG_Runtime_Admission','http'),PHP_INT_MAX-2,3);
add_filter('rest_pre_dispatch',array('MAD4B_ETG_Runtime_Admission','rest_write'),PHP_INT_MAX-2,3);
// Deny mail if the Site Profile later diverges from the explicit host.
// This is a veto, never a mail authorization; the independent SMTP/Company
// Communication gate remains authoritative even when this test passes.
add_filter('pre_wp_mail',static function($pre) {
    return MAD4B_ETG_Runtime_Admission::environment_status()['verified'] ? $pre : false;
},PHP_INT_MAX-2,2);

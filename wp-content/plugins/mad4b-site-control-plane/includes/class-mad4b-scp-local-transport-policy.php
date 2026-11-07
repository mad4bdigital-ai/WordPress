<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/**
 * Fail-closed transport policy for WordPress dedicated Local Lab.
 * Only explicit user-attested OS-loopback ingress (optionally carried by SSH)
 * qualifies. 'localhost' in a URL alone is never proof of an isolated listener.
 * This contract does not enable CSO modules, admit providers or grant writes.
 */
final class MAD4B_SCP_Local_Transport_Policy {
    const CONTRACT = 'mad4b.local-loopback-transport.v1';
    const OPT_IN = 'MAD4B_CSO_LOCAL_HTTP_LOOPBACK';
    const TRANSPORT = 'MAD4B_CSO_LOCAL_HTTP_TRANSPORT';
    private static function parts($origin) {
        if(!is_string($origin)||strlen($origin)>240)return null;
        $parts=wp_parse_url($origin);
        if(!is_array($parts)||!isset($parts['scheme'],$parts['host'])
            ||isset($parts['user'])||isset($parts['pass'])||isset($parts['query'])
            ||isset($parts['fragment'])||isset($parts['path']))return null;
        $host=strtolower($parts['host']);
        if(!in_array($host,array('localhost','127.0.0.1'),true))return null;
        if($parts['scheme']!=='http'||!isset($parts['port'])
            ||!is_int($parts['port'])||$parts['port']<1024||$parts['port']>65535)return null;
        return $parts;
    }
    public static function loopback_enabled($origin) {
        if(!defined(self::OPT_IN)||constant(self::OPT_IN)!==true
            ||!defined(self::TRANSPORT)
            ||!in_array(constant(self::TRANSPORT),array('ssh_tunnel','same_host'),true)
            ||!function_exists('wp_get_environment_type')
            ||wp_get_environment_type()!=='local')return false;
        $parts=self::parts($origin);
        if(!$parts)return false;
        // The actual site binding may not be overridden by a request header.
        $home=wp_parse_url(home_url());
        if(!is_array($home)||($home['scheme']??'')!=='http'
            ||strtolower((string)($home['host']??''))!==strtolower($parts['host'])
            ||($home['port']??null)!==$parts['port'])return false;
        if(!class_exists('MAD4B_SCP_Site_Profile'))return false;
        $site=MAD4B_SCP_Site_Profile::status();
        return is_array($site)&&!empty($site['origin_match'])
            &&!empty($site['environment_match'])
            &&($site['configured_environment']??'')==='local'
            &&($site['wordpress_environment']??'')==='local';
    }
    public static function request_allowed($origin,$server,$post=false) {
        if(!self::loopback_enabled($origin)||!is_array($server))return false;
        if(function_exists('is_ssl')&&is_ssl())return false;
        $parts=self::parts($origin);if(!$parts)return false;
        $expected=strtolower($parts['host'].':'.$parts['port']);
        if(!isset($server['HTTP_HOST'])||!is_string($server['HTTP_HOST'])
            ||strtolower($server['HTTP_HOST'])!==$expected)return false;
        // This path is deliberately incompatible with remote reverse-proxy
        // and X-Forwarded client routing. The listener must be OS loopback.
        foreach(array('HTTP_FORWARDED','HTTP_X_FORWARDED_FOR',
            'HTTP_X_FORWARDED_HOST','HTTP_X_FORWARDED_PROTO',
            'HTTP_X_REAL_IP','HTTP_X_ORIGINAL_URL') as $key)
            if(isset($server[$key])&&$server[$key]!=='')return false;
        if($post){
            if(($server['HTTP_ORIGIN']??null)!==$origin
                ||($server['HTTP_SEC_FETCH_SITE']??null)!=='same-origin')return false;
        }
        return true;
    }
    public static function status($origin=null) {
        $origin=is_string($origin)?$origin:rtrim((string)home_url(),'/');
        return array('contract'=>self::CONTRACT,
            'wordpress_environment'=>function_exists('wp_get_environment_type')?wp_get_environment_type():'unknown',
            'opted_in'=>defined(self::OPT_IN)&&constant(self::OPT_IN)===true,
            'approved_transport'=>defined(self::TRANSPORT)?constant(self::TRANSPORT):'none',
            'loopback_http_eligible'=>self::loopback_enabled($origin),
            'browser_ssh_tunnel_attestation_required'=>true,
            'public_host_http_allowed'=>false,
            'enables_secret_provider'=>false,'authorizing'=>false);
    }
}

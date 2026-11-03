<?php
/**
 * Plugin Name: MAD4B Universal Site Signed Identity V4
 * Description: Site-owned P-256 signed identity for any originally enrolled company/environment. Never grants writes.
 * Version: 0.1.0
 */
defined('ABSPATH') || exit;
if (!class_exists('MAD4B_Universal_Site_Signed_V4', false)) {
final class MAD4B_Universal_Site_Signed_V4 {
    const PRIVATE_OPT = 'mad4b_site_signed_v4_private_v1';
    const CENTRAL_PUB = __DIR__.'/assets/central-public.pem';
    const CENTRAL_SHA = '99c714e3c54cf7ba6c9889155e440b8702fa01ae6aadc6ed19fe4aaa682cadb5';
    public static function binding(): array {
        if (!class_exists('MAD4B_SCP_Site_Profile',false) ||
            !method_exists('MAD4B_SCP_Site_Profile','status') ||
            !function_exists('wp_get_environment_type')) return [];
        $env=wp_get_environment_type();
        if (!in_array($env,['staging','production'],true)) return [];
        $status=MAD4B_SCP_Site_Profile::status();
        $saved=get_option('mad4b_scp_site_profile_v2',[]);
        $identity=get_option('mad4b_company_site_identity_v1',[]);
        $origin=untrailingslashit(home_url('/'));
        if (!is_array($status) || !is_array($saved) || !is_array($identity)
            || !preg_match('/^[a-z][a-z0-9-]{2,47}$/D',(string)($identity['company_code']??''))
            || !wp_is_uuid((string)($saved['site_uuid']??''))
            || ($identity['site_uuid']??'')!==$saved['site_uuid']
            || ($status['site_uuid']??'')!==$saved['site_uuid']
            || ($status['canonical_origin']??'')!==$origin
            || ($status['environment']??'')!==$env
            || ($status['origin_match']??false)!==true
            || ($status['environment_match']??false)!==true
            || ($status['deployment_binding_match']??false)!==true
            || ($status['deployment_binding_bound']??false)!==true
            || ($status['profile_environment_authoritative']??false)!==true
            || ($status['foreign_profile_detected']??true)!==false
            || !preg_match('/^[a-f0-9]{64}$/D',(string)($status['profile_digest']??''))) return [];
        return ['company'=>$identity['company_code'],'environment'=>$env,
                'site_uuid'=>$saved['site_uuid'],'origin'=>$origin,
                'profile_digest'=>$status['profile_digest']];
    }
    public static function central_public(): string {
        if (!is_file(self::CENTRAL_PUB) || is_link(self::CENTRAL_PUB)) return '';
        $raw=file_get_contents(self::CENTRAL_PUB);
        if (!is_string($raw) || strlen($raw)>7000 || strpos($raw,'PRIVATE KEY')!==false) return '';
        $key=openssl_pkey_get_public($raw);
        $details=$key?openssl_pkey_get_details($key):false;
        if (!is_array($details) || ($details['type']??null)!==OPENSSL_KEYTYPE_EC
            || ($details['ec']['curve_name']??'')!=='prime256v1'
            || !hash_equals(self::CENTRAL_SHA,hash('sha256',(string)$details['key']))) return '';
        return (string)$details['key'];
    }
    public static function material($current): array {
        if (!is_array($current)) return [];
        if ($current) return $current; // Do not replace an existing signed provider.
        if (!self::binding() || self::central_public()==='') return [];
        $private=get_option(self::PRIVATE_OPT,'');
        if (!is_string($private) || strlen($private)>8000) return [];
        $key=openssl_pkey_get_private($private);
        $details=$key?openssl_pkey_get_details($key):false;
        if (!is_array($details) || ($details['ec']['curve_name']??'')!=='prime256v1') return [];
        return ['central_public_pem'=>self::central_public(),'site_private_pem'=>$private];
    }
    public static function status_cli($args,$flags): void {
        if (!current_user_can('manage_options')) WP_CLI::error('Original site administrator required',77);
        $b=self::binding();
        $private=get_option(self::PRIVATE_OPT,'');
        $ready=(bool)$b && self::central_public()!=='' && is_string($private)
            && (bool)openssl_pkey_get_private($private);
        WP_CLI::line(wp_json_encode(['contract'=>'mad4b.universal-site-signed-v4-status.v1',
            'company'=>$b['company']??null,'environment'=>$b['environment']??null,
            'site_uuid'=>$b['site_uuid']??null,'canonical_origin'=>$b['origin']??null,
            'state'=>$ready?'SITE_P256_READY':'EXACT_ORIGINAL_SITE_ENROLLMENT_OR_KEY_REQUIRED',
            'remote_write_authorized'=>false,'production_mutated'=>false]));
    }
    public static function bootstrap_cli($args,$flags): void {
        if (!current_user_can('manage_options') || !current_user_can('activate_plugins'))
            WP_CLI::error('Original site administrator required',77);
        $b=self::binding();
        if (!$b || ($flags['company']??'')!==$b['company'] ||
            ($flags['environment']??'')!==$b['environment'] ||
            ($flags['site-uuid']??'')!==$b['site_uuid'] ||
            ($flags['origin']??'')!==$b['origin'] ||
            ($flags['profile-digest']??'')!==$b['profile_digest'] ||
            ($flags['central-sha256']??'')!==self::CENTRAL_SHA ||
            self::central_public()==='')
            WP_CLI::error('Original exact binding or Central key mismatch',77);
        if (get_option(self::PRIVATE_OPT,null)!==null)
            WP_CLI::error('Site key already exists; no implicit rotation',73);
        $key=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_EC,'curve_name'=>'prime256v1']);
        $private='';
        if (!$key || !openssl_pkey_export($key,$private))
            WP_CLI::error('Site-local P-256 generation failed',75);
        $details=openssl_pkey_get_details($key);
        if (!add_option(self::PRIVATE_OPT,$private,'',false))
            WP_CLI::error('Immutable site key insertion failed',75);
        unset($private);
        WP_CLI::line(wp_json_encode(['state'=>'SITE_LOCAL_P256_INITIALIZED',
            'company'=>$b['company'],'environment'=>$b['environment'],
            'site_uuid'=>$b['site_uuid'],'site_public_key_sha256'=>
              hash('sha256',(string)$details['key']),
            'remote_write_authorized'=>false,'production_mutated'=>false]));
    }
    public static function public_cli($args,$flags): void {
        if (!current_user_can('manage_options') || !self::binding())
            WP_CLI::error('Original administrator and site binding required',77);
        $private=get_option(self::PRIVATE_OPT,'');
        $key=is_string($private)?openssl_pkey_get_private($private):false;
        $details=$key?openssl_pkey_get_details($key):false;
        if (!is_array($details) || ($details['ec']['curve_name']??'')!=='prime256v1')
            WP_CLI::error('Site P-256 key missing',77);
        WP_CLI::line((string)$details['key']);
    }
    public static function boot(): void {
        add_filter('mad4b_company_p256_existing_material_v4',[__CLASS__,'material'],20,1);
        if (defined('WP_CLI') && WP_CLI) {
            WP_CLI::add_command('mad4b-site-signed-v4 status',[__CLASS__,'status_cli']);
            WP_CLI::add_command('mad4b-site-signed-v4 bootstrap',[__CLASS__,'bootstrap_cli']);
            WP_CLI::add_command('mad4b-site-signed-v4 public',[__CLASS__,'public_cli']);
        }
    }
}
MAD4B_Universal_Site_Signed_V4::boot();
if (!class_exists('MAD4B_Company_Signed_Site_Profile_V4',false))
    require_once __DIR__.'/includes/mad4b-company-signed-site-profile-v4.php';
}

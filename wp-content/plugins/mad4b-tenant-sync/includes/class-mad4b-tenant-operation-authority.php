<?php
/**
 * Environment-neutral company operation guard.
 *
 * Site identity, origin, deployment, audit and explicit operation grant are
 * the authority. A WP_ENVIRONMENT_TYPE label is NEVER a write grant.
 */
defined('ABSPATH') || exit;
final class MAD4B_Tenant_Operation_Authority {
    public static function status($write=false) {
        if(!class_exists('MAD4B_SCP_Site_Profile',false))return null;
        $s=MAD4B_SCP_Site_Profile::status();
        if(!is_array($s) || empty($s['configured'])
          || empty($s['authority_ready']) || empty($s['same_origin_clone_protection'])
          || empty($s['deployment_binding_match']) || empty($s['origin_match'])
          || empty($s['environment_match']) || !empty($s['mutation_pending_audit'])
          || !is_string($s['site_uuid']??null)
          || !function_exists('wp_is_uuid') || !wp_is_uuid($s['site_uuid']))return null;
        if($write){
            if(!class_exists('MAD4B_SCP_Audit',false))return null;
            $audit=MAD4B_SCP_Audit::storage_status();
            if(!is_array($audit)||empty($audit['ready']))return null;
        }
        return $s;
    }
    public static function scope($write=false) {
        $s=self::status($write);
        return is_array($s)?(string)$s['site_uuid']:'';
    }
    public static function admin_read() {
        return self::scope(false)!=='' && current_user_can('manage_options');
    }
    /** HTTPS on public hosts; only real loopback may use HTTP in any env. */
    public static function secure_origin() {
        $home=(string)get_option('home');
        $scheme=wp_parse_url($home,PHP_URL_SCHEME);
        $host=wp_parse_url($home,PHP_URL_HOST);
        if(!is_string($host)||$host==='')return false;
        if($scheme==='https')return true;
        return $scheme==='http'
            && in_array(strtolower($host),['localhost','127.0.0.1','::1'],true);
    }
    /** Ordinary, audited, bounded tenant mutations never depend on staging. */
    public static function local_write() {
        return self::scope(true)!=='';
    }
    /**
     * Schema is a separate signed DDL operation. No environment label,
     * WP-CLI root, plugin toggle or arbitrary caller-supplied verified=true
     * may replace an independently verified First-party scope-bound grant.
     */
    public static function schema($operation,$tables) {
        $s=self::status(true);
        if(!$s || !defined('WP_CLI') || WP_CLI!==true || get_current_user_id()!==0
          || !is_string($operation) || !preg_match('/^[a-z][a-z0-9.]{5,95}$/D',$operation)
          || !is_array($tables) || !$tables || count($tables)>8
          || !class_exists('MAD4B_Original_Tenant_Schema_Migration_Authority_V1',false)
          || !method_exists('MAD4B_Original_Tenant_Schema_Migration_Authority_V1','verify_exact'))return false;
        // Resolve exact tenant tables from this WordPress installation's
        // *current* database prefix; never accept arbitrary caller tables.
        global $wpdb;
        if(!is_object($wpdb)||!is_string($wpdb->prefix??null)
          ||!preg_match('/^[A-Za-z0-9_]{1,40}$/D',$wpdb->prefix))return false;
        $allowed=[
            'tenant.sparse.schema'=>[
                $wpdb->prefix.'mad4b_tenant_content_maps',
                $wpdb->prefix.'mad4b_tenant_field_overrides',
                $wpdb->prefix.'mad4b_tenant_hub_cache'],
            'tenant.workflow.schema'=>[$wpdb->prefix.'mad4b_tenant_approvals'],
            'tenant.media.schema'=>[$wpdb->prefix.'mad4b_tenant_media_seo'],
        ];
        if(!isset($allowed[$operation]) || $tables!==$allowed[$operation])return false;
        foreach($tables as $table)if(!is_string($table)
          ||!preg_match('/^[a-zA-Z0-9_]{5,96}$/D',$table))return false;
        $grant=MAD4B_Original_Tenant_Schema_Migration_Authority_V1::verify_exact(
            $operation,(string)$s['site_uuid'],(string)($s['configured_environment']??wp_get_environment_type()),
            array_values($tables));
        return is_array($grant) && ($grant['original_signed_312_verified']??false)===true
          && ($grant['operation']??'')===$operation
          && ($grant['site_uuid']??'')===$s['site_uuid']
          && ($grant['environment']??'')===($s['configured_environment']??wp_get_environment_type())
          && ($grant['tables']??null)===array_values($tables)
          && ($grant['schema_migration_only']??false)===true;
    }
    /** Ephemeral QA writes use a distinct signed sandbox grant, not Staging. */
    public static function qa_draft() {
        $s=self::status(true);
        if(!$s || !defined('WP_CLI') || WP_CLI!==true || get_current_user_id()!==0
          || !defined('MAD4B_HUB_EPHEMERAL_QA') || MAD4B_HUB_EPHEMERAL_QA!==true
          || !class_exists('MAD4B_Original_Tenant_QA_Sandbox_Authority_V1',false)
          || !method_exists('MAD4B_Original_Tenant_QA_Sandbox_Authority_V1','verify_exact'))return false;
        $p=MAD4B_Original_Tenant_QA_Sandbox_Authority_V1::verify_exact(
            (string)$s['site_uuid'],(string)($s['configured_environment']??wp_get_environment_type()),
            'tenant.shadow.ephemeral_qa');
        return is_array($p) && ($p['original_signed_312_verified']??false)===true
          && ($p['site_uuid']??'')===$s['site_uuid']
          && ($p['environment']??'')===($s['configured_environment']??wp_get_environment_type())
          && ($p['operation']??'')==='tenant.shadow.ephemeral_qa'
          && ($p['ephemeral_cleanup_verified']??false)===true;
    }
}

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
        // Source-owned exact table scope is discovered from the loaded
        // tenant provider, never from hardcoded central operation lists.
        // Discovery does not itself authorize DDL, and an absent/different
        // signed Original 312 grant keeps schema() false.
        if(!class_exists('MAD4B_Tenant_Schema_Provider_Catalog_V1',false))
            return false;
        $source=MAD4B_Tenant_Schema_Provider_Catalog_V1::resolve($operation,$tables);
        if(!is_array($source)
            ||($source['source_catalog_verified']??false)!==true
            ||($source['schema_mutation_authorized']??true)!==false
            ||($source['original_signed_312_verified']??true)!==false)
            return false;
        $grant=MAD4B_Original_Tenant_Schema_Migration_Authority_V1::verify_exact(
            $operation,(string)$s['site_uuid'],(string)($s['configured_environment']??wp_get_environment_type()),
            array_values($tables));
        return is_array($grant) && ($grant['original_signed_312_verified']??false)===true
          && ($grant['operation']??'')===$operation
          && ($grant['site_uuid']??'')===$s['site_uuid']
          && ($grant['environment']??'')===($s['configured_environment']??wp_get_environment_type())
          && ($grant['tables']??null)===array_values($tables)
          // A first-party signed exact-scope verifier must corroborate the
          // loaded provider source bytes and the schema declaration, not
          // just the table names: DDL logic can change without a rename.
          && ($grant['source_declaration_sha256']??null)===$source['declaration_sha256']
          && ($grant['provider_source_sha256']??null)===$source['source_sha256']
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

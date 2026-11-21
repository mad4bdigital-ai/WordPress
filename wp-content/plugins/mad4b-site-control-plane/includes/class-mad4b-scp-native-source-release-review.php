<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Read-only Central bridge for an EXACT native source-release approval input.
 *
 * This class NEVER publishes, approves, signs, issues Grants or claims Jobs.
 * A mutation is not added to Write until independently certified and mounted
 * by the first-party dynamic provider registry and normal NHI governance.
 */
final class MAD4B_SCP_Native_Source_Release_Review {
    // The two read interfaces are service contracts, NOT operation IDs.
    // Individual operations/providers are discovered from the certified
    // WordPress Ability and native first-party Registry at runtime.
    const STATUS_ABILITY = 'mad4b/native-source-catalog-status';
    const PREFLIGHT_ABILITY = 'mad4b/native-source-catalog-preflight';
    const CONTRACT = 'mad4b.native-source-dynamic-review.v2';
    private static $booted = false;

    public static function boot() {
        if ( self::$booted || ! function_exists( 'add_action' ) ) return;
        self::$booted = true;
        add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ), 32 );
    }
    public static function can_review( $input = null ) { return current_user_can( 'manage_options' ); }

    public static function register_abilities() {
        if ( ! function_exists( 'wp_register_ability' ) || ! function_exists( 'wp_has_ability' ) ) return;
        $hash = array( 'type'=>'string','pattern'=>'^[a-f0-9]{64}$' );
        $operation = array( 'type'=>'string','pattern'=>'^[a-z][a-z0-9_.-]{2,90}$' );
        $file = array( 'type'=>'object', 'additionalProperties'=>false,
            'properties'=>array( 'target'=>array('type'=>'string','minLength'=>1,'maxLength'=>240),
                'expected_before_sha256'=>$hash, 'source_sha256'=>$hash ),
            'required'=>array('target','expected_before_sha256','source_sha256') );
        $request = array( 'type'=>'object', 'additionalProperties'=>false,
            'properties'=>array('operation_id'=>$operation,'ability_name'=>array('type'=>'string','minLength'=>3),
                'provider'=>array('type'=>'string','minLength'=>1),
                'site_uuid'=>array('type'=>'string','minLength'=>36,'maxLength'=>36),
                'origin'=>array('type'=>'string','minLength'=>8),
                'company'=>array('type'=>'string','minLength'=>2),
                'environment'=>array('type'=>'string','minLength'=>2),
                'plan_sha256'=>$hash,'source_manifest_sha256'=>$hash,
                'operation_binding_sha256'=>$hash,
                'git_commit'=>array('type'=>'string','pattern'=>'^[a-f0-9]{40}$'),
                'files'=>array('type'=>'array','items'=>$file,'minItems'=>1,'maxItems'=>24) ),
            'required'=>array('operation_id','ability_name','provider','site_uuid','origin',
                'company','environment','plan_sha256','source_manifest_sha256','operation_binding_sha256','git_commit','files') );
        foreach ( array(
            self::STATUS_ABILITY=>array('Native Source Catalog Status','Inspect available registered source operations, without granting authority.','status',array('type'=>'object','additionalProperties'=>false)),
            self::PREFLIGHT_ABILITY=>array('Native Source Catalog Preflight','Compare an exact operation plan to live WordPress identity and source hashes (read-only).','preflight',$request),
        ) as $name=>$info ) {
            if ( wp_has_ability( $name ) ) continue;
            wp_register_ability( $name, array(
                'label'=>$info[0], 'description'=>$info[1], 'category'=>'mad4b-admin',
                'execute_callback'=>array(__CLASS__,$info[2]),
                'permission_callback'=>array(__CLASS__,'can_review'),
                'input_schema'=>$info[3],
                'output_schema'=>array('type'=>'object','additionalProperties'=>true),
                'meta'=>array('public'=>false,'show_in_rest'=>false,
                    'mcp'=>array('public'=>false,'type'=>'tool','surface'=>'read'),
                    'annotations'=>array('readonly'=>true,'destructive'=>false,'idempotent'=>true)),
            ) );
        }
    }

    private static function identity() {
        return array(
            'origin'=>function_exists('home_url') ? rtrim((string)home_url(),'/') : '',
            'site_uuid'=>class_exists('MAD4B_SCP_Site_Profile')
                ? strtolower((string)MAD4B_SCP_Site_Profile::site_uuid()) : '',
        );
    }

    private static function safe_file_hash( $target ) {
        if ( ! is_string( $target ) || strlen( $target ) > 240 ||
            ! preg_match( '#^wp-content/(?:plugins/(?:mad4b-|etg-)[a-z0-9-]+/|mu-plugins/(?:mad4b-|etg-))[a-zA-Z0-9._/-]+\\.(?:php|css|js|json|txt|md)$#D', $target ) ||
            strpos( $target, '..' ) !== false || strpos( $target, '//' ) !== false ||
            strpos( $target, '\\' ) !== false ) return '';
        if ( ! defined( 'WP_CONTENT_DIR' ) ) return '';
        $rel = substr( $target, strlen( 'wp-content/' ) );
        $root = realpath( WP_CONTENT_DIR );
        $path = WP_CONTENT_DIR . '/' . $rel;
        if ( false === $root || is_link( $path ) || ! is_file( $path ) || filesize( $path ) > 4000000 ) return '';
        $parent = realpath( dirname( $path ) );
        if ( false === $parent || strncmp( $parent . '/', $root . '/', strlen( $root ) + 1 ) !== 0 ) return '';
        $result = hash_file( 'sha256', $path );
        return is_string( $result ) ? $result : '';
    }

    public static function status( $input = array() ) {
        $identity = self::identity();
        // Read only the existing first-party governed write projection. Do not
        // treat arbitrary client-provided operation names as registered tools.
        $providers = array(); // This read surface has no independently verified native Registry reader.
        return array(
            'contract'=>self::CONTRACT,'state'=>'READ_ONLY_DYNAMIC_DISCOVERY',
            'site'=>$identity,'mounted_write_providers'=>$providers,
            'native_registry_readback_verified_here'=>false,
            'approval_created'=>false,'authority_created'=>false,
            'mutation_performed'=>false,'publisher_authorized'=>false,
            'original313_receipt_signed'=>false,
        );
    }

    public static function request_input( array $plan ) {
        $identity = self::identity();
        $needed=array('operation_id','ability_name','provider','site_uuid','origin',
            'company','environment','plan_sha256','source_manifest_sha256','operation_binding_sha256','git_commit','files');
        if ( count(array_diff($needed,array_keys($plan)))===0 &&
            count(array_diff(array_keys($plan),$needed))===0 ) {
            foreach(array('operation_id','provider','company','environment') as $key) {
                if (!is_string($plan[$key]) || !preg_match('/^[a-z][a-z0-9_.-]{1,90}$/D',$plan[$key])) {
                    return new WP_Error('mad4b_native_source_field_invalid','Invalid native operation/provider field.');
                }
            }
            if ( ! is_string($plan['ability_name']) ||
                ! preg_match('#^[a-z0-9][a-z0-9._-]*/[a-z0-9][a-z0-9._-]*$#D',$plan['ability_name']) ) {
                return new WP_Error('mad4b_native_source_ability_invalid','Invalid dynamic Ability.');
            }
            if (!preg_match('/^[a-f0-9]{64}$/D',(string)$plan['plan_sha256']) ||
                !preg_match('/^[a-f0-9]{64}$/D',(string)$plan['source_manifest_sha256']) ||
                !preg_match('/^[a-f0-9]{64}$/D',(string)$plan['operation_binding_sha256']) ||
                !preg_match('/^[a-f0-9]{40}$/D',(string)$plan['git_commit']) ||
                !preg_match('/^[a-f0-9-]{36}$/D',(string)$plan['site_uuid']) ||
                !is_string($plan['origin']) || !is_array($plan['files']) ||
                count($plan['files'])<1 || count($plan['files'])>24) {
                return new WP_Error('mad4b_native_source_plan_invalid','Source-release plan must be exact.');
            }
            if ($identity['origin']!==$plan['origin'] || $identity['site_uuid']!==strtolower($plan['site_uuid'])) {
                return new WP_Error('mad4b_native_source_site_mismatch','Source-release target must match this installed site.');
            }
            $files=array();
            foreach($plan['files'] as $row) {
                if (!is_array($row) ||
                    count(array_diff(array('target','expected_before_sha256','source_sha256'),array_keys($row))) ||
                    count(array_diff(array_keys($row),array('target','expected_before_sha256','source_sha256')))) {
                    return new WP_Error('mad4b_native_source_file_invalid','File hash declaration missing.');
                }
                foreach(array('expected_before_sha256','source_sha256') as $field) {
                    if (!isset($row[$field]) || !is_string($row[$field]) ||
                        !preg_match('/^[a-f0-9]{64}$/D',$row[$field])) {
                        return new WP_Error('mad4b_native_source_hash_invalid','Invalid file SHA.');
                    }
                }
                if (''===self::safe_file_hash($row['target'])) {
                    return new WP_Error('mad4b_native_source_file_not_eligible','Source target must be an existing permitted local file.');
                }
                $files[] = array('target'=>$row['target'],
                    'expected_before_sha256'=>$row['expected_before_sha256'],
                    'source_sha256'=>$row['source_sha256']);
            }
            return array(
                'contract'=>'mad4b.native-source-dynamic-approval-input.v2',
                'operation_id'=>$plan['operation_id'],'ability_name'=>$plan['ability_name'],
                'provider'=>$plan['provider'],'company'=>$plan['company'],
                'environment'=>$plan['environment'],'site_uuid'=>$plan['site_uuid'],
                'origin'=>$plan['origin'],'git_commit'=>$plan['git_commit'],
                'plan_sha256'=>$plan['plan_sha256'],
                'source_manifest_sha256'=>$plan['source_manifest_sha256'],
                'operation_binding_sha256'=>$plan['operation_binding_sha256'],
                'files'=>$files,
            );
        }
        return new WP_Error('mad4b_native_source_request_invalid','Native source-release plan fields missing.');
    }

    public static function preflight( $input ) {
        $facts=self::request_input(is_array($input)?$input:array());
        if ( is_wp_error($facts) ) return $facts;
        $observed=array();
        $cas=true;$changed=false;
        foreach($facts['files'] as $file) {
            $current=self::safe_file_hash($file['target']);
            $same = $current!=='' && hash_equals($file['expected_before_sha256'],$current);
            $cas=$cas&&$same;
            $changed=$changed||!hash_equals($file['expected_before_sha256'],$file['source_sha256']);
            $observed[]=array('target'=>$file['target'],'observed_sha256'=>$current,'preimage_matches'=>$same);
        }
        $provider=class_exists('MAD4B_SCP_Servers')
            ? MAD4B_SCP_Servers::provider_for_ability('mad4b-write',$facts['ability_name']) : null;
        $mounted=$provider!==null && $provider===$facts['provider'] &&
            function_exists('wp_has_ability') && wp_has_ability($facts['ability_name']);
        $clean=class_exists('MAD4B_SCP_Staging_Write_Authority')
            ? MAD4B_SCP_Staging_Write_Authority::authorization_input($facts,$facts['ability_name']) : $facts;
        $unchanged=is_array($clean) && $clean===$facts;
        $fingerprint = $cas && $changed && $mounted && $unchanged &&
            class_exists('MAD4B_SCP_Authorization_Target_Fingerprint')
            ? MAD4B_SCP_Authorization_Target_Fingerprint::fingerprint(
                $facts['ability_name'],$facts['provider'],$facts) : '';
        return array(
            'contract'=>self::CONTRACT,
            'state'=>$cas && $changed && $mounted && $unchanged && $fingerprint!==''
                ? 'EXACT_CAS_PREVIEW__SIGNED_HOST_ADMISSION_REQUIRED'
                : 'HOLD__DYNAMIC_PROVIDER_OR_PREIMAGE_NOT_READY',
            'operation_id'=>$facts['operation_id'],'ability_name'=>$facts['ability_name'],
            'provider'=>$facts['provider'],'site_uuid'=>$facts['site_uuid'],
            'plan_sha256'=>$facts['plan_sha256'],'observed_files'=>$observed,
            'preimage_match'=>$cas,'change_required'=>$changed,
            'provider_mounted'=>$mounted,'authorization_input_unchanged'=>$unchanged,
            'expected_approval_target_fingerprint'=>$fingerprint,
            'git_commit_verified_by_wp'=>false,'native_manifest_verified_by_wp'=>false,
            'read_only'=>true,'approval_ticket_created'=>false,
            'mutation_performed'=>false,'authorization_created'=>false,
            'original313_receipt_signed'=>false,
        );
    }
}

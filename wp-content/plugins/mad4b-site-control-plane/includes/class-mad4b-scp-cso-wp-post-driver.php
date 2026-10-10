<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Exact native WordPress post-field provider for a deliberately enrolled
 * Staging canary. Supports post_title and post_excerpt ONLY. No post_meta,
 * post_content, status, publication, WPML, Rank Math or arbitrary plugin keys.
 * Execution is admitted solely by CSO Native Executor's approved-ticket path.
 */
final class MAD4B_SCP_CSO_WP_Post_Driver implements MAD4B_SCP_CSO_Storage_Provider {
    const PROVIDER = 'wp_post_core';
    const READ = 'mad4b-cso/wp-post-read';
    const WRITE = 'mad4b-cso/wp-post-write';
    private static $booted = false;

    public function provider_key() { return self::PROVIDER; }

    public static function boot() {
        if ( self::$booted ) return;
        self::$booted = true;
        if ( ! MAD4B_SCP_CSO_Scope::enabled( 'single_write' ) ||
            ! MAD4B_SCP_CSO_Scope::enabled( 'forms' ) ||
            ! defined( 'MAD4B_CSO_STORAGE_ADAPTER_CLASSES' ) ||
            ! is_array( MAD4B_CSO_STORAGE_ADAPTER_CLASSES ) ||
            ( MAD4B_CSO_STORAGE_ADAPTER_CLASSES[ self::PROVIDER ] ?? null ) !== __CLASS__ ||
            ! defined( 'MAD4B_CSO_WP_POST_WRITER_ENABLED' ) ||
            true !== MAD4B_CSO_WP_POST_WRITER_ENABLED )
            return;
        add_action( 'wp_abilities_api_init', array( __CLASS__, 'register' ), 43 );
    }

    private static function allowed( $target, $scope, $write ) {
        if ( ! is_array( $target ) || array_keys( $target ) !== array( 'post_id' ) ||
            ! is_int( $target['post_id'] ) || $target['post_id'] < 1 ||
            ! is_array( $scope ) ||
            ! isset( $scope['site_uuid'], $scope['brand_ref'], $scope['environment'] ) ||
            ! in_array( $scope['environment'], array( 'local','development','staging' ), true ) ||
            ! function_exists( 'get_post' ) || ! function_exists( 'get_post_meta' ) ||
            ! function_exists( 'current_user_can' ) )
            return false;
        $post = get_post( $target['post_id'] );
        if ( ! is_object( $post ) || in_array( $post->post_status,
            array( 'trash', 'inherit', 'auto-draft' ), true ) )
            return false;
        $post_types = defined( 'MAD4B_CSO_WP_POST_TYPES' ) &&
            is_array( MAD4B_CSO_WP_POST_TYPES ) ? MAD4B_CSO_WP_POST_TYPES : array( 'post','page' );
        if ( count( $post_types ) > 12 ||
            ! in_array( (string) $post->post_type, $post_types, true ) ||
            ! current_user_can( 'edit_post', $target['post_id'] ) )
            return false;
        if ( $write && ( ! class_exists( 'MAD4B_SCP_Policy', false ) ||
            ! MAD4B_SCP_Policy::can_mutate() ) ) return false;
        // No invisible owner inference for old posts. Require explicit canonical
        // site/brand tagging by a separately authorized migration/enrollment.
        $site = (string) get_post_meta( $target['post_id'], '_mad4b_cso_site_uuid', true );
        $brand = (string) get_post_meta( $target['post_id'], '_mad4b_cso_brand_id', true );
        return hash_equals( (string) $scope['site_uuid'], $site ) &&
            hash_equals( (string) $scope['brand_ref'], $brand );
    }

    private static function revision( $title, $excerpt, $modified, $status, $type ) {
        return hash( 'sha256', wp_json_encode( array(
            (string) $title, (string) $excerpt, (string) $modified,
            (string) $status, (string) $type ) ) );
    }

    public function describe( $target, $scope ) {
        if ( ! self::allowed( $target, $scope, false ) )
            return MAD4B_SCP_CSO_Scope::error( 'POST_PROVIDER_SCOPE_DENIED' );
        $snapshot = $this->read( $target, $scope );
        if ( is_wp_error( $snapshot ) ) return $snapshot;
        $fields = array(
            array( 'key'=>'title', 'type'=>'string', 'required'=>true,
                'max_length'=>200, 'min_length'=>1 ),
            array( 'key'=>'excerpt', 'type'=>'string', 'required'=>false,
                'max_length'=>500, 'min_length'=>0 ),
        );
        return array( 'provider_id'=>self::PROVIDER, 'target'=>$target,
            'fields'=>$fields, 'native_read_ability'=>self::READ,
            'native_write_ability'=>self::WRITE,
            'descriptor_sha256'=>hash( 'sha256', wp_json_encode(
                array( self::PROVIDER, $target, $fields, $scope['site_uuid'],
                    $scope['brand_ref'] ) ) ),
            'revision'=>$snapshot['revision'] );
    }

    public function read( $target, $scope ) {
        if ( ! self::allowed( $target, $scope, false ) )
            return MAD4B_SCP_CSO_Scope::error( 'POST_READ_SCOPE_DENIED' );
        $post = get_post( $target['post_id'] );
        if ( ! is_object( $post ) ) return MAD4B_SCP_CSO_Scope::error( 'POST_MISSING' );
        return array(
            'revision'=>self::revision( $post->post_title,
                $post->post_excerpt, $post->post_modified_gmt,
                $post->post_status, $post->post_type ),
            'values'=>array( 'title'=>(string) $post->post_title,
                'excerpt'=>(string) $post->post_excerpt ),
            'observed_at'=>time() );
    }

    public static function read_permission( $input ) {
        $scope = MAD4B_SCP_CSO_Scope::current();
        return ! is_wp_error( $scope ) &&
            self::allowed( $input, $scope, false );
    }

    public static function write_permission( $input ) {
        $scope = MAD4B_SCP_CSO_Scope::current();
        return ! is_wp_error( $scope ) &&
            MAD4B_SCP_CSO_Scope::enabled( 'single_write' ) &&
            MAD4B_SCP_CSO_Scope::first_party_session() &&
            is_array( $input ) && ( $input['provider_id'] ?? '' ) === self::PROVIDER &&
            self::allowed( $input['target'] ?? null, $scope, true );
    }

    public static function write_native( $input ) {
        global $wpdb;
        if ( ! self::write_permission( $input ) ||
            ! is_array( $input['values'] ?? null ) ||
            ! is_string( $input['expected_revision'] ?? null ) ||
            ! preg_match( '/^[a-f0-9]{64}$/D', $input['expected_revision'] ) ||
            ! isset( $input['scope_sha256'] ) || ! is_string( $input['scope_sha256'] ) )
            return MAD4B_SCP_CSO_Scope::error( 'POST_WRITE_INPUT_DENIED' );
        $scope = MAD4B_SCP_CSO_Scope::current();
        if ( is_wp_error( $scope ) ||
            ! hash_equals( MAD4B_SCP_CSO_Scope::digest( $scope ), $input['scope_sha256'] ) )
            return MAD4B_SCP_CSO_Scope::error( 'POST_WRITE_SCOPE_CHANGED' );
        $values = $input['values'];
        if ( ! $values || count( $values ) > 2 ||
            array_diff( array_keys( $values ), array( 'title','excerpt' ) ) ||
            ! MAD4B_SCP_CSO_Scope::safe_data( $values ) )
            return MAD4B_SCP_CSO_Scope::error( 'POST_WRITE_FIELD_DENIED' );
        foreach ( $values as $field => $value ) {
            if ( ! is_string( $value ) || strlen( $value ) > ( $field === 'title' ? 200 : 500 ) ||
                ( $field === 'title' && trim( $value ) === '' ) ||
                wp_strip_all_tags( $value ) !== $value )
                return MAD4B_SCP_CSO_Scope::error( 'POST_WRITE_VALUE_UNSAFE' );
        }
        $id = $input['target']['post_id'];
        if ( ! is_object( $wpdb ) || ! isset( $wpdb->posts, $wpdb->postmeta ) ||
            ! method_exists( $wpdb, 'query' ) ||
            ! class_exists( 'MAD4B_SCP_Database_Topology', false ) )
            return MAD4B_SCP_CSO_Scope::error( 'POST_TRANSACTION_UNAVAILABLE' );
        $topology = MAD4B_SCP_Database_Topology::assert_write_ready( true );
        if ( is_wp_error( $topology ) ) return $topology;
        $started = $wpdb->query( 'START TRANSACTION' );
        if ( false === $started ) return MAD4B_SCP_CSO_Scope::error( 'POST_TRANSACTION_START_FAILED' );
        $effect_attempted = false;
        try {
            $row = $wpdb->get_row( $wpdb->prepare(
                "SELECT ID,post_title,post_excerpt,post_modified_gmt,post_status,post_type FROM {$wpdb->posts} WHERE ID = %d FOR UPDATE",
                $id ), ARRAY_A );
            $owned = $wpdb->get_results( $wpdb->prepare(
                "SELECT meta_key,meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key IN (%s,%s) FOR UPDATE",
                $id, '_mad4b_cso_site_uuid', '_mad4b_cso_brand_id' ), ARRAY_A );
            $owners = array();
            if ( is_array( $owned ) ) foreach ( $owned as $entry ) {
                if ( isset( $owners[ $entry['meta_key'] ] ) ) throw new RuntimeException( 'duplicate owner claim' );
                $owners[ $entry['meta_key'] ] = $entry['meta_value'];
            }
            if ( ! is_array( $row ) ||
                ( $owners['_mad4b_cso_site_uuid'] ?? '' ) !== $scope['site_uuid'] ||
                ( $owners['_mad4b_cso_brand_id'] ?? '' ) !== $scope['brand_ref'] ||
                ! self::allowed( $input['target'], $scope, true ) ||
                ! hash_equals( $input['expected_revision'], self::revision(
                    $row['post_title'], $row['post_excerpt'], $row['post_modified_gmt'],
                    $row['post_status'], $row['post_type'] ) ) ||
                true !== MAD4B_SCP_CSO_Scope::assert_current( $scope ) )
                throw new RuntimeException( 'stale post row or owner' );
            $args = array( 'ID'=>$id );
            if ( array_key_exists( 'title', $values ) ) $args['post_title'] = $values['title'];
            if ( array_key_exists( 'excerpt', $values ) ) $args['post_excerpt'] = $values['excerpt'];
            $effect_attempted = true;
            $updated = wp_update_post( wp_slash( $args ), true );
            if ( is_wp_error( $updated ) || (int) $updated !== (int) $id )
                throw new RuntimeException( 'post update uncertain' );
            if ( true !== MAD4B_SCP_CSO_Scope::assert_current( $scope ) )
                throw new RuntimeException( 'post scope changed after effect' );
            if ( false === $wpdb->query( 'COMMIT' ) )
                throw new RuntimeException( 'commit uncertain' );
            clean_post_cache( $id );
            return array( 'status'=>'written', 'native_provider'=>self::PROVIDER,
                'post_id'=>$id, 'revision_check_at_effect'=>true,
                'database_commit_acknowledged'=>true );
        } catch ( \Throwable $e ) {
            // A hook can have produced external effects even on SQL ROLLBACK.
            // Return an error; caller MUST preserve needs_reconcile.
            $wpdb->query( 'ROLLBACK' );
            return MAD4B_SCP_CSO_Scope::error(
                $effect_attempted ? 'POST_WRITE_EFFECT_UNCERTAIN' : 'POST_PRE_EFFECT_CONFLICT' );
        }
    }

    public static function register() {
        if ( ! function_exists( 'wp_register_ability' ) ||
            ! function_exists( 'wp_has_ability' ) ||
            wp_has_ability( self::READ ) || wp_has_ability( self::WRITE ) )
            return;
        $read = wp_register_ability( self::READ, array(
            'label'=>'CSO Scoped Post Read',
            'description'=>'Read exactly one enrolled WordPress post title and excerpt.',
            'category'=>'mad4b-read',
            'input_schema'=>array('type'=>'object','properties'=>array(
                'post_id'=>array('type'=>'integer','minimum'=>1)),
                'required'=>array('post_id'),'additionalProperties'=>false),
            'output_schema'=>array('type'=>'object'),
            'permission_callback'=>array(__CLASS__,'read_permission'),
            'execute_callback'=>static function($input) {
                $scope=MAD4B_SCP_CSO_Scope::current();
                return is_wp_error($scope) ? $scope :
                    (new MAD4B_SCP_CSO_WP_Post_Driver())->read($input,$scope);
            },
            'meta'=>array('public'=>false,
                'annotations'=>array('readonly'=>true,'destructive'=>false),
                'mcp'=>array('public'=>false,'type'=>'tool','surface'=>'read')) ));
        if ( is_wp_error( $read ) || ! wp_has_ability( self::READ ) ) return;
        // Original MAD4B authorization/execution-fence wraps this Ability.
        // Registration alone does not grant invocation.
        wp_register_ability( self::WRITE, array(
            'label'=>'CSO Governed Post Fields Write',
            'description'=>'Exact approved bounded title/excerpt change for an enrolled post.',
            'category'=>'mad4b-write',
            'input_schema'=>array('type'=>'object','properties'=>array(
                'contract'=>array('type'=>'string'),
                'provider_id'=>array('type'=>'string'),
                'target'=>array('type'=>'object'),
                'expected_revision'=>array('type'=>'string'),
                'descriptor_sha256'=>array('type'=>'string'),
                'values'=>array('type'=>'object'),
                'plan_sha256'=>array('type'=>'string'),
                'scope_sha256'=>array('type'=>'string')),
                'additionalProperties'=>false),
            'output_schema'=>array('type'=>'object'),
            'permission_callback'=>array(__CLASS__,'write_permission'),
            'execute_callback'=>array(__CLASS__,'write_native'),
            'meta'=>array('public'=>false,
                'annotations'=>array('readonly'=>false,'destructive'=>true,'idempotent'=>false),
                'mcp'=>array('public'=>false,'type'=>'tool','surface'=>'write',
                    'governed_write'=>true,'mad4b_cso_certified_write'=>true)) ));
    }
}

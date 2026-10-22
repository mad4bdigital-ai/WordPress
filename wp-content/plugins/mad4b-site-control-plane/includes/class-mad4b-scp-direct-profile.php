<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Isolated, authenticated WordPress content MCP surface. Never changes the
 * existing governed approval/claim/breakglass contracts.
 */
final class MAD4B_SCP_Direct_Profile {
    const CONTRACT = 'mad4b.direct-authenticated.v1';
    const OPTION = 'mad4b_scp_direct_access_profile_v1';
    const PAGE = 'mad4b-direct-access-profile';
    const SAVE_ACTION = 'mad4b_direct_access_profile_save';
    // Same existing endpoint; NO new MCP server registration.
    const SERVER = 'mad4b-chatgpt';
    const STATUS = 'mad4b/direct-connection-status';
    const LIST_POSTS = 'mad4b/direct-list-posts';
    const GET_POST = 'mad4b/direct-get-post';
    const CREATE_POST = 'mad4b/direct-create-post';
    const UPDATE_POST = 'mad4b/direct-update-post';
    private static $booted = false;

    public static function boot() {
        if ( self::$booted ) return;
        self::$booted = true;
        add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ), 32 );
        // Do not bypass the parent-menu ordering registry: on a partial
        // bootstrap the controls stay unavailable rather than misregistering.
        if ( class_exists( 'MAD4B_SCP_Admin_Route_Registry' ) )
            MAD4B_SCP_Admin_Route_Registry::schedule_submenu( array( __CLASS__, 'register_page' ), 26 );
        add_action( 'admin_post_' . self::SAVE_ACTION, array( __CLASS__, 'save' ) );
    }

    public static function default_mode() {
        // Upgrading an already enrolled site must NOT silently escalate it.
        return class_exists( 'MAD4B_SCP_Site_Profile' )
            && MAD4B_SCP_Site_Profile::configured()
            ? 'governed' : 'direct_authenticated';
    }

    public static function state() {
        $raw = get_option( self::OPTION, null );
        $configured = is_array( $raw );
        $valid = $configured && isset( $raw['contract'], $raw['mode'], $raw['revision'] )
            && self::CONTRACT === $raw['contract']
            && in_array( $raw['mode'], array( 'direct_authenticated', 'governed', 'read_only', 'maintenance_lock' ), true )
            && is_int( $raw['revision'] ) && $raw['revision'] >= 1;
        if ( $configured && ! $valid ) return array(
            'contract' => self::CONTRACT, 'valid' => false, 'mode' => 'maintenance_lock',
            'source' => 'invalid_persisted_option', 'revision' => 0,
        );
        return array( 'contract' => self::CONTRACT, 'valid' => true,
            'mode' => $valid ? $raw['mode'] : self::default_mode(),
            'source' => $valid ? 'saved_admin_preference' : 'fresh_or_upgrade_default',
            'revision' => $valid ? $raw['revision'] : 0,
        );
    }

    public static function can_user() {
        $user_id = get_current_user_id();
        if ( $user_id < 1 || ! class_exists( 'MAD4B_SCP_Policy' )
            || ! MAD4B_SCP_Policy::can_connect_user( $user_id )
            || ! current_user_can( 'manage_options' ) ) return false;
        // The existing OAuth resource bridge verifies remote tokens at the
        // original ChatGPT transport. Reject a forged WP current-user context
        // when no authenticated bearer/cookie authentication exists.
        // MCP tools may not infer authorization from a server-side WP user
        // that some other plugin populated. The bearer must be verified on
        // this exact request by the existing OAuth resource bridge.
        return class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge' )
            && MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_active();
    }

    public static function can_transport() {
        $state = self::state();
        if ( empty( $state['valid'] ) || 'governed' === $state['mode'] ) return false;
        return self::can_user();
    }

    private static function permitted( $write = false ) {
        if ( ! self::can_transport() ) return new WP_Error( 'mad4b_direct_connection_denied',
            'Authenticated and enrolled WordPress administrator required.', array( 'status'=>403 ) );
        if ( $write && 'direct_authenticated' !== self::state()['mode'] )
            return new WP_Error( 'mad4b_direct_read_only', 'Direct content writes are disabled by the current profile.', array( 'status'=>403 ) );
        // A read-only OAuth bearer must never be promoted to a write bearer merely
        // by flipping the local site profile. No per-operation MAD4B ticket is
        // needed, but write-capable OAuth scope must be held by this subject.
        if ( $write && class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge' )
            && MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_active()
            && ! MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_has_scope( 'mad4b:authority:step-up' ) )
            return new WP_Error( 'mad4b_direct_write_scope_required',
                'The connected OAuth session has no direct-write authority scope.', array( 'status'=>403 ) );
        if ( ! current_user_can( 'edit_posts' ) )
            return new WP_Error( 'mad4b_direct_content_capability', 'The WordPress user cannot edit posts.', array( 'status'=>403 ) );
        return true;
    }

    public static function tools() {
        return array( self::STATUS, self::LIST_POSTS, self::GET_POST, self::CREATE_POST, self::UPDATE_POST );
    }

    /** Stable tool inventory in Direct and Governed makes A/B MCP testing valid.
     * Profile access decisions are request-time checks, not registration-time
     * bearer or authority decisions. An invalid option is fail-closed.
     */
    public static function catalog_tools() {
        return ! empty( self::state()['valid'] ) ? self::tools() : array();
    }

    public static function read_tools() {
        return array( self::STATUS, self::LIST_POSTS, self::GET_POST );
    }
    public static function write_tools() {
        return array( self::CREATE_POST, self::UPDATE_POST );
    }

    private static function schema( array $properties = array(), array $required = array() ) {
        $s = array( 'type'=>'object', 'properties'=>$properties, 'additionalProperties'=>false );
        if ( $required ) $s['required'] = $required;
        return $s;
    }

    public static function register_abilities() {
        if ( ! function_exists( 'wp_register_ability' ) ) return;
        $str = array( 'type'=>'string', 'maxLength'=>65535 );
        $title = array( 'type'=>'string', 'minLength'=>1, 'maxLength'=>300 );
        $id = array( 'type'=>'integer', 'minimum'=>1 );
        $gmt = array( 'type'=>'string', 'minLength'=>1, 'maxLength'=>32 );
        $defs = array(
            self::STATUS => array( 'Direct Connection Status', 'status', self::schema(), true ),
            self::LIST_POSTS => array( 'List WordPress Posts', 'list_posts', self::schema( array(
                'limit'=>array( 'type'=>'integer', 'minimum'=>1, 'maximum'=>20 ),
            ) ), true ),
            self::GET_POST => array( 'Get WordPress Post', 'get_post', self::schema( array( 'post_id'=>$id ), array( 'post_id' ) ), true ),
            self::CREATE_POST => array( 'Create WordPress Post', 'create_post', self::schema( array(
                'title'=>$title, 'content'=>$str,
                'status'=>array( 'type'=>'string', 'enum'=>array( 'draft', 'pending', 'publish' ) ),
            ), array( 'title' ) ), false ),
            self::UPDATE_POST => array( 'Update WordPress Post', 'update_post', self::schema( array(
                'post_id'=>$id, 'title'=>$title, 'content'=>$str,
                'expected_modified_gmt'=>$gmt,
            ), array( 'post_id', 'expected_modified_gmt' ) ), false ),
        );
        foreach ( $defs as $name=>$def ) {
            if ( function_exists( 'wp_has_ability' ) && wp_has_ability( $name ) ) continue;
            $method = $def[1];
            wp_register_ability( $name, array(
                'label'=>$def[0],
                'description'=>'WordPress user-scoped direct MCP capability; never anonymous or unrestricted.',
                'category'=>$def[3] ? 'mad4b-read' : 'mad4b-content',
                'execute_callback'=>array( __CLASS__, $method ),
                'permission_callback'=>static function ( $input = null ) use ( $def, $method ) {
                    $base = 'status' === $method ? self::can_user()
                        : self::permitted( ! $def[3] );
                    if ( is_wp_error( $base ) || ! $base ) return $base;
                    if ( 'get_post' === $method || 'update_post' === $method ) {
                        $post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
                        $post = $post_id ? get_post( $post_id ) : null;
                        if ( ! $post || 'post' !== $post->post_type ) return false;
                        return current_user_can( 'edit_post', $post_id );
                    }
                    return true;
                },
                'input_schema'=>$def[2],
                'output_schema'=>array( 'type'=>'object', 'additionalProperties'=>true ),
                'meta'=>array( 'public'=>false, 'show_in_rest'=>false,
                    'mcp'=>array( 'public'=>false, 'type'=>'tool', 'surface'=>'direct' ),
                    'annotations'=>array( 'readonly'=>$def[3], 'destructive'=>! $def[3],
                        'idempotent'=>$def[3] ) ),
            ) );
        }
    }

    public static function status( $input = array() ) {
        return array( 'contract'=>self::CONTRACT, 'profile'=>self::state(),
            'authenticated'=>self::can_user(), 'user_id'=>get_current_user_id(),
            'ability_count'=>count( self::tools() ), 'governed_runtime_unchanged'=>true,
            'extra_mad4b_per_mutation_approval'=>false,
            'server_id'=>self::SERVER, 'server_count_changed'=>false );
    }

    public static function list_posts( $input = array() ) {
        $allow = self::permitted(); if ( true !== $allow ) return $allow;
        $limit = isset( $input['limit'] ) ? max( 1, min( 20, absint( $input['limit'] ) ) ) : 10;
        $posts = get_posts( array( 'post_type'=>'post', 'post_status'=>'any',
            'posts_per_page'=>$limit, 'orderby'=>'modified', 'order'=>'DESC' ) );
        $allowed = array();
        foreach ( $posts as $post ) {
            if ( is_object( $post ) && 'post' === $post->post_type
                && current_user_can( 'edit_post', (int) $post->ID ) )
                $allowed[] = self::serialize_post( $post );
        }
        return array( 'items'=>$allowed );
    }

    public static function get_post( $input = array() ) {
        $allow = self::permitted(); if ( true !== $allow ) return $allow;
        $id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
        $post = $id ? get_post( $id ) : null;
        if ( ! $post || 'post' !== $post->post_type
            || ! current_user_can( 'edit_post', $id ) )
            return new WP_Error( 'mad4b_direct_post_denied', 'Post unavailable to this WordPress user.', array( 'status'=>403 ) );
        return self::serialize_post( $post );
    }

    private static function serialize_post( $post ) {
        return array( 'id'=>(int)$post->ID, 'title'=>(string)$post->post_title,
            'content'=>(string)$post->post_content, 'status'=>(string)$post->post_status,
            'modified_gmt'=>(string)$post->post_modified_gmt );
    }

    public static function create_post( $input = array() ) {
        $allow = self::permitted( true ); if ( true !== $allow ) return $allow;
        $title = isset( $input['title'] ) && is_string( $input['title'] )
            ? trim( $input['title'] ) : '';
        if ( '' === $title || strlen( $title ) > 1200 )
            return new WP_Error( 'mad4b_direct_title_required', 'Valid post title required.' );
        $status = isset( $input['status'] ) ? $input['status'] : 'draft';
        if ( ! in_array( $status, array( 'draft', 'pending', 'publish' ), true ) )
            return new WP_Error( 'mad4b_direct_status_invalid', 'Unsupported post status.' );
        if ( ! current_user_can( 'create_posts' ) && ! current_user_can( 'edit_posts' ) )
            return new WP_Error( 'mad4b_direct_create_denied', 'WordPress create-post capability required.' );
        if ( 'publish' === $status && ! current_user_can( 'publish_posts' ) )
            return new WP_Error( 'mad4b_direct_publish_denied', 'WordPress publish-post capability required.' );
        $post_id = wp_insert_post( array( 'post_type'=>'post',
            'post_title'=>sanitize_text_field( $title ),
            'post_content'=>isset( $input['content'] ) && is_string( $input['content'] ) ? wp_kses_post( $input['content'] ) : '',
            'post_status'=>$status, 'post_author'=>get_current_user_id(),
        ), true );
        if ( is_wp_error( $post_id ) ) return $post_id;
        return self::get_post( array( 'post_id'=>$post_id ) );
    }

    public static function update_post( $input = array() ) {
        $allow = self::permitted( true ); if ( true !== $allow ) return $allow;
        $id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
        $post = $id ? get_post( $id ) : null;
        if ( ! $post || 'post' !== $post->post_type || ! current_user_can( 'edit_post', $id ) )
            return new WP_Error( 'mad4b_direct_update_denied', 'Cannot edit this WordPress post.', array( 'status'=>403 ) );
        $expected = isset( $input['expected_modified_gmt'] ) && is_string( $input['expected_modified_gmt'] )
            ? $input['expected_modified_gmt'] : '';
        if ( '' === $expected || ! hash_equals( (string)$post->post_modified_gmt, $expected ) )
            return new WP_Error( 'mad4b_direct_update_stale', 'Post changed; read it again before retry.', array( 'status'=>409 ) );
        $changes = array( 'ID'=>$id );
        if ( isset( $input['title'] ) ) {
            if ( ! is_string( $input['title'] ) || '' === trim( $input['title'] ) )
                return new WP_Error( 'mad4b_direct_title_invalid', 'Invalid title.' );
            $changes['post_title'] = sanitize_text_field( $input['title'] );
        }
        if ( isset( $input['content'] ) ) {
            if ( ! is_string( $input['content'] ) ) return new WP_Error( 'mad4b_direct_content_invalid', 'Content must be text.' );
            $changes['post_content'] = wp_kses_post( $input['content'] );
        }
        if ( count( $changes ) < 2 )
            return new WP_Error( 'mad4b_direct_no_change', 'Provide a title or content field.' );
        $result = wp_update_post( $changes, true );
        if ( is_wp_error( $result ) ) return $result;
        return self::get_post( array( 'post_id'=>$id ) );
    }

    public static function register_page() {
        add_submenu_page( 'mad4b-control-plane', 'MCP Access Profile',
            'MCP Access Profile', 'manage_options', self::PAGE, array( __CLASS__, 'render_page' ) );
    }

    public static function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Administrator required.' );
        $state = self::state();
        echo '<div class="wrap"><h1>MCP Access Profile</h1>';
        echo '<p>Direct allows ordinary authenticated WordPress post reads/writes without MAD4B approval tickets. OAuth and WordPress user permissions remain required.</p>';
        echo '<p><strong>Current mode:</strong> ' . esc_html( $state['mode'] )
            . ' | <strong>Source:</strong> ' . esc_html( $state['source'] )
            . ' | <strong>Revision:</strong> ' . esc_html( (string)$state['revision'] ) . '</p>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        wp_nonce_field( self::SAVE_ACTION );
        echo '<input type="hidden" name="action" value="' . esc_attr( self::SAVE_ACTION ) . '">';
        echo '<input type="hidden" name="expected_revision" value="' . esc_attr( (string)$state['revision'] ) . '">';
        echo '<select name="profile">';
        foreach ( array( 'direct_authenticated'=>'Direct Authenticated', 'governed'=>'Governed',
            'read_only'=>'Read Only', 'maintenance_lock'=>'Maintenance Lock' ) as $mode=>$name ) {
            echo '<option value="' . esc_attr($mode) . '"' . selected( $state['mode'], $mode, false ) . '>' . esc_html($name) . '</option>';
        }
        echo '</select>';
        submit_button( 'Save MCP Profile' );
        echo '</form><p>Existing ChatGPT connector URL (no new connector): <code>' . esc_html( rest_url( 'mcp/' . self::SERVER ) ) . '</code></p></div>';
    }

    public static function save() {
        if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' )
            || ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized request.', '', array('response'=>403) );
        check_admin_referer( self::SAVE_ACTION );
        $mode = isset( $_POST['profile'] ) && is_string( $_POST['profile'] )
            ? sanitize_key( wp_unslash( $_POST['profile'] ) ) : '';
        $revision = isset( $_POST['expected_revision'] ) ? absint( $_POST['expected_revision'] ) : -1;
        if ( ! in_array( $mode, array( 'direct_authenticated','governed','read_only','maintenance_lock' ), true ) )
            wp_die( 'Invalid mode.', '', array('response'=>400) );
        $state = self::state();
        if ( ! $state['valid'] || $revision !== $state['revision'] )
            wp_die( 'Profile changed. Reload and review before saving.', '', array('response'=>409) );
        $before = get_option( self::OPTION, null );
        $new = array( 'contract'=>self::CONTRACT, 'mode'=>$mode, 'revision'=>$revision+1 );
        if ( ! update_option( self::OPTION, $new, false ) && get_option( self::OPTION, null ) !== $new )
            wp_die( 'Profile persistence failed.', '', array('response'=>503) );
        if ( get_option( self::OPTION, null ) !== $new ) {
            if ( null !== $before ) update_option( self::OPTION, $before, false );
            else delete_option( self::OPTION );
            wp_die( 'Profile readback failed; previous option restored.', '', array('response'=>503) );
        }
        wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE . '&saved=1' ) );
        exit;
    }
}

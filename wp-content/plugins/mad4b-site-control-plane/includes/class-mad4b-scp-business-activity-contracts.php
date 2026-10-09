<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Optional, tenant-local identity/profile facet of a Content Experience Profile.
 *
 * No business vertical, CPT, role or meta key is a built-in default. This
 * contract is inert until a site administrator saves an exact Experience
 * Profile plan/apply with an explicit activity_contract definition.
 */
final class MAD4B_SCP_Business_Activity_Contracts {
    const CONTRACT = 'mad4b.business-activity-contract.v1';
    const MAX_FIELDS = 40;

    private static function err( $code, $message ) { return new WP_Error( $code, $message ); }
    private static function digest( $value ) {
        $encoded = wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        return is_string( $encoded ) ? hash( 'sha256', $encoded ) : '';
    }
    private static function meta_key( $key ) {
        return is_string( $key ) && 1 === preg_match( '/^_[a-zA-Z][a-zA-Z0-9_-]{2,120}$/', $key )
            && ( ! class_exists( 'MAD4B_SCP_Policy' ) || ! MAD4B_SCP_Policy::is_sensitive_database_column( $key ) );
    }
    private static function safe_role( $role ) {
        if ( ! is_string( $role ) || ! preg_match( '/^[a-z][a-z0-9_-]{1,48}$/', $role ) ) return false;
        if ( ! function_exists( 'get_role' ) ) return false;
        $object = get_role( $role );
        if ( ! is_object( $object ) || ! isset( $object->capabilities ) || ! is_array( $object->capabilities ) ) return false;
        foreach ( array( 'manage_options', 'edit_users', 'create_users', 'promote_users',
            'activate_plugins', 'install_plugins', 'edit_plugins', 'delete_users',
            'manage_network', 'publish_posts', 'edit_others_posts' ) as $cap )
            if ( ! empty( $object->capabilities[ $cap ] ) ) return false;
        return true;
    }

    public static function normalize( $raw, $post_type, $meta_keys, $configured_taxonomies ) {
        if ( null === $raw || array() === $raw ) return array( 'enabled' => false );
        if ( ! is_array( $raw ) || array_diff( array_keys( $raw ), array(
            'enabled', 'user_role', 'post_user_meta_key', 'user_post_meta_key',
            'attribute_meta_keys', 'taxonomy_slugs', 'create_user', 'create_profile', 'sync_targets',
            'field_owners', 'sync_identity_key',
        ) ) ) return self::err( 'mad4b_activity_contract_unknown_field', 'Activity facet has unrecognized fields.' );
        if ( empty( $raw['enabled'] ) ) return array( 'enabled' => false );
        $role = isset( $raw['user_role'] ) ? sanitize_key( $raw['user_role'] ) : '';
        $post_key = isset( $raw['post_user_meta_key'] ) ? $raw['post_user_meta_key'] : '';
        $user_key = isset( $raw['user_post_meta_key'] ) ? $raw['user_post_meta_key'] : '';
        if ( ! self::safe_role( $role ) || ! self::meta_key( $post_key ) || ! self::meta_key( $user_key ) ||
            $post_key === $user_key )
            return self::err( 'mad4b_activity_contract_identity_invalid', 'Registered nonprivileged user role and independent safe link keys are required.' );
        $fields = isset( $raw['attribute_meta_keys'] ) ? $raw['attribute_meta_keys'] : array();
        if ( ! is_array( $fields ) || count( $fields ) > self::MAX_FIELDS ) return self::err( 'mad4b_activity_attribute_count', 'Attribute mapping exceeds the permitted size.' );
        $allow = array();
        foreach ( $fields as $field ) {
            if ( ! is_string( $field ) || ! preg_match( '/^[a-zA-Z][a-zA-Z0-9_-]{0,120}$/', $field ) ||
                ! in_array( $field, $meta_keys, true ) || $field === $post_key || $field === $user_key )
                return self::err( 'mad4b_activity_attribute_unmapped', 'Attribute must be allowed by the parent Content Experience Profile.' );
            $allow[$field] = true;
        }
        $taxonomies = isset( $raw['taxonomy_slugs'] ) ? $raw['taxonomy_slugs'] : array();
        if ( ! is_array( $taxonomies ) || count( $taxonomies ) > 20 ) return self::err( 'mad4b_activity_taxonomy_count', 'Too many taxonomy classifications.' );
        $attached = get_object_taxonomies( $post_type );
        foreach ( $taxonomies as $taxonomy ) if ( ! is_string( $taxonomy ) || ! in_array( $taxonomy, $attached, true ) || ! in_array( $taxonomy, $configured_taxonomies, true ) )
            return self::err( 'mad4b_activity_taxonomy_unavailable', 'Configured classification taxonomy is not attached to the profile CPT.' );
        $targets = isset( $raw['sync_targets'] ) ? $raw['sync_targets'] : array();
        if ( ! is_array( $targets ) || count( $targets ) > 12 )
            return self::err( 'mad4b_activity_sync_target_invalid', 'Business Activity sync target count is invalid.' );
        $sync = array(); $resource_bindings = array();
        foreach ( $targets as $target_id => $target ) {
            if ( ! is_string( $target_id ) || 'wordpress' === $target_id || ! preg_match( '/^[a-z][a-z0-9_-]{1,48}$/', $target_id ) ||
                ! is_array( $target ) || array_diff( array_keys( $target ), array( 'provider', 'direction', 'field_keys', 'source_ref', 'conflict_policy', 'resource_kind', 'purpose' ) ) )
                return self::err( 'mad4b_activity_sync_target_fields', 'Sync target must have bounded fields and ID.' );
            $provider = isset( $target['provider'] ) ? $target['provider'] : '';
            $direction = isset( $target['direction'] ) ? $target['direction'] : '';
            if ( ! in_array( $provider, array( 'wordpress', 'google_drive' ), true ) ||
                ! in_array( $direction, array( 'import', 'export', 'bidirectional' ), true ) )
                return self::err( 'mad4b_activity_sync_provider_invalid', 'Provider and direction are unsupported.' );
            $field_keys = isset( $target['field_keys'] ) ? $target['field_keys'] : array();
            if ( ! is_array( $field_keys ) || count( $field_keys ) > self::MAX_FIELDS ||
                array_diff( $field_keys, array_keys( $allow ) ) )
                return self::err( 'mad4b_activity_sync_fields_invalid', 'Sync fields must be permitted by the parent Content Experience Profile.' );
            $source_ref = isset( $target['source_ref'] ) ? $target['source_ref'] : '';
            if ( ! is_string( $source_ref ) || strlen( $source_ref ) > 180 ||
                ( '' !== $source_ref && ! preg_match( '/^[A-Za-z0-9._:-]+$/', $source_ref ) ) )
                return self::err( 'mad4b_activity_sync_source_invalid', 'Sync source uses only a bounded provider resource identifier, never credentials or arbitrary URLs.' );
            $kind = isset( $target['resource_kind'] ) ? (string) $target['resource_kind']
                : ( 'wordpress' === $provider ? 'wordpress_post' : 'drive_document' );
            if ( ! in_array( $kind, 'wordpress' === $provider ? array( 'wordpress_post' ) :
                array( 'drive_document', 'drive_sheet', 'drive_file', 'drive_folder' ), true ) )
                return self::err( 'mad4b_activity_sync_resource_kind_invalid', 'Configured resource type does not match the source provider.' );
            $purpose = isset( $target['purpose'] ) ? (string) $target['purpose'] : 'record_data';
            if ( ! in_array( $purpose, array( 'record_data', 'editorial_policy', 'reference',
                'media_assets' ), true ) )
                return self::err( 'mad4b_activity_sync_purpose_invalid', 'Source purpose must be an allowed data or context role.' );
            if ( 'record_data' !== $purpose && $field_keys )
                return self::err( 'mad4b_activity_policy_source_field_denied', 'Guidelines/reference/media sources are context, not direct canonical business fields.' );
            if ( 'google_drive' === $provider && '' === $source_ref )
                return self::err( 'mad4b_activity_drive_binding_required', 'Google Drive source must bind to an exact provider resource reference.' );
            if ( 'record_data' === $purpose && 'drive_folder' === $kind )
                return self::err( 'mad4b_activity_folder_not_row_source', 'Drive folder is a container; select exact child file/sheet IDs as record sources.' );
            if ( '' !== $source_ref ) {
                $resource_key = $provider . ':' . $source_ref;
                if ( isset( $resource_bindings[ $resource_key ] ) )
                    return self::err( 'mad4b_activity_duplicate_resource_binding', 'Duplicate provider resource alias in the same profile.' );
                $resource_bindings[ $resource_key ] = $target_id;
            }
            $policy = isset( $target['conflict_policy'] ) ? $target['conflict_policy'] : 'manual_review';
            if ( ! in_array( $policy, array( 'manual_review', 'source_wins', 'site_wins' ), true ) )
                return self::err( 'mad4b_activity_sync_conflict_policy', 'Sync conflict policy is not supported.' );
            $sync[ $target_id ] = array( 'provider' => $provider, 'direction' => $direction,
                'field_keys' => array_values( array_unique( $field_keys ) ), 'source_ref' => $source_ref,
                'conflict_policy' => $policy, 'resource_kind' => $kind, 'purpose' => $purpose );
        }
        // Exactly one field owner per canonical field. An owner's ability
        // to author a field must be declared explicitly and independently
        // of "last updated" or source precedence.
        $owners_raw = isset( $raw['field_owners'] ) ? $raw['field_owners'] : array();
        if ( ! is_array( $owners_raw ) || count( $owners_raw ) > self::MAX_FIELDS ||
            array_diff( array_keys( $owners_raw ), array_keys( $allow ) ) )
            return self::err( 'mad4b_activity_field_owner_keys_invalid', 'Field owners must reference the activity attribute allowlist.' );
        $owners = array();
        foreach ( array_keys( $allow ) as $field ) {
            $owner = isset( $owners_raw[ $field ] ) ? (string) $owners_raw[ $field ] : 'manual_review';
            if ( 'wordpress' !== $owner && 'manual_review' !== $owner &&
                ( ! isset( $sync[ $owner ] ) || ! in_array( $field, $sync[ $owner ]['field_keys'], true ) ) )
                return self::err( 'mad4b_activity_field_owner_invalid', 'Field owner must be WordPress, manual review or configured source containing the exact field.' );
            if ( isset( $sync[ $owner ] ) && ( 'export' === $sync[ $owner ]['direction'] ||
                'record_data' !== $sync[ $owner ]['purpose'] ) )
                return self::err( 'mad4b_activity_field_owner_direction_invalid', 'Outbound-only target cannot be canonical field owner.' );
            $owners[ $field ] = $owner;
        }
        $identity_key = isset( $raw['sync_identity_key'] ) ? (string) $raw['sync_identity_key'] : 'post_id';
        if ( ! in_array( $identity_key, array( 'post_id', 'external_stable_id' ), true ) )
            return self::err( 'mad4b_activity_sync_identity_invalid', 'Sync entity identity must be stable, never based on title or display name.' );
        return array( 'enabled' => true, 'user_role' => $role,
            'post_user_meta_key' => $post_key, 'user_post_meta_key' => $user_key,
            'attribute_meta_keys' => array_keys( $allow ),
            'taxonomy_slugs' => array_values( array_unique( $taxonomies ) ),
            'create_user' => ! empty( $raw['create_user'] ),
            'create_profile' => ! empty( $raw['create_profile'] ),
            'sync_targets' => $sync,
            'field_owners' => $owners,
            'sync_identity_key' => $identity_key,
        );
    }

    private static function binding( $input ) {
        $slug = isset( $input['profile_slug'] ) ? $input['profile_slug'] : '';
        $profile = MAD4B_SCP_Content_Experience_Profiles::profile( $slug );
        if ( is_wp_error( $profile ) ) return $profile;
        if ( empty( $profile['enabled'] ) || empty( $profile['activity_contract']['enabled'] ) )
            return self::err( 'mad4b_activity_facet_not_configured', 'This site profile does not enable a user-linked activity facet.' );
        if ( ! class_exists( 'MAD4B_SCP_Site_Profile' ) ||
            ! MAD4B_SCP_Site_Profile::configured() ||
            ! MAD4B_SCP_Site_Profile::origin_enrolled() ||
            ! MAD4B_SCP_Site_Profile::site_urls_match_enrollment() )
            return self::err( 'mad4b_activity_site_identity_missing', 'An exact enrolled WordPress site is required.' );
        $type = $profile['post_type'];
        $post_type = MAD4B_SCP_Content_Experience_Profiles::post_type_object( $type );
        if ( is_wp_error( $post_type ) ) return $post_type;
        return array( 'profile' => $profile, 'contract' => $profile['activity_contract'], 'type' => $type,
            'post_type' => $post_type, 'site_uuid' => (string) MAD4B_SCP_Site_Profile::site_uuid() );
    }
    public static function status( $input = array() ) {
        $binding = self::binding( is_array( $input ) ? $input : array() );
        if ( is_wp_error( $binding ) ) return $binding;
        return array( 'contract' => self::CONTRACT, 'profile_slug' => $binding['profile']['slug'],
            'profile_revision' => (int) $binding['profile']['revision'],
            'post_type' => $binding['type'],
            'configured_role' => $binding['contract']['user_role'],
            'allowed_attribute_meta_keys' => $binding['contract']['attribute_meta_keys'],
            'attached_taxonomies' => $binding['contract']['taxonomy_slugs'],
            'configured_sync_targets' => isset( $binding['contract']['sync_targets'] ) ? $binding['contract']['sync_targets'] : array(),
            'field_owners' => isset( $binding['contract']['field_owners'] ) ? $binding['contract']['field_owners'] : array(),
            'sync_identity_key' => isset( $binding['contract']['sync_identity_key'] ) ? $binding['contract']['sync_identity_key'] : 'post_id',
            'site_uuid' => $binding['site_uuid'],
            'read_only' => true, 'mutation_performed' => false );
    }
    public static function plan( $input = array() ) {
        $input = is_array( $input ) ? $input : array();
        if ( ! current_user_can( 'list_users' ) ) return self::err( 'mad4b_activity_user_read_denied', 'User listing capability required.' );
        $binding = self::binding( $input );
        if ( is_wp_error( $binding ) ) return $binding;
        $cfg = $binding['contract'];
        $post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
        $user_id = isset( $input['user_id'] ) ? absint( $input['user_id'] ) : 0;
        if ( $post_id && ( get_post_type( $post_id ) !== $binding['type'] || ! current_user_can( 'edit_post', $post_id ) ) )
            return self::err( 'mad4b_activity_profile_post_denied', 'Post is not in this activity profile or cannot be edited.' );
        if ( !$post_id && empty( $cfg['create_profile'] ) ) return self::err( 'mad4b_activity_creation_disabled', 'New profile creation is disabled.' );
        if ( $user_id && ! get_userdata( $user_id ) ) return self::err( 'mad4b_activity_user_not_found', 'User does not exist.' );
        if ( !$user_id && empty( $cfg['create_user'] ) ) return self::err( 'mad4b_activity_user_creation_disabled', 'Account creation is not enabled for this profile.' );
        if ( !$user_id && ( ! isset( $input['email'], $input['login'] ) ||
            ! is_email( $input['email'] ) || '' === sanitize_user( $input['login'], true ) ||
            username_exists( sanitize_user( $input['login'], true ) ) || email_exists( $input['email'] ) ) )
            return self::err( 'mad4b_activity_user_intake_invalid', 'Unique verified-format login and email required.' );
        if ( !$post_id && ( empty( $input['post_title'] ) || ! is_string( $input['post_title'] ) ||
            strlen( $input['post_title'] ) > 200 ) )
            return self::err( 'mad4b_activity_title_required', 'New profile requires a bounded title.' );
        $attributes = isset( $input['attributes'] ) ? $input['attributes'] : array();
        if ( ! is_array( $attributes ) || count( $attributes ) > self::MAX_FIELDS ||
            array_diff( array_keys( $attributes ), $cfg['attribute_meta_keys'] ) )
            return self::err( 'mad4b_activity_attribute_not_mapped', 'Attribute must exist in parent profile allowlist.' );
        foreach ( $attributes as $v ) if ( ! is_scalar( $v ) || strlen( (string) $v ) > 4000 )
            return self::err( 'mad4b_activity_attribute_value_invalid', 'Only bounded scalar values are accepted.' );
        $classes = isset( $input['classifications'] ) ? $input['classifications'] : array();
        if ( ! is_array( $classes ) || array_diff( array_keys( $classes ), $cfg['taxonomy_slugs'] ) )
            return self::err( 'mad4b_activity_taxonomy_not_mapped', 'Classification must be enabled by this profile.' );
        foreach ( $classes as $taxonomy => $ids ) {
            $obj = get_taxonomy( $taxonomy );
            if ( !$obj || ! current_user_can( $obj->cap->assign_terms ) || ! is_array( $ids ) || count( $ids ) > 30 )
                return self::err( 'mad4b_activity_classification_denied', 'Classification is not assignable or exceeds the limit.' );
            foreach ( $ids as $id ) if ( ! is_int( $id ) || $id < 1 || ! term_exists( $id, $taxonomy ) )
                return self::err( 'mad4b_activity_term_missing', 'A classification term does not exist.' );
        }
        $post_key = $cfg['post_user_meta_key']; $user_key = $cfg['user_post_meta_key'];
        $current_user = $post_id ? absint( get_post_meta( $post_id, $post_key, true ) ) : 0;
        $current_post = $user_id ? absint( get_user_meta( $user_id, $user_key, true ) ) : 0;
        if ( ( $current_user && ( ! $user_id || $current_user !== $user_id ) ) ||
            ( $current_post && ( ! $post_id || $current_post !== $post_id ) ) )
            return self::err( 'mad4b_activity_relation_conflict', 'Existing mirrored user/post relation conflicts with the requested link.' );
        $scope = array( 'contract' => self::CONTRACT, 'site_uuid' => $binding['site_uuid'],
            'profile_slug' => $binding['profile']['slug'], 'profile_revision' => $binding['profile']['revision'],
            'profile_authority_sha256' => $binding['profile']['authority_sha256'],
            'post_id' => $post_id, 'user_id' => $user_id, 'current_user_id' => $current_user,
            'current_post_id' => $current_post, 'email' => !$user_id ? strtolower( (string) $input['email'] ) : '',
            'login' => !$user_id ? sanitize_user( $input['login'], true ) : '',
            'post_title' => !$post_id ? $input['post_title'] : '',
            'attributes' => $attributes, 'classifications' => $classes );
        $hash = self::digest( $scope );
        if ( '' === $hash ) return self::err( 'mad4b_activity_plan_encoding_failed', 'Plan serialization failed.' );
        return array( 'contract' => self::CONTRACT, 'plan_sha256' => $hash,
            'binding' => $scope, 'user_role' => $cfg['user_role'],
            'requires_create_user' => !$user_id, 'requires_create_post' => !$post_id,
            'requires_governed_approval' => true,
            'read_only' => true, 'mutation_performed' => false );
    }
    /**
     * This is a site-configured cross-provider transfer plan, not an
     * unreviewed Google Drive or WordPress mutation. Each provider's
     * existing authenticated and revision-bound adapter performs the write.
     */
    public static function sync_plan( $input = array() ) {
        $input = is_array( $input ) ? $input : array();
        $binding = self::binding( $input );
        if ( is_wp_error( $binding ) ) return $binding;
        $target_id = isset( $input['target_id'] ) ? (string) $input['target_id'] : '';
        $op = isset( $input['operation'] ) ? (string) $input['operation'] : '';
        $targets = isset( $binding['contract']['sync_targets'] ) ? $binding['contract']['sync_targets'] : array();
        if ( ! isset( $targets[ $target_id ] ) || ! in_array( $op, array( 'import', 'export', 'update', 'improve', 'reconcile' ), true ) )
            return self::err( 'mad4b_activity_sync_not_configured', 'Target and operation must be explicitly configured.' );
        $target = $targets[ $target_id ];
        $direction = in_array( $op, array( 'import', 'export' ), true ) ? $op : 'bidirectional';
        if ( ! in_array( $target['direction'], array( $direction, 'bidirectional' ), true ) )
            return self::err( 'mad4b_activity_sync_direction_denied', 'Requested operation exceeds configured sync direction.' );
        $routes = MAD4B_SCP_Content_Experience_Profiles::profile_routes(
            $binding['profile']['slug'], (int) $binding['profile']['revision'] );
        $provider = $target['provider'];
        $ability = 'wordpress' === $provider
            ? array( $routes['update_plan'], $routes['update_apply'], $routes['verify'] )
            : array(); // Drive integration must resolve its own certified provider; Brand Context routes are not generic file editors.
        // The Google Drive Context abilities are Brand Core-specific. They
        // are prerequisites only; arbitrary Drive document updates require
        // an exact certified provider operation and an independent revision.
        $provider_write_certified = false;
        $plan = array( 'contract' => self::CONTRACT, 'provider' => $provider,
            'profile_slug' => $binding['profile']['slug'], 'site_uuid' => $binding['site_uuid'],
            'profile_revision' => (int) $binding['profile']['revision'],
            'profile_authority_sha256' => $binding['profile']['authority_sha256'],
            'target_id' => $target_id, 'operation' => $op,
            'source_ref' => $target['source_ref'], 'field_keys' => $target['field_keys'],
            'conflict_policy' => $target['conflict_policy'],
            'resource_kind' => $target['resource_kind'],
            'purpose' => $target['purpose'],
            'field_owners' => isset( $binding['contract']['field_owners'] ) ? $binding['contract']['field_owners'] : array(),
            'provider_abilities_to_discover' => $ability,
            'provider_write_certified' => $provider_write_certified,
            'provider_binding_required' => 'google_drive' === $provider,
            'source_is_metadata_reference_only' => true,
            'requires_source_and_destination_revision_readback' => true,
            'requires_authenticated_provider_write' => true,
            'requires_independent_content_diff' => true,
            'read_only' => true, 'mutation_performed' => false );
        $plan['plan_sha256'] = self::digest( $plan );
        return $plan;
    }

    public static function apply( $input = array() ) {
        if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'create_users' ) ||
            ! current_user_can( 'edit_users' ) )
            return self::err( 'mad4b_activity_admin_required', 'Exact administrator/user management permissions required.' );
        $input = is_array( $input ) ? $input : array();
        if ( empty( $input['confirmed'] ) || ! isset( $input['plan_sha256'] ) ||
            ! preg_match( '/^[a-f0-9]{64}$/', $input['plan_sha256'] ) )
            return self::err( 'mad4b_activity_confirmation_required', 'Confirmed exact reviewed plan required.' );
        $plan = self::plan( $input );
        if ( is_wp_error( $plan ) ) return $plan;
        if ( ! hash_equals( $plan['plan_sha256'], $input['plan_sha256'] ) )
            return self::err( 'mad4b_activity_plan_drift', 'Profile, meta classifications or identity changed.' );
        $slug = $plan['binding']['profile_slug'];
        $profile = MAD4B_SCP_Content_Experience_Profiles::profile( $slug );
        if ( is_wp_error( $profile ) ) return $profile;
        $type = $profile['post_type']; $cfg = $profile['activity_contract'];
        $obj = MAD4B_SCP_Content_Experience_Profiles::post_type_object( $type );
        if ( is_wp_error( $obj ) || ! current_user_can( MAD4B_SCP_Content_Experience_Profiles::post_type_create_cap( $obj ) ) )
            return self::err( 'mad4b_activity_cpt_creation_denied', 'Post Type creation rights are missing.' );
        $key = isset( $input['operation_key'] ) ? (string) $input['operation_key'] : '';
        if ( ! preg_match( '/^[A-Za-z0-9._:-]{12,128}$/', $key ) )
            return self::err( 'mad4b_activity_operation_key_invalid', 'Stable idempotent operation key is required.' );
        $lock = 'mad4b_activity_lock_' . hash( 'sha256', $plan['binding']['site_uuid'] . '|' . $slug . '|' . $key );
        if ( ! add_option( $lock, array( 'plan_sha256' => $plan['plan_sha256'], 'started_at' => gmdate( 'c' ) ), '', false ) )
            return self::err( 'mad4b_activity_operation_exists', 'Operation already exists; reconcile its exact result instead of recreating.' );
        $user_id = (int) $plan['binding']['user_id']; $post_id = (int) $plan['binding']['post_id'];
        if ( !$user_id ) {
            $created = wp_insert_user( array(
                'user_login' => $plan['binding']['login'], 'user_email' => $plan['binding']['email'],
                'user_pass' => wp_generate_password( 32, true, true ), 'role' => $cfg['user_role'],
            ) );
            if ( is_wp_error( $created ) ) return $created;
            $user_id = (int) $created;
        }
        if ( !$post_id ) {
            $created = wp_insert_post( array( 'post_type' => $type,
                'post_title' => sanitize_text_field( $plan['binding']['post_title'] ),
                'post_status' => 'draft', 'post_author' => get_current_user_id() ), true );
            if ( is_wp_error( $created ) ) return $created;
            $post_id = (int) $created;
        }
        $post_key = $cfg['post_user_meta_key']; $user_key = $cfg['user_post_meta_key'];
        $before_user = absint( get_post_meta( $post_id, $post_key, true ) );
        $before_post = absint( get_user_meta( $user_id, $user_key, true ) );
        if ( $before_user && $before_user !== $user_id || $before_post && $before_post !== $post_id )
            return self::err( 'mad4b_activity_relation_conflicted', 'Another user or post owns the mapped relationship.' );
        update_post_meta( $post_id, $post_key, $user_id );
        update_user_meta( $user_id, $user_key, $post_id );
        foreach ( $plan['binding']['attributes'] as $field => $v )
            update_post_meta( $post_id, $field, is_bool( $v ) ? (int) $v : sanitize_text_field( (string) $v ) );
        foreach ( $plan['binding']['classifications'] as $tax => $ids ) {
            $value = wp_set_object_terms( $post_id, $ids, $tax, false );
            if ( is_wp_error( $value ) ) return $value;
        }
        if ( absint( get_post_meta( $post_id, $post_key, true ) ) !== $user_id ||
            absint( get_user_meta( $user_id, $user_key, true ) ) !== $post_id )
            return self::err( 'mad4b_activity_readback_failed', 'Bidirectional user/profile relation did not pass exact readback.' );
        if ( ! empty( $plan['requires_create_user'] ) && function_exists( 'wp_send_new_user_notifications' ) )
            wp_send_new_user_notifications( $user_id, 'user' );
        update_option( $lock, array( 'complete' => true, 'user_id' => $user_id,
            'post_id' => $post_id, 'plan_sha256' => $plan['plan_sha256'] ), false );
        return array( 'contract' => self::CONTRACT, 'profile_slug' => $slug,
            'post_type' => $type, 'user_id' => $user_id, 'post_id' => $post_id,
            'profile_draft' => true, 'readback_verified' => true, 'mutation_performed' => true );
    }
}

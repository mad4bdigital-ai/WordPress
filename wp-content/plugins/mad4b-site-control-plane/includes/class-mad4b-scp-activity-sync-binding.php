<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/** Exact enrolled site/Brand/entity/provider binding for the activity sync saga. */
trait MAD4B_SCP_Activity_Sync_Binding {
    private static function bind( $input ) {
        $slug = isset( $input['profile_slug'] ) ? (string) $input['profile_slug'] : '';
        $entity = isset( $input['entity_id'] ) ? (string) $input['entity_id'] : '';
        if ( ! class_exists( 'MAD4B_SCP_Site_Profile' ) || ! MAD4B_SCP_Site_Profile::configured() ||
            ! MAD4B_SCP_Site_Profile::origin_enrolled() || ! MAD4B_SCP_Site_Profile::site_urls_match_enrollment() )
            return self::err( 'mad4b_sync_site_not_enrolled', 'Exact enrolled site is required.' );
        $profile = MAD4B_SCP_Content_Experience_Profiles::profile( $slug );
        if ( is_wp_error( $profile ) ) return $profile;
        $c = isset( $profile['activity_contract'] ) ? $profile['activity_contract'] : array();
        if ( empty( $profile['enabled'] ) || empty( $c['enabled'] ) ||
            ! isset( $c['sync_targets'], $c['attribute_meta_keys'] ) )
            return self::err( 'mad4b_sync_activity_not_enabled', 'Enable a versioned Activity facet first.' );
        if ( ! preg_match( '/^[A-Za-z0-9._:-]{1,180}$/D', $entity ) ||
            ( isset( $c['sync_identity_key'] ) && 'post_id' === $c['sync_identity_key'] &&
                ( ! ctype_digit( $entity ) || (int) $entity < 1 ||
                    get_post_type( (int) $entity ) !== $profile['post_type'] ||
                    ! current_user_can( 'edit_post', (int) $entity ) ) ) )
            return self::err( 'mad4b_sync_entity_mismatch', 'Entity is not bound to this WordPress CPT.' );
        $sources = array( 'wordpress' => array( 'provider' => 'wordpress', 'direction' => 'bidirectional',
            'resource_kind' => 'wordpress_post', 'purpose' => 'record_data',
            'source_ref' => $entity, 'field_keys' => $c['attribute_meta_keys'], 'field_bindings' => array() ) );
        foreach ( $c['sync_targets'] as $id => $target ) {
            if ( ! isset( $target['purpose'] ) || 'record_data' !== $target['purpose'] ) continue;
            $mode = isset( $target['resource_binding_mode'] ) ? $target['resource_binding_mode'] : 'static';
            if ( 'entity_post_meta' === $mode ) {
                $key = isset( $target['resource_binding_meta_key'] ) ? (string) $target['resource_binding_meta_key'] : '';
                if ( ! ctype_digit( $entity ) || (int) $entity < 1 ||
                    ! in_array( $key, $profile['meta_keys'], true ) ||
                    get_post_type( (int) $entity ) !== $profile['post_type'] ||
                    ! current_user_can( 'edit_post', (int) $entity ) )
                    return self::err( 'mad4b_sync_entity_provider_binding_denied', 'Per-entity source binding is not allowed by this profile.' );
                $resource = get_post_meta( (int) $entity, $key, true );
                if ( ! is_string( $resource ) || ! preg_match( '/^[A-Za-z0-9._:-]{8,180}$/D', $resource ) )
                    return self::err( 'mad4b_sync_entity_provider_ref_missing', 'Entity has no approved exact resource ID bound to its profile Meta.' );
                $target['source_ref'] = $resource;
            }
            $sources[ $id ] = $target;
        }
        $identity = class_exists( 'MAD4B_SCP_Operational_Integrity', false )
            ? MAD4B_SCP_Operational_Integrity::capture()
            : self::err( 'mad4b_sync_integrity_unavailable', 'Trusted scope unavailable.' );
        if ( is_wp_error( $identity ) ) return $identity;
        if ( ! isset( $identity['fingerprint'], $identity['scope']['site_uuid'] ) ||
            ! hash_equals( (string) MAD4B_SCP_Site_Profile::site_uuid(),
                (string) $identity['scope']['site_uuid'] ) )
            return self::err( 'mad4b_sync_identity_unbound', 'Source profile is not bound to the trusted site.' );
        return array( 'profile' => $profile, 'contract' => $c, 'entity_id' => $entity,
            'site_uuid' => (string) MAD4B_SCP_Site_Profile::site_uuid(),
            'scope_fingerprint' => $identity['fingerprint'], 'sources' => $sources );
    }
}

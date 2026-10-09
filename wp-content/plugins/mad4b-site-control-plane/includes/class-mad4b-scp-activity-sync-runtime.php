<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Durable, exact-scope cross-provider synchronization coordinator.
 * A trusted WP-local adapter can be registered through the filter
 * mad4b_activity_sync_adapters. An adapter MUST implement read and CAS write;
 * this runtime never treats caller-supplied provider data as a receipt.
 *
 * This is a bounded saga, NOT an atomic distributed transaction.
 */
final class MAD4B_SCP_Activity_Sync_Runtime {
    const CONTRACT = 'mad4b.activity-sync-runtime.v1';
    const MAX_STEPS = 400;
    private static function err( $code, $message ) { return new WP_Error( $code, $message ); }
    private static function digest( $value ) {
        $s = wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        return is_string( $s ) ? hash( 'sha256', $s ) : '';
    }

    /**
     * Every provider-facing transition must be persisted AND reread before
     * an external side effect or a lease release. WordPress returns false
     * from update_option() for unchanged values; exact readback is decisive.
     */
    private static function persist_operation( $key, $next, $code ) {
        update_option( $key, $next, false );
        if ( function_exists( 'wp_cache_delete' ) ) wp_cache_delete( $key, 'options' );
        $back = get_option( $key, false );
        if ( ! is_array( $back ) ||
            ! hash_equals( self::digest( $next ), self::digest( $back ) ) )
            return self::err( $code, 'Operation journal write/readback was not confirmed. No new provider write is authorized.' );
        return true;
    }
    private static function release_operation_lease( $binding, $operation_key ) {
        $key = self::key( $binding, 'lease' );
        $lease = get_option( $key, false );
        if ( ! is_array( $lease ) || ! isset( $lease['operation_key'] ) ||
            ! hash_equals( (string) $lease['operation_key'], (string) $operation_key ) )
            return self::err( 'mad4b_sync_lease_identity_changed', 'Cannot release a lease belonging to another worker.' );
        delete_option( $key );
        if ( false !== get_option( $key, false ) )
            return self::err( 'mad4b_sync_lease_release_unverified', 'Could not verify release of this operation lease.' );
        return true;
    }
    /** Definitive refusal before the next provider call: journal, then unlock. */
    private static function prewrite_failure( $binding, $key, $op, $reason ) {
        $op['state'] = 'needs_reconcile';
        $persisted = self::persist_operation( $key, $op, 'mad4b_sync_prewrite_failure_unverified' );
        if ( is_wp_error( $persisted ) ) return $persisted;
        $released = self::release_operation_lease( $binding, $op['operation_key'] );
        if ( is_wp_error( $released ) ) return $released;
        return $reason;
    }
    private static function key( $binding, $which ) {
        return 'mad4b_asyn_' . $which . '_' . hash( 'sha256',
            $binding['site_uuid'] . '|' . $binding['profile']['slug'] . '|' . $binding['entity_id'] );
    }
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
        return array( 'profile' => $profile, 'contract' => $c, 'entity_id' => $entity,
            'site_uuid' => (string) MAD4B_SCP_Site_Profile::site_uuid(), 'sources' => $sources );
    }
    public static function read_wordpress( $source, $fields, $binding ) {
        $id = (int) $binding['entity_id'];
        if ( !$id || get_post_type( $id ) !== $binding['profile']['post_type'] ||
            ! current_user_can( 'edit_post', $id ) )
            return self::err( 'mad4b_sync_wp_read_denied', 'Post access denied.' );
        $values = array();
        foreach ( $fields as $field ) {
            $selector = isset( $source['field_bindings'][ $field ]['provider_field'] )
                ? $source['field_bindings'][ $field ]['provider_field'] : $field;
            // Parent profile's declared metadata allowlist is the WP boundary.
            if ( ! in_array( $selector, $binding['profile']['meta_keys'], true ) ||
                1 !== preg_match( '/^[A-Za-z][A-Za-z0-9_-]{0,120}$/D', $selector ) )
                return self::err( 'mad4b_sync_wp_meta_unmapped', 'WordPress metadata is not in the parent profile allowlist.' );
            $raw = get_post_meta( $id, $selector, true );
            if ( ! is_scalar( $raw ) || strlen( (string) $raw ) > 4000 )
                return self::err( 'mad4b_sync_wp_field_unreadable', 'Only bounded scalar profile data are supported.' );
            $values[ $field ] = (string) $raw;
        }
        ksort( $values, SORT_STRING );
        return array( 'resource_id' => (string) $id, 'revision' => self::digest( $values ),
            'observed_at' => gmdate( 'Y-m-d\\TH:i:s\\Z' ), 'state' => 'present', 'fields' => $values );
    }
    public static function write_wordpress( $source, $field, $value, $expected, $binding ) {
        $fields = array_keys( $expected['fields'] );
        $current = self::read_wordpress( $source, $fields, $binding );
        if ( is_wp_error( $current ) ) return $current;
        if ( ! hash_equals( $current['revision'], $expected['revision'] ) )
            return self::err( 'mad4b_sync_wp_cas_changed', 'WordPress metadata changed before write.' );
        $selector = isset( $source['field_bindings'][ $field ]['provider_field'] )
            ? $source['field_bindings'][ $field ]['provider_field'] : $field;
        if ( ! in_array( $selector, $binding['profile']['meta_keys'], true ) ||
            ! current_user_can( 'edit_post', (int) $binding['entity_id'] ) )
            return self::err( 'mad4b_sync_wp_write_denied', 'Target Meta key is not editable.' );
        if ( strlen( (string) $value ) > 4000 )
            return self::err( 'mad4b_sync_wp_write_unbounded', 'Write field too large.' );
        $before = get_post_meta( (int) $binding['entity_id'], $selector, true );
        if ( (string) $before !== (string) $value ) {
            // WP update_post_meta(..., $prev_value) does NOT constrain writes
            // when previous value is empty. Use an exact SQL row-level CAS
            // even for blank metadata, rejecting missing/duplicate rows.
            global $wpdb;
            if ( ! isset( $wpdb->postmeta ) )
                return self::err( 'mad4b_sync_wp_atomic_store_unavailable', 'WordPress atomic metadata storage is unavailable.' );
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT meta_id, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s",
                (int) $binding['entity_id'], $selector ), ARRAY_A );
            if ( ! is_array( $rows ) || count( $rows ) !== 1 )
                return self::err( 'mad4b_sync_wp_meta_row_ambiguous', 'Target Meta key is missing or has duplicate rows; reconcile before mutation.' );
            $stored = (string) $rows[0]['meta_value'];
            if ( ! hash_equals( $stored, maybe_serialize( $before ) ) )
                return self::err( 'mad4b_sync_wp_row_cas_changed', 'Metadata changed since the last WordPress read.' );
            $clean = function_exists( 'sanitize_meta' )
                ? sanitize_meta( $selector, (string) $value, 'post', $binding['profile']['post_type'] )
                : (string) $value;
            if ( ! is_string( $clean ) || $clean !== (string) $value )
                return self::err( 'mad4b_sync_wp_value_transformation_required', 'Registered WordPress sanitization would change the approved field value.' );
            // Use byte-sensitive CAS: typical WP database collations are
            // case-insensitive and plain WHERE meta_value=%s is insufficient.
            $updated = $wpdb->query( $wpdb->prepare(
                "UPDATE {$wpdb->postmeta} SET meta_value = %s WHERE meta_id = %d AND BINARY meta_value = BINARY %s LIMIT 1",
                maybe_serialize( $clean ), (int) $rows[0]['meta_id'], $stored ) );
            if ( 1 !== $updated )
                return self::err( 'mad4b_sync_wp_row_cas_failed', 'Atomic WordPress Meta row compare-and-set failed.' );
            wp_cache_delete( (int) $binding['entity_id'], 'post_meta' );
            do_action( 'updated_post_meta', (int) $rows[0]['meta_id'],
                (int) $binding['entity_id'], $selector, $clean );
            do_action( 'updated_postmeta', (int) $rows[0]['meta_id'],
                (int) $binding['entity_id'], $selector, $clean );
        }
        $after = self::read_wordpress( $source, $fields, $binding );
        if ( is_wp_error( $after ) || (string) $after['fields'][ $field ] !== (string) $value )
            return self::err( 'mad4b_sync_wp_readback_failed', 'WordPress metadata readback failed.' );
        return $after;
    }
    private static function adapters( $binding ) {
        $adapters = array( 'wordpress' => array(
            'read' => array( __CLASS__, 'read_wordpress' ),
            'write' => array( __CLASS__, 'write_wordpress' ),
            'conditional_write' => true, 'readback' => true ) );
        // A trusted server-installed extension registers the Google Drive
        // provider. Chat input cannot inject a callable or authentication.
        if ( function_exists( 'apply_filters' ) )
            $adapters = apply_filters( 'mad4b_activity_sync_adapters', $adapters, $binding['profile'] );
        if ( ! is_array( $adapters ) ) return array();
        return $adapters;
    }
    private static function observe( $binding ) {
        $registered = self::adapters( $binding ); $observations = array(); $capabilities = array();
        foreach ( $binding['sources'] as $id => $source ) {
            $provider = $source['provider'];
            if ( ! isset( $registered[ $provider ] ) ||
                ! isset( $registered[ $provider ]['read'] ) ||
                ! is_callable( $registered[ $provider ]['read'] ) )
                return self::err( 'mad4b_sync_provider_unavailable', 'Required authenticated source provider is not registered.' );
            $adapter = $registered[ $provider ];
            $field_keys = array_values( (array) $source['field_keys'] );
            $read = call_user_func( $adapter['read'], $source, $field_keys, $binding );
            if ( is_wp_error( $read ) ) return $read;
            if ( ! is_array( $read ) || ! isset( $read['resource_id'], $read['revision'], $read['observed_at'], $read['fields'] ) ||
                ! is_array( $read['fields'] ) || array_diff( $field_keys, array_keys( $read['fields'] ) ) ||
                array_diff( array_keys( $read['fields'] ), $field_keys ) ||
                ! isset( $read['state'] ) || 'present' !== $read['state'] )
                return self::err( 'mad4b_sync_provider_observation_invalid', 'Provider returned incomplete, missing or deleted source fields.' );
            if ( ! is_string( $read['revision'] ) ||
                ! preg_match( '/^[A-Za-z0-9._:-]{1,160}$/D', $read['revision'] ) ||
                ! is_string( $read['observed_at'] ) ||
                false === strtotime( $read['observed_at'] ) ||
                strtotime( $read['observed_at'] ) > time() + 60 ||
                time() - strtotime( $read['observed_at'] ) > 3600 )
                return self::err( 'mad4b_sync_provider_revision_stale',
                    'Provider must return a fresh, bounded exact resource revision.' );
            foreach ( $read['fields'] as $field_value )
                if ( ! is_scalar( $field_value ) || strlen( (string) $field_value ) > 4000 )
                    return self::err( 'mad4b_sync_provider_field_unbounded', 'Provider returned an unsafe field value.' );
            $expect = (string) $source['source_ref'];
            if ( (string) $read['resource_id'] !== $expect )
                return self::err( 'mad4b_sync_provider_resource_swapped', 'Provider resource identity differs from configured exact source.' );
            $observations[ $id ] = $read;
            $capabilities[ $id ] = array( 'write' => isset( $adapter['write'] ) &&
                is_callable( $adapter['write'] ) && ! empty( $adapter['conditional_write'] ) && ! empty( $adapter['readback'] ) );
        }
        return array( 'observations' => $observations, 'capabilities' => $capabilities, 'adapters' => $registered );
    }
    private static function checkpoint( $binding ) {
        $raw = get_option( self::key( $binding, 'checkpoint' ), false );
        if ( ! is_array( $raw ) || ! isset( $raw['site_uuid'], $raw['profile_revision'], $raw['authority_sha256'], $raw['sources'] ) ||
            $raw['site_uuid'] !== $binding['site_uuid'] ||
            (int) $raw['profile_revision'] !== (int) $binding['profile']['revision'] ||
            $raw['authority_sha256'] !== $binding['profile']['authority_sha256'] )
            return self::err( 'mad4b_sync_checkpoint_missing_or_drifted', 'Persisted checkpoint missing or profile/site authority changed.' );
        return $raw;
    }
    private static function hashes( $observations ) {
        $out = array();
        foreach ( $observations as $id => $item ) {
            $fields = array();
            foreach ( $item['fields'] as $field => $value )
                $fields[ $field ] = self::digest( array( 'type' => gettype( $value ), 'value' => $value ) );
            $out[ $id ] = array( 'resource_id' => $item['resource_id'], 'revision' => $item['revision'], 'field_hashes' => $fields );
        }
        return $out;
    }
    private static function trusted_plan( $binding, $observed, $checkpoint ) {
        $input = array( 'profile_slug' => $binding['profile']['slug'],
            'entity_id' => $binding['entity_id'], 'observations' => $observed['observations'],
            'baseline' => $checkpoint['sources'], 'max_snapshot_age_seconds' => 3600 );
        $plan = MAD4B_SCP_Activity_Source_Reconciliation::plan( $input );
        if ( is_wp_error( $plan ) ) return $plan;
        $steps = array();
        if ( ! $plan['has_conflicts'] ) foreach ( $plan['copy_proposals'] as $proposal ) {
            foreach ( $proposal['destination_ids'] as $dest ) {
                if ( ! isset( $observed['capabilities'][ $dest ] ) || !$observed['capabilities'][ $dest ]['write'] )
                    return self::err( 'mad4b_sync_conditional_writer_missing', 'Destination has no certified conditional writer with readback.' );
                $source = $proposal['source_id']; $field = $proposal['field'];
                if ( 'wordpress' !== $source && 'export' === $binding['sources'][ $source ]['direction'] )
                    return self::err( 'mad4b_sync_source_direction_denied', 'Outbound-only source cannot initiate a write.' );
                $dest_value = $observed['observations'][ $dest ]['fields'][ $field ];
                $steps[] = array( 'source' => $source, 'destination' => $dest,
                    'field' => $field, 'value_sha256' => $proposal['source_field_sha256'],
                    'expected_destination_value_sha256' => self::digest( array(
                        'type' => gettype( $dest_value ), 'value' => $dest_value ) ) );
            }
        }
        if ( count( $steps ) > self::MAX_STEPS )
            return self::err( 'mad4b_sync_steps_unbounded', 'Sync proposal exceeds bounded execution.' );
        return array( 'reconciliation' => $plan, 'steps' => $steps );
    }
    public static function status( $input = array() ) {
        if ( ! current_user_can( 'manage_options' ) ) return self::err( 'mad4b_sync_status_denied', 'Administrator required.' );
        $binding = self::bind( is_array( $input ) ? $input : array() );
        if ( is_wp_error( $binding ) ) return $binding;
        $cp = get_option( self::key( $binding, 'checkpoint' ), false );
        $op = get_option( self::key( $binding, 'operation' ), false );
        $adapters = self::adapters( $binding );
        $provider_status = array();
        foreach ( $binding['sources'] as $source_id => $source ) {
            $id = $source['provider'];
            $a = isset( $adapters[ $id ] ) ? $adapters[ $id ] : array();
            $provider_status[ $source_id ] = array(
                'provider' => $id, 'resource_kind' => $source['resource_kind'],
                'reader_registered' => isset( $a['read'] ) && is_callable( $a['read'] ),
                'conditional_writer_registered' => isset( $a['write'] ) && is_callable( $a['write'] ) &&
                    ! empty( $a['conditional_write'] ) && ! empty( $a['readback'] ),
                'authenticated_readback_proven' => false,
            );
        }
        return array( 'contract' => self::CONTRACT, 'checkpoint_present' => is_array( $cp ),
            'checkpoint_sha256' => is_array( $cp ) ? self::digest( $cp ) : null,
            'provider_status' => $provider_status,
            'operation_state' => is_array( $op ) && isset( $op['state'] ) ? $op['state'] : 'none',
            'operation_sha256' => is_array( $op ) ? self::digest( $op ) : null,
            'read_only' => true, 'mutation_performed' => false );
    }
    public static function plan( $input = array() ) {
        if ( ! current_user_can( 'manage_options' ) )
            return self::err( 'mad4b_sync_plan_admin_required', 'Administrator required to inspect source fields.' );
        $binding = self::bind( is_array( $input ) ? $input : array() );
        if ( is_wp_error( $binding ) ) return $binding;
        $observed = self::observe( $binding );
        if ( is_wp_error( $observed ) ) return $observed;
        $checkpoint = get_option( self::key( $binding, 'checkpoint' ), false );
        if ( ! is_array( $checkpoint ) ) {
            $hashes = self::hashes( $observed['observations'] );
            $equal = true;
            foreach ( $binding['contract']['attribute_meta_keys'] as $field ) {
                $set = array();
                foreach ( $binding['sources'] as $id => $src )
                    if ( in_array( $field, $src['field_keys'], true ) )
                        $set[] = $hashes[ $id ]['field_hashes'][ $field ];
                if ( count( array_unique( $set ) ) > 1 ) $equal = false;
            }
            $choices = isset( $input['field_sources'] ) ? $input['field_sources'] : array();
            if ( ! is_array( $choices ) || count( $choices ) > 40 ||
                array_diff( array_keys( $choices ), $binding['contract']['attribute_meta_keys'] ) )
                return self::err( 'mad4b_sync_initial_sources_invalid', 'Initial per-field source choices must be bounded and configured.' );
            $steps = array(); $requires_review = false;
            if ( ! $equal ) foreach ( $binding['contract']['attribute_meta_keys'] as $field ) {
                $involved = array();
                foreach ( $binding['sources'] as $id => $source ) {
                    if ( in_array( $field, $source['field_keys'], true ) ) $involved[] = $id;
                }
                $values = array();
                foreach ( $involved as $id ) $values[ $id ] = $hashes[ $id ]['field_hashes'][ $field ];
                if ( count( array_unique( array_values( $values ) ) ) < 2 ) continue;
                $owner = isset( $binding['contract']['field_owners'][ $field ] )
                    ? $binding['contract']['field_owners'][ $field ] : 'manual_review';
                $chosen = isset( $choices[ $field ] ) ? $choices[ $field ] : '';
                if ( ! in_array( $chosen, $involved, true ) ||
                    ( 'manual_review' !== $owner && $owner !== $chosen ) ||
                    ( 'wordpress' !== $chosen && 'export' === $binding['sources'][ $chosen ]['direction'] ) ) {
                    $requires_review = true; continue;
                }
                foreach ( $involved as $dest ) {
                    if ( $chosen === $dest || $values[ $dest ] === $values[ $chosen ] ) continue;
                    if ( ! isset( $observed['capabilities'][ $dest ] ) ||
                        !$observed['capabilities'][ $dest ]['write'] ||
                        ( 'wordpress' !== $dest &&
                          ! in_array( $binding['sources'][ $dest ]['direction'], array( 'export', 'bidirectional' ), true ) ) ) {
                        $requires_review = true; continue;
                    }
                    $steps[] = array( 'source' => $chosen, 'destination' => $dest,
                        'field' => $field, 'value_sha256' => $values[ $chosen ],
                        'expected_destination_value_sha256' => $values[ $dest ] );
                }
            }
            if ( count( $steps ) > self::MAX_STEPS )
                return self::err( 'mad4b_sync_initial_steps_unbounded', 'Initial arbitration exceeds the step budget.' );
            $mode = $equal ? 'bootstrap' : 'bootstrap_arbitrate';
            $proposal = array( 'mode' => $mode, 'site_uuid' => $binding['site_uuid'],
                'profile_slug' => $binding['profile']['slug'],
                'profile_revision' => $binding['profile']['revision'],
                'profile_authority_sha256' => $binding['profile']['authority_sha256'],
                'entity_id' => $binding['entity_id'], 'sources' => $hashes,
                'field_sources' => $choices, 'steps' => $steps );
            return array( 'contract' => self::CONTRACT, 'mode' => $mode,
                'plan_sha256' => self::digest( $proposal ), 'sources_consistent' => $equal,
                'requires_manual_reconciliation' => !$equal && ( $requires_review || !$choices ),
                'source_ids' => array_keys( $hashes ), 'steps' => $steps,
                'ready_for_apply' => !$requires_review && ( $equal || ! empty( $steps ) ),
                'read_only' => true, 'mutation_performed' => false );
        }
        $checkpoint = self::checkpoint( $binding );
        if ( is_wp_error( $checkpoint ) ) return $checkpoint;
        $resolved = self::trusted_plan( $binding, $observed, $checkpoint );
        if ( is_wp_error( $resolved ) ) return $resolved;
        $intent = array( 'mode' => 'sync', 'site_uuid' => $binding['site_uuid'],
            'profile_slug' => $binding['profile']['slug'], 'entity_id' => $binding['entity_id'],
            'profile_revision' => $binding['profile']['revision'],
            'authority_sha256' => $binding['profile']['authority_sha256'],
            'checkpoint_sha256' => self::digest( $checkpoint ),
            'source_snapshots' => self::hashes( $observed['observations'] ),
            'steps' => $resolved['steps'] );
        return array( 'contract' => self::CONTRACT, 'mode' => 'sync',
            'plan_sha256' => self::digest( $intent ),
            'has_conflicts' => $resolved['reconciliation']['has_conflicts'],
            'conflicts' => $resolved['reconciliation']['conflicts'],
            'steps' => $resolved['steps'], 'ready_for_apply' => !$resolved['reconciliation']['has_conflicts'],
            'read_only' => true, 'mutation_performed' => false );
    }
    public static function begin( $input = array() ) {
        if ( ! current_user_can( 'manage_options' ) || empty( $input['confirmed'] ) ||
            empty( $input['plan_sha256'] ) || ! preg_match( '/^[a-f0-9]{64}$/D', (string) $input['plan_sha256'] ) )
            return self::err( 'mad4b_sync_exact_confirmation_required', 'Confirmed exact administrator plan hash required.' );
        $binding = self::bind( $input );
        if ( is_wp_error( $binding ) ) return $binding;
        $plan = self::plan( $input );
        if ( is_wp_error( $plan ) ) return $plan;
        if ( empty( $plan['ready_for_apply'] ) || ! hash_equals( $plan['plan_sha256'], $input['plan_sha256'] ) )
            return self::err( 'mad4b_sync_plan_stale_or_conflicted', 'Provider source changed, conflicts exist, or plan hash is stale.' );
        $key = self::key( $binding, 'operation' );
        if ( false !== get_option( $key, false ) )
            return self::err( 'mad4b_sync_operation_exists', 'Previous operation must finish or undergo independent reconciliation.' );
        $operation_key = isset( $input['operation_key'] ) ? (string) $input['operation_key'] : '';
        if ( ! preg_match( '/^[A-Za-z0-9._:-]{12,128}$/D', $operation_key ) )
            return self::err( 'mad4b_sync_operation_key_invalid', 'Stable operation identity required.' );
        if ( 'bootstrap' === $plan['mode'] ) {
            // Independently read and require another exact match before
            // persisted trust-on-first-use bootstrap. No arbitrary baseline.
            $obs = self::observe( $binding );
            if ( is_wp_error( $obs ) ) return $obs;
            $sources = self::hashes( $obs['observations'] );
            $checkpoint = array( 'site_uuid' => $binding['site_uuid'],
                'profile_revision' => (int) $binding['profile']['revision'],
                'authority_sha256' => $binding['profile']['authority_sha256'], 'sources' => $sources );
            $fresh = self::plan( $input );
            if ( is_wp_error( $fresh ) || !$fresh['ready_for_apply'] ||
                ! hash_equals( $fresh['plan_sha256'], $input['plan_sha256'] ) )
                return self::err( 'mad4b_sync_bootstrap_drifted', 'Authoritative sources changed during bootstrap.' );
            if ( ! add_option( self::key( $binding, 'checkpoint' ), $checkpoint, '', false ) )
                return self::err( 'mad4b_sync_checkpoint_concurrent', 'Checkpoint has already been initialized.' );
            if ( function_exists( 'wp_cache_delete' ) )
                wp_cache_delete( self::key( $binding, 'checkpoint' ), 'options' );
            if ( self::digest( get_option( self::key( $binding, 'checkpoint' ), false ) ) !== self::digest( $checkpoint ) )
                return self::err( 'mad4b_sync_bootstrap_readback_unverified', 'First-run checkpoint could not be verified.' );
            return array( 'contract' => self::CONTRACT, 'checkpoint_initialized' => true,
                'checkpoint_sha256' => self::digest( $checkpoint ), 'mutation_performed' => true );
        }
        $op = array( 'site_uuid' => $binding['site_uuid'],
            'profile_revision' => (int) $binding['profile']['revision'],
            'authority_sha256' => $binding['profile']['authority_sha256'],
            'plan_sha256' => $plan['plan_sha256'], 'operation_key' => $operation_key,
            'state' => 'queued', 'next_step' => 0, 'steps' => $plan['steps'],
            'initial_checkpoint_sha256' => 'bootstrap_arbitrate' === $plan['mode']
                ? null : self::digest( self::checkpoint( $binding ) ),
            'bootstrap_arbitration' => 'bootstrap_arbitrate' === $plan['mode'],
            'started_at' => gmdate( 'c' ) );
        if ( ! add_option( $key, $op, '', false ) )
            return self::err( 'mad4b_sync_operation_raced', 'Another worker reserved the same sync entity.' );
        if ( function_exists( 'wp_cache_delete' ) ) wp_cache_delete( $key, 'options' );
        if ( self::digest( get_option( $key, false ) ) !== self::digest( $op ) )
            return self::err( 'mad4b_sync_operation_begin_unverified', 'New operation journal was not read back; no provider write authorized.' );
        return array( 'contract' => self::CONTRACT, 'state' => 'queued', 'step_count' => count( $op['steps'] ),
            'operation_key' => $operation_key, 'mutation_performed' => true );
    }
    public static function advance( $input = array() ) {
        if ( ! current_user_can( 'manage_options' ) || empty( $input['confirmed'] ) )
            return self::err( 'mad4b_sync_advance_denied', 'Administrator confirmation required.' );
        $binding = self::bind( $input );
        if ( is_wp_error( $binding ) ) return $binding;
        $key = self::key( $binding, 'operation' );
        $op = get_option( $key, false );
        if ( ! is_array( $op ) || ! isset( $op['operation_key'], $op['steps'], $op['next_step'] ) ||
            ! isset( $input['operation_key'] ) || ! hash_equals( $op['operation_key'], (string) $input['operation_key'] ) ||
            $op['site_uuid'] !== $binding['site_uuid'] ||
            (int) $op['profile_revision'] !== (int) $binding['profile']['revision'] ||
            $op['authority_sha256'] !== $binding['profile']['authority_sha256'] )
            return self::err( 'mad4b_sync_operation_not_current', 'Exact operation, site and profile authority must match.' );
        if ( ! in_array( $op['state'], array( 'queued', 'running' ), true ) )
            return self::err( 'mad4b_sync_requires_recovery', 'Unknown or completed operation requires independent reconciliation.' );
        $mutex = self::key( $binding, 'lease' );
        if ( ! add_option( $mutex, array( 'operation_key' => $op['operation_key'], 'at' => time() ), '', false ) )
            return self::err( 'mad4b_sync_lease_busy', 'Another worker is running, or crashed while holding this lease.' );
        // Persist the in-flight step BEFORE executing any provider write.
        $i = (int) $op['next_step'];
        if ( $i >= count( $op['steps'] ) ) {
            $obs = self::observe( $binding );
            if ( is_wp_error( $obs ) ) return self::prewrite_failure( $binding, $key, $op, $obs );
            $cp = ! empty( $op['bootstrap_arbitration'] )
                ? get_option( self::key( $binding, 'checkpoint' ), false ) : self::checkpoint( $binding );
            if ( ( ! empty( $op['bootstrap_arbitration'] ) && false !== $cp ) ||
                ( empty( $op['bootstrap_arbitration'] ) && is_wp_error( $cp ) ) ) {
                $op['state'] = 'needs_reconcile';
                $persisted = self::persist_operation( $key, $op, 'mad4b_sync_failure_journal_unverified' );
                if ( is_wp_error( $persisted ) ) return $persisted;
                return self::err( 'mad4b_sync_checkpoint_changed', 'Checkpoint unexpected or altered during sync.' );
            }
            // A successful outbox is not enough: every configured field must
            // now agree across participating providers, including fields
            // that this operation did not write. Otherwise retain the old
            // checkpoint and require conflict recovery.
            $current = self::hashes( $obs['observations'] );
            foreach ( $binding['contract']['attribute_meta_keys'] as $field ) {
                $values = array();
                foreach ( $binding['sources'] as $source_id => $source ) {
                    if ( in_array( $field, $source['field_keys'], true ) )
                        $values[] = $current[ $source_id ]['field_hashes'][ $field ];
                }
                if ( count( array_unique( $values ) ) > 1 ) {
                    $op['state'] = 'needs_reconcile';
                $persisted = self::persist_operation( $key, $op, 'mad4b_sync_failure_journal_unverified' );
                if ( is_wp_error( $persisted ) ) return $persisted;
                return self::err( 'mad4b_sync_postwrite_divergence', 'At least one field diverged after provider writes.' );
                }
            }
            // The option is a per-entity journal; no other writer can begin
            // while the lease remains held. Compare the checkpoint to the
            // immutable source of this operation before the final update.
            if ( empty( $op['bootstrap_arbitration'] ) &&
                ( ! isset( $op['initial_checkpoint_sha256'] ) ||
                  ! hash_equals( $op['initial_checkpoint_sha256'], self::digest( $cp ) ) ) ) {
                $op['state'] = 'needs_reconcile';
                $persisted = self::persist_operation( $key, $op, 'mad4b_sync_failure_journal_unverified' );
                if ( is_wp_error( $persisted ) ) return $persisted;
                return self::err( 'mad4b_sync_checkpoint_cas_drift', 'Checkpoint changed while the operation was active.' );
            }
            $next_cp = array( 'site_uuid' => $binding['site_uuid'],
                'profile_revision' => (int) $binding['profile']['revision'],
                'authority_sha256' => $binding['profile']['authority_sha256'],
                'sources' => $current );
            $saved = ! empty( $op['bootstrap_arbitration'] )
                ? add_option( self::key( $binding, 'checkpoint' ), $next_cp, '', false )
                : update_option( self::key( $binding, 'checkpoint' ), $next_cp, false );
            if ( !$saved &&
                self::digest( get_option( self::key( $binding, 'checkpoint' ), false ) ) !== self::digest( $next_cp ) ) {
                $op['state'] = 'needs_reconcile';
                $persisted = self::persist_operation( $key, $op, 'mad4b_sync_failure_journal_unverified' );
                if ( is_wp_error( $persisted ) ) return $persisted;
                return self::err( 'mad4b_sync_checkpoint_update_failed', 'Durable checkpoint update failed.' );
            }
            $back = get_option( self::key( $binding, 'checkpoint' ), false );
            if ( ! is_array( $back ) || ! hash_equals( self::digest( $next_cp ), self::digest( $back ) ) ) {
                $op['state'] = 'needs_reconcile';
                $persisted = self::persist_operation( $key, $op, 'mad4b_sync_failure_journal_unverified' );
                if ( is_wp_error( $persisted ) ) return $persisted;
                return self::err( 'mad4b_sync_checkpoint_readback_failed', 'Durable checkpoint readback failed.' );
            }
            $op['state'] = 'complete'; $op['completed_at'] = gmdate( 'c' );
            $persisted = self::persist_operation( $key, $op, 'mad4b_sync_completion_journal_unverified' );
            if ( is_wp_error( $persisted ) ) return $persisted;
            $released = self::release_operation_lease( $binding, $op['operation_key'] );
            if ( is_wp_error( $released ) ) return $released;
            return array( 'contract' => self::CONTRACT, 'state' => 'complete',
                'writes_applied' => $i, 'checkpoint_advanced' => true,
                'checkpoint_sha256' => self::digest( $back ), 'mutation_performed' => true );
        }
        $step = $op['steps'][ $i ];
        $obs = self::observe( $binding );
        if ( is_wp_error( $obs ) ) return self::prewrite_failure( $binding, $key, $op, $obs );
        $source = $obs['observations'][ $step['source'] ];
        $dest = $obs['observations'][ $step['destination'] ];
        $field = $step['field'];
        $value = $source['fields'][ $field ];
        $destination_value = $dest['fields'][ $field ];
        if ( self::digest( array( 'type' => gettype( $destination_value ), 'value' => $destination_value ) ) !==
            $step['expected_destination_value_sha256'] ) {
            return self::prewrite_failure( $binding, $key, $op,
                self::err( 'mad4b_sync_destination_changed_since_approval',
                    'Destination changed since the approved exact plan. A new read and review is required.' ) );
        }
        if ( self::digest( array( 'type' => gettype( $value ), 'value' => $value ) ) !== $step['value_sha256'] ) {
            return self::prewrite_failure( $binding, $key, $op,
                self::err( 'mad4b_sync_owner_field_drifted', 'Owner field changed since exact approved plan.' ) );
        }
        $provider = $binding['sources'][ $step['destination'] ]['provider'];
        $adapters = $obs['adapters'];
        $adapter = $adapters[ $provider ];
        if ( empty( $adapter['conditional_write'] ) || empty( $adapter['readback'] ) ||
            ! isset( $adapter['write'] ) || ! is_callable( $adapter['write'] ) )
            return self::prewrite_failure( $binding, $key, $op,
                self::err( 'mad4b_sync_writer_not_verified', 'Provider is not certified for conditional writes.' ) );
        $op['state'] = 'step_inflight'; $op['inflight_step'] = $i;
        $persisted = self::persist_operation( $key, $op, 'mad4b_sync_inflight_journal_unverified' );
        if ( is_wp_error( $persisted ) ) {
            // No remote call has started. Unlock only our own lease so an
            // unchanged queued operation can be safely retried or cancelled.
            $released = self::release_operation_lease( $binding, $op['operation_key'] );
            if ( is_wp_error( $released ) ) return $released;
            return $persisted;
        }
        $written = call_user_func( $adapter['write'], $binding['sources'][ $step['destination'] ],
            $field, $value, $dest, $binding );
        if ( is_wp_error( $written ) ) {
            $op['state'] = 'needs_reconcile';
            $persisted = self::persist_operation( $key, $op, 'mad4b_sync_provider_error_journal_unverified' );
            if ( is_wp_error( $persisted ) ) return $persisted;
            return $written;
        }
        $readback = call_user_func( $adapter['read'], $binding['sources'][ $step['destination'] ],
            array_values( $binding['sources'][ $step['destination'] ]['field_keys'] ), $binding );
        if ( is_wp_error( $readback ) || ! isset( $readback['fields'][ $field ] ) ||
            (string) $readback['fields'][ $field ] !== (string) $value ) {
            $op['state'] = 'needs_reconcile';
                $persisted = self::persist_operation( $key, $op, 'mad4b_sync_failure_journal_unverified' );
                if ( is_wp_error( $persisted ) ) return $persisted;
                return self::err( 'mad4b_sync_provider_readback_uncertain', 'Provider write outcome has not passed exact readback.' );
        }
        $op['next_step'] = $i + 1; unset( $op['inflight_step'] ); $op['state'] = 'running';
        $persisted = self::persist_operation( $key, $op, 'mad4b_sync_postwrite_journal_unverified' );
        if ( is_wp_error( $persisted ) ) return $persisted;
        $released = self::release_operation_lease( $binding, $op['operation_key'] );
        if ( is_wp_error( $released ) ) return $released;
        return array( 'contract' => self::CONTRACT, 'state' => 'running',
            'completed_steps' => $op['next_step'], 'step_count' => count( $op['steps'] ),
            'readback_verified' => true, 'mutation_performed' => true );
    }
    /**
     * Recover only a previously attempted write whose *current provider
     * readback* matches the approved source value. Never blindly replay a
     * provider call with an unknown outcome.
     */
    public static function recover( $input = array() ) {
        if ( ! current_user_can( 'manage_options' ) || empty( $input['confirmed'] ) )
            return self::err( 'mad4b_sync_recover_denied', 'Exact administrator recovery required.' );
        $binding = self::bind( $input );
        if ( is_wp_error( $binding ) ) return $binding;
        $key = self::key( $binding, 'operation' );
        $op = get_option( $key, false );
        $expected = isset( $input['expected_operation_sha256'] ) ? (string) $input['expected_operation_sha256'] : '';
        if ( ! is_array( $op ) || ! preg_match( '/^[a-f0-9]{64}$/D', $expected ) ||
            ! hash_equals( self::digest( $op ), $expected ) ||
            ! isset( $input['operation_key'] ) || ! isset( $op['operation_key'] ) ||
            ! hash_equals( $op['operation_key'], (string) $input['operation_key'] ) ||
            ! in_array( $op['state'], array( 'step_inflight', 'needs_reconcile' ), true ) ||
            ( $op['site_uuid'] ?? '' ) !== $binding['site_uuid'] ||
            (int) ( $op['profile_revision'] ?? -1 ) !== (int) $binding['profile']['revision'] ||
            ( $op['authority_sha256'] ?? '' ) !== $binding['profile']['authority_sha256'] ||
            ! isset( $op['inflight_step'] ) )
            return self::err( 'mad4b_sync_recover_state_not_exact', 'Unknown-write reconciliation requires an exact in-flight journal checksum.' );
        if ( 'step_inflight' === $op['state'] &&
            false !== get_option( self::key( $binding, 'lease' ), false ) )
            return self::err( 'mad4b_sync_recover_inflight_worker_not_quiesced', 'In-flight provider request may still be active; recovery needs independent worker quiescence.' );
        $recovery_mutex = self::key( $binding, 'recovery_lease' );
        $recovery_token = self::digest( array( $op['operation_key'], $expected ) );
        if ( ! add_option( $recovery_mutex, $recovery_token, '', false ) )
            return self::err( 'mad4b_sync_recovery_busy', 'Another reconciliation worker owns this operation.' );
        try {
            $fresh_op = get_option( $key, false );
            if ( ! is_array( $fresh_op ) || ! hash_equals( $expected, self::digest( $fresh_op ) ) )
                return self::err( 'mad4b_sync_recovery_journal_changed', 'Journal changed during recovery lock acquisition.' );
            $i = (int) $op['inflight_step'];
            if ( ! isset( $op['steps'][ $i ] ) || $i !== (int) $op['next_step'] )
                return self::err( 'mad4b_sync_recover_step_missing', 'Recorded in-flight step is inconsistent.' );
            $step = $op['steps'][ $i ];
            $observed = self::observe( $binding );
            if ( is_wp_error( $observed ) ) return $observed;
            if ( ! isset( $observed['observations'][ $step['destination'] ]['fields'][ $step['field'] ],
                $observed['observations'][ $step['source'] ]['fields'][ $step['field'] ] ) )
                return self::err( 'mad4b_sync_recover_field_missing', 'No verified postwrite fields available.' );
            $current = $observed['observations'][ $step['destination'] ]['fields'][ $step['field'] ];
            $owner = $observed['observations'][ $step['source'] ]['fields'][ $step['field'] ];
            $hash = static function ( $v ) { return self::digest( array( 'type' => gettype( $v ), 'value' => $v ) ); };
            if ( ! hash_equals( $step['value_sha256'], $hash( $current ) ) ||
                ! hash_equals( $step['value_sha256'], $hash( $owner ) ) )
                return self::err( 'mad4b_sync_recover_requires_review', 'Uncertain provider result differs from exact approved write. Do not replay.' );
            $op['next_step'] = $i + 1; unset( $op['inflight_step'] );
            $op['state'] = 'running'; $op['recovered_at'] = gmdate( 'c' );
            $persisted = self::persist_operation( $key, $op, 'mad4b_sync_recovery_journal_unverified' );
            if ( is_wp_error( $persisted ) ) return $persisted;
            if ( false !== get_option( self::key( $binding, 'lease' ), false ) ) {
                $released = self::release_operation_lease( $binding, $op['operation_key'] );
                if ( is_wp_error( $released ) ) return $released;
            }
            return array( 'contract' => self::CONTRACT, 'state' => 'running',
                'next_step' => $op['next_step'], 'recovered_by_exact_readback' => true,
                'provider_replayed' => false, 'mutation_performed' => true );

        } finally {
            if ( (string) get_option( $recovery_mutex, '' ) === $recovery_token )
                delete_option( $recovery_mutex );
        }
    }
    public static function finalize_reconciled( $input = array() ) {
        if ( ! current_user_can( 'manage_options' ) || empty( $input['confirmed'] ) )
            return self::err( 'mad4b_sync_reconcile_denied', 'Exact administrator reconciliation confirmation required.' );
        $binding = self::bind( $input );
        if ( is_wp_error( $binding ) ) return $binding;
        $key = self::key( $binding, 'operation' );
        $op = get_option( $key, false );
        $expected = isset( $input['expected_operation_sha256'] ) ? (string) $input['expected_operation_sha256'] : '';
        if ( ! is_array( $op ) || ! isset( $op['state'] ) || 'needs_reconcile' !== $op['state'] ||
            ! preg_match( '/^[a-f0-9]{64}$/D', $expected ) ||
            ! hash_equals( self::digest( $op ), $expected ) ||
            ( $op['site_uuid'] ?? '' ) !== $binding['site_uuid'] ||
            (int) ( $op['profile_revision'] ?? -1 ) !== (int) $binding['profile']['revision'] ||
            ( $op['authority_sha256'] ?? '' ) !== $binding['profile']['authority_sha256'] )
            return self::err( 'mad4b_sync_reconcile_journal_not_exact', 'Require unchanged partial saga journal digest.' );
        $recovery_mutex = self::key( $binding, 'recovery_lease' );
        $recovery_token = self::digest( array( $op['operation_key'], $expected ) );
        if ( ! add_option( $recovery_mutex, $recovery_token, '', false ) )
            return self::err( 'mad4b_sync_recovery_busy', 'Another reconciliation worker owns this operation.' );
        try {
            $fresh_op = get_option( $key, false );
            if ( ! is_array( $fresh_op ) || ! hash_equals( $expected, self::digest( $fresh_op ) ) )
                return self::err( 'mad4b_sync_recovery_journal_changed', 'Journal changed during recovery lock acquisition.' );
            $observed = self::observe( $binding );
            if ( is_wp_error( $observed ) ) return $observed;
            $hashes = self::hashes( $observed['observations'] );
            foreach ( $op['steps'] as $step ) {
                if ( ! isset( $hashes[ $step['source'] ]['field_hashes'][ $step['field'] ] ) ||
                    ! hash_equals( $step['value_sha256'],
                        $hashes[ $step['source'] ]['field_hashes'][ $step['field'] ] ) )
                    return self::err( 'mad4b_sync_reconcile_owner_changed', 'Canonical owner no longer matches the approved step.' );
            }
            foreach ( $binding['contract']['attribute_meta_keys'] as $field ) {
                $values = array();
                foreach ( $binding['sources'] as $id => $source ) {
                    if ( in_array( $field, $source['field_keys'], true ) )
                        $values[] = $hashes[ $id ]['field_hashes'][ $field ];
                }
                if ( count( array_unique( $values ) ) !== 1 )
                    return self::err( 'mad4b_sync_reconcile_still_divergent', 'Provider data still differ; preserve old checkpoint.' );
            }
            $old = get_option( self::key( $binding, 'checkpoint' ), false );
            if ( ! empty( $op['bootstrap_arbitration'] ) ) {
                if ( false !== $old )
                    return self::err( 'mad4b_sync_reconcile_checkpoint_raced', 'Unexpected checkpoint exists during bootstrap.' );
            } elseif ( ! is_array( $old ) || ! isset( $op['initial_checkpoint_sha256'] ) ||
                ! hash_equals( $op['initial_checkpoint_sha256'], self::digest( $old ) ) )
                return self::err( 'mad4b_sync_reconcile_checkpoint_raced', 'Checkpoint changed while partially applying writes.' );
            $new = array( 'site_uuid' => $binding['site_uuid'],
                'profile_revision' => (int) $binding['profile']['revision'],
                'authority_sha256' => $binding['profile']['authority_sha256'],
                'sources' => $hashes );
            $saved = ! empty( $op['bootstrap_arbitration'] )
                ? add_option( self::key( $binding, 'checkpoint' ), $new, '', false )
                : update_option( self::key( $binding, 'checkpoint' ), $new, false );
            $back = get_option( self::key( $binding, 'checkpoint' ), false );
            if ( ( !$saved && self::digest( $back ) !== self::digest( $new ) ) ||
                ! is_array( $back ) || ! hash_equals( self::digest( $new ), self::digest( $back ) ) )
                return self::err( 'mad4b_sync_reconcile_checkpoint_failed', 'Final checkpoint readback was not verified.' );
            $op['state'] = 'complete'; $op['reconciled_at'] = gmdate( 'c' );
            $persisted = self::persist_operation( $key, $op, 'mad4b_sync_reconcile_journal_unverified' );
            if ( is_wp_error( $persisted ) ) return $persisted;
            if ( false !== get_option( self::key( $binding, 'lease' ), false ) ) {
                $released = self::release_operation_lease( $binding, $op['operation_key'] );
                if ( is_wp_error( $released ) ) return $released;
            }
            return array( 'contract' => self::CONTRACT, 'state' => 'complete',
                'checkpoint_sha256' => self::digest( $back ),
                'provider_readbacks_verified' => true, 'mutation_performed' => true );

        } finally {
            if ( (string) get_option( $recovery_mutex, '' ) === $recovery_token )
                delete_option( $recovery_mutex );
        }
    }
    public static function cancel( $input = array() ) {
        if ( ! current_user_can( 'manage_options' ) || empty( $input['confirmed'] ) )
            return self::err( 'mad4b_sync_cancel_denied', 'Administrator confirmation required.' );
        $binding = self::bind( $input );
        if ( is_wp_error( $binding ) ) return $binding;
        $key = self::key( $binding, 'operation' );
        $expected = isset( $input['expected_operation_sha256'] ) ? (string) $input['expected_operation_sha256'] : '';
        if ( ! preg_match( '/^[a-f0-9]{64}$/D', $expected ) )
            return self::err( 'mad4b_sync_cancel_write_may_exist', 'Exact queued journal checksum is required.' );
        $lease_key = self::key( $binding, 'lease' );
        $cancel_token = self::digest( array( $binding['site_uuid'], $expected, 'cancel' ) );
        // Same atomic reservation used by advance. No cancel/advance TOCTOU.
        if ( ! add_option( $lease_key, array( 'operation_key' => $cancel_token, 'at' => time() ), '', false ) )
            return self::err( 'mad4b_sync_cancel_worker_active', 'Cannot cancel while an advance worker holds the lease.' );
        try {
            $op = get_option( $key, false );
            if ( ! is_array( $op ) || ! in_array( $op['state'], array( 'queued', 'needs_reconcile' ), true ) ||
                ! isset( $op['next_step'] ) || (int) $op['next_step'] !== 0 ||
                isset( $op['inflight_step'] ) || ! hash_equals( self::digest( $op ), $expected ) ||
                ( $op['site_uuid'] ?? '' ) !== $binding['site_uuid'] ||
                ( $op['authority_sha256'] ?? '' ) !== $binding['profile']['authority_sha256'] )
                return self::err( 'mad4b_sync_cancel_write_may_exist', 'Cannot cancel an uncertain, in-flight or partially applied provider write.' );
            $op['state'] = 'cancelled_without_provider_write';
            $op['cancelled_at'] = gmdate( 'c' );
            $archive_key = self::key( $binding, 'archive' ) . '_' . substr( self::digest( $op ), 0, 32 );
            if ( ! add_option( $archive_key, $op, '', false ) ||
                ! hash_equals( self::digest( $op ), self::digest( get_option( $archive_key, false ) ) ) )
                return self::err( 'mad4b_sync_cancel_archive_failed', 'Exact cancelled-operation archive could not be verified.' );
            delete_option( $key );
            if ( false !== get_option( $key, false ) )
                return self::err( 'mad4b_sync_cancel_journal_remains', 'Active journal could not be cleared; archive retained.' );
            return array( 'contract' => self::CONTRACT, 'state' => $op['state'],
                'archived' => true, 'provider_writes_executed' => 0,
                'mutation_performed' => true );
        } finally {
            $lease = get_option( $lease_key, false );
            if ( is_array( $lease ) && isset( $lease['operation_key'] ) &&
                hash_equals( (string) $lease['operation_key'], $cancel_token ) )
                delete_option( $lease_key );
        }
    }

    /** Archive only completed operations. A failed journal is never erased. */
    public static function archive( $input = array() ) {
        if ( ! current_user_can( 'manage_options' ) || empty( $input['confirmed'] ) )
            return self::err( 'mad4b_sync_archive_denied', 'Administrator confirmation required.' );
        $binding = self::bind( $input );
        if ( is_wp_error( $binding ) ) return $binding;
        $key = self::key( $binding, 'operation' );
        $op = get_option( $key, false );
        $expected = isset( $input['expected_operation_sha256'] ) ? (string) $input['expected_operation_sha256'] : '';
        if ( ! is_array( $op ) || ! isset( $op['state'] ) || 'complete' !== $op['state'] ||
            ! preg_match( '/^[a-f0-9]{64}$/D', $expected ) ||
            ! hash_equals( self::digest( $op ), $expected ) )
            return self::err( 'mad4b_sync_archive_not_exact_complete', 'Only an exactly verified completed operation may be archived.' );
        $archive_key = self::key( $binding, 'archive' ) . '_' . substr( self::digest( $op ), 0, 32 );
        if ( ! add_option( $archive_key, $op, '', false ) )
            return self::err( 'mad4b_sync_archive_already_exists', 'Operation is already archived or a duplicate.' );
        $back = get_option( $archive_key, false );
        if ( ! is_array( $back ) || ! hash_equals( self::digest( $op ), self::digest( $back ) ) )
            return self::err( 'mad4b_sync_archive_readback_failed', 'Cannot remove active record before archival readback.' );
        delete_option( $key );
        if ( false !== get_option( $key, false ) )
            return self::err( 'mad4b_sync_archive_active_record_present', 'Old active operation was not cleared.' );
        return array( 'contract' => self::CONTRACT, 'archived' => true,
            'archive_sha256' => self::digest( $back ), 'mutation_performed' => true );
    }

}

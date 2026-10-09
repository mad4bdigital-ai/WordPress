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
        foreach ( $c['sync_targets'] as $id => $target )
            if ( isset( $target['purpose'] ) && 'record_data' === $target['purpose'] )
                $sources[ $id ] = $target;
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
            $ok = update_post_meta( (int) $binding['entity_id'], $selector,
                sanitize_text_field( (string) $value ), $before );
            if ( false === $ok ) return self::err( 'mad4b_sync_wp_write_failed', 'Compare-and-set metadata write failed.' );
        }
        $after = self::read_wordpress( $source, $fields, $binding );
        if ( is_wp_error( $after ) || (string) $after['fields'][ $field ] !== sanitize_text_field( (string) $value ) )
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
                $steps[] = array( 'source' => $source, 'destination' => $dest,
                    'field' => $field, 'value_sha256' => $proposal['source_field_sha256'] );
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
        return array( 'contract' => self::CONTRACT, 'checkpoint_present' => is_array( $cp ),
            'checkpoint_sha256' => is_array( $cp ) ? self::digest( $cp ) : null,
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
            $proposal = array( 'mode' => 'bootstrap', 'site_uuid' => $binding['site_uuid'],
                'profile_slug' => $binding['profile']['slug'],
                'profile_revision' => $binding['profile']['revision'],
                'profile_authority_sha256' => $binding['profile']['authority_sha256'],
                'entity_id' => $binding['entity_id'], 'sources' => $hashes );
            return array( 'contract' => self::CONTRACT, 'mode' => 'bootstrap',
                'plan_sha256' => self::digest( $proposal ), 'sources_consistent' => $equal,
                'requires_manual_reconciliation' => !$equal, 'source_ids' => array_keys( $hashes ),
                'ready_for_apply' => $equal, 'read_only' => true, 'mutation_performed' => false );
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
            return array( 'contract' => self::CONTRACT, 'checkpoint_initialized' => true,
                'checkpoint_sha256' => self::digest( $checkpoint ), 'mutation_performed' => true );
        }
        $op = array( 'site_uuid' => $binding['site_uuid'],
            'profile_revision' => (int) $binding['profile']['revision'],
            'authority_sha256' => $binding['profile']['authority_sha256'],
            'plan_sha256' => $plan['plan_sha256'], 'operation_key' => $operation_key,
            'state' => 'queued', 'next_step' => 0, 'steps' => $plan['steps'],
            'started_at' => gmdate( 'c' ) );
        if ( ! add_option( $key, $op, '', false ) )
            return self::err( 'mad4b_sync_operation_raced', 'Another worker reserved the same sync entity.' );
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
            if ( is_wp_error( $obs ) ) { $op['state'] = 'needs_reconcile'; update_option( $key, $op, false ); return $obs; }
            $cp = self::checkpoint( $binding );
            if ( is_wp_error( $cp ) ) return $cp;
            // A separate CAS/checkpoint executor is required before claiming
            // cross-provider completion; never advance baseline from a
            // potentially partial or unverified operation.
            $op['state'] = 'awaiting_checkpoint_reconciliation';
            update_option( $key, $op, false );
            return array( 'contract' => self::CONTRACT, 'state' => $op['state'],
                'writes_applied' => $i, 'checkpoint_advanced' => false,
                'mutation_performed' => true );
        }
        $step = $op['steps'][ $i ];
        $obs = self::observe( $binding );
        if ( is_wp_error( $obs ) ) { $op['state'] = 'needs_reconcile'; update_option( $key, $op, false ); return $obs; }
        $source = $obs['observations'][ $step['source'] ];
        $dest = $obs['observations'][ $step['destination'] ];
        $field = $step['field'];
        $value = $source['fields'][ $field ];
        if ( self::digest( array( 'type' => gettype( $value ), 'value' => $value ) ) !== $step['value_sha256'] ) {
            $op['state'] = 'needs_reconcile'; update_option( $key, $op, false );
            return self::err( 'mad4b_sync_owner_field_drifted', 'Owner field changed since exact approved plan.' );
        }
        $provider = $binding['sources'][ $step['destination'] ]['provider'];
        $adapters = $obs['adapters'];
        $adapter = $adapters[ $provider ];
        if ( empty( $adapter['conditional_write'] ) || empty( $adapter['readback'] ) ||
            ! isset( $adapter['write'] ) || ! is_callable( $adapter['write'] ) )
            return self::err( 'mad4b_sync_writer_not_verified', 'Provider is not certified for conditional writes.' );
        $op['state'] = 'step_inflight'; $op['inflight_step'] = $i;
        update_option( $key, $op, false );
        $written = call_user_func( $adapter['write'], $binding['sources'][ $step['destination'] ],
            $field, $value, $dest, $binding );
        if ( is_wp_error( $written ) ) {
            $op['state'] = 'needs_reconcile'; update_option( $key, $op, false );
            return $written;
        }
        $readback = call_user_func( $adapter['read'], $binding['sources'][ $step['destination'] ],
            array_values( $binding['sources'][ $step['destination'] ]['field_keys'] ), $binding );
        if ( is_wp_error( $readback ) || ! isset( $readback['fields'][ $field ] ) ||
            (string) $readback['fields'][ $field ] !== (string) $value ) {
            $op['state'] = 'needs_reconcile'; update_option( $key, $op, false );
            return self::err( 'mad4b_sync_provider_readback_uncertain', 'Provider write outcome has not passed exact readback.' );
        }
        $op['next_step'] = $i + 1; unset( $op['inflight_step'] ); $op['state'] = 'running';
        update_option( $key, $op, false ); delete_option( $mutex );
        return array( 'contract' => self::CONTRACT, 'state' => 'running',
            'completed_steps' => $op['next_step'], 'step_count' => count( $op['steps'] ),
            'readback_verified' => true, 'mutation_performed' => true );
    }
}

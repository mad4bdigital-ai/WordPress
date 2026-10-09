<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Multi-source field-level, three-way reconciliation proposal.
 *
 * Externally supplied observations/checkpoints are untrusted planning
 * evidence. This class does NOT assert their Drive origin, authenticate
 * providers, persist a checkpoint or authorize a WordPress/Drive write.
 */
final class MAD4B_SCP_Activity_Source_Reconciliation {
    const CONTRACT = 'mad4b.activity-source-reconciliation.v1';
    const MAX_SOURCES = 13; // WordPress plus twelve site-configured sources.
    const MAX_FIELDS = 40;
    const MAX_VALUE_BYTES = 4000;
    const MAX_SNAPSHOT_AGE = 86400;

    private static function fail( $code, $msg ) { return new WP_Error( $code, $msg ); }
    private static function digest( $value ) {
        $encoded = wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        return is_string( $encoded ) ? hash( 'sha256', $encoded ) : '';
    }
    private static function valid_revision( $revision ) {
        return is_string( $revision ) && 1 === preg_match( '/^[A-Za-z0-9._:-]{1,160}$/D', $revision );
    }
    private static function ref_ok( $ref ) {
        return is_string( $ref ) && 1 === preg_match( '/^[A-Za-z0-9._:-]{1,180}$/D', $ref );
    }
    private static function has_valid_observation( $item, $max_age ) {
        if ( ! is_array( $item ) || array_diff( array_keys( $item ),
            array( 'resource_id', 'revision', 'observed_at', 'state', 'fields' ) ) ) return false;
        if ( empty( $item['resource_id'] ) || ! self::ref_ok( $item['resource_id'] ) ||
            empty( $item['revision'] ) || ! self::valid_revision( $item['revision'] ) ||
            empty( $item['observed_at'] ) || ! is_string( $item['observed_at'] ) ||
            ! preg_match( '/^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}(?:\\.\\d+)?(?:Z|[+-]\\d{2}:\\d{2})$/', $item['observed_at'] ) ) return false;
        $time = strtotime( $item['observed_at'] );
        if ( false === $time || $time > time() + 60 || time() - $time > $max_age ) return false;
        if ( ! isset( $item['state'] ) || ! in_array( $item['state'], array( 'present', 'missing', 'deleted' ), true ) ||
            ! isset( $item['fields'] ) || ! is_array( $item['fields'] ) ||
            count( $item['fields'] ) > self::MAX_FIELDS ) return false;
        return true;
    }
    private static function normalize_value_hashes( $values, $allowed ) {
        $out = array();
        foreach ( $values as $field => $value ) {
            if ( ! is_string( $field ) || ! in_array( $field, $allowed, true ) ||
                ! is_scalar( $value ) || strlen( (string) $value ) > self::MAX_VALUE_BYTES )
                return self::fail( 'mad4b_activity_source_field_invalid', 'Source field is unknown or exceeds bounded primitive limits.' );
            $hash = self::digest( array( 'type' => gettype( $value ), 'value' => $value ) );
            if ( ! $hash ) return self::fail( 'mad4b_activity_source_digest_failed', 'Field snapshot could not be encoded.' );
            $out[ $field ] = $hash;
        }
        ksort( $out, SORT_STRING );
        return $out;
    }

    /**
     * Rules/assets/reference sources are not row data. Compare the editorial
     * source revisions used to draft one WordPress entity with the current
     * resource revisions, then queue review rather than rewriting content.
     */
    public static function context_impact_plan( $input = array() ) {
        $input = is_array( $input ) ? $input : array();
        if ( ! current_user_can( 'list_users' ) || ! class_exists( 'MAD4B_SCP_Content_Experience_Profiles' ) ||
            ! class_exists( 'MAD4B_SCP_Site_Profile' ) ||
            ! MAD4B_SCP_Site_Profile::configured() ||
            ! MAD4B_SCP_Site_Profile::origin_enrolled() ||
            ! MAD4B_SCP_Site_Profile::site_urls_match_enrollment() )
            return self::fail( 'mad4b_activity_context_site_denied', 'Context impact comparison needs exact enrolled site access.' );
        $slug = isset( $input['profile_slug'] ) ? (string) $input['profile_slug'] : '';
        $profile = MAD4B_SCP_Content_Experience_Profiles::profile( $slug );
        if ( is_wp_error( $profile ) ) return $profile;
        if ( empty( $profile['activity_contract']['enabled'] ) )
            return self::fail( 'mad4b_activity_context_not_configured', 'Business activity contract must be enabled.' );
        $targets = isset( $profile['activity_contract']['sync_targets'] ) ? $profile['activity_contract']['sync_targets'] : array();
        $current = isset( $input['current_revisions'] ) ? $input['current_revisions'] : null;
        $used = isset( $input['used_revisions'] ) ? $input['used_revisions'] : null;
        if ( ! is_array( $current ) || ! is_array( $used ) || count( $current ) > 12 || count( $used ) > 12 )
            return self::fail( 'mad4b_activity_context_versions_invalid', 'Bounded current and previously used revisions required.' );
        $context = array();
        foreach ( $targets as $id => $target ) {
            if ( ! is_array( $target ) || ! isset( $target['purpose'] ) ||
                'record_data' === $target['purpose'] ) continue;
            $context[ $id ] = $target;
        }
        if ( array_diff( array_keys( $current ), array_keys( $context ) ) ||
            array_diff( array_keys( $used ), array_keys( $context ) ) )
            return self::fail( 'mad4b_activity_context_source_unconfigured', 'Context includes an unconfigured source.' );
        $impact = array(); $review = false;
        foreach ( $context as $id => $target ) {
            $ref = isset( $target['source_ref'] ) ? (string) $target['source_ref'] : '';
            $now = isset( $current[ $id ] ) && is_array( $current[ $id ] ) ? $current[ $id ] : array();
            $old = isset( $used[ $id ] ) && is_array( $used[ $id ] ) ? $used[ $id ] : array();
            $matching = '' !== $ref && isset( $now['resource_id'], $old['resource_id'] ) &&
                $now['resource_id'] === $ref && $old['resource_id'] === $ref &&
                isset( $now['revision'], $old['revision'] ) &&
                self::valid_revision( $now['revision'] ) && self::valid_revision( $old['revision'] );
            if ( ! $matching || ! isset( $now['observed_at'] ) ||
                ! is_string( $now['observed_at'] ) || ! preg_match( '/^\\d{4}-\\d{2}-\\d{2}T/', $now['observed_at'] ) ||
                false === strtotime( $now['observed_at'] ) ||
                strtotime( $now['observed_at'] ) > time() + 60 ||
                time() - strtotime( $now['observed_at'] ) > 3600 ) {
                $impact[] = array( 'source_id' => $id, 'purpose' => $target['purpose'],
                    'status' => 'source_unverified_or_stale', 'action' => 'fresh_provider_readback_and_review' );
                $review = true;
                continue;
            }
            $changed = ! hash_equals( $now['revision'], $old['revision'] );
            $impact[] = array( 'source_id' => $id, 'purpose' => $target['purpose'],
                'status' => $changed ? 'source_revision_changed' : 'same_source_revision',
                'action' => $changed ? 'evaluate_draft_impact_and_reapprove' : 'no_new_impact_detected' );
            if ( $changed ) $review = true;
        }
        $intent = array( 'site_uuid' => MAD4B_SCP_Site_Profile::site_uuid(),
            'profile_slug' => $slug, 'profile_revision' => $profile['revision'],
            'profile_authority_sha256' => $profile['authority_sha256'], 'impact' => $impact );
        return array( 'contract' => 'mad4b.activity-context-impact.v1',
            'profile_slug' => $slug, 'comparison_sha256' => self::digest( $intent ),
            'impact' => $impact, 'review_required' => $review,
            'source_revisions_cryptographically_verified' => false,
            'update_authorized' => false, 'publication_authorized' => false,
            'read_only' => true, 'mutation_performed' => false );
    }

    public static function plan( $input = array() ) {
        $input = is_array( $input ) ? $input : array();
        if ( ! current_user_can( 'list_users' ) )
            return self::fail( 'mad4b_activity_reconcile_access_denied', 'Scoped Business Activity inspection capability is required.' );
        $slug = isset( $input['profile_slug'] ) ? (string) $input['profile_slug'] : '';
        if ( ! class_exists( 'MAD4B_SCP_Content_Experience_Profiles' ) ||
            ! class_exists( 'MAD4B_SCP_Site_Profile' ) ||
            ! MAD4B_SCP_Site_Profile::configured() ||
            ! MAD4B_SCP_Site_Profile::origin_enrolled() ||
            ! MAD4B_SCP_Site_Profile::site_urls_match_enrollment() )
            return self::fail( 'mad4b_activity_reconcile_site_unbound', 'Exact enrolled site is required.' );
        $profile = MAD4B_SCP_Content_Experience_Profiles::profile( $slug );
        if ( is_wp_error( $profile ) ) return $profile;
        $contract = isset( $profile['activity_contract'] ) && is_array( $profile['activity_contract'] )
            ? $profile['activity_contract'] : array();
        if ( empty( $profile['enabled'] ) || empty( $contract['enabled'] ) )
            return self::fail( 'mad4b_activity_reconcile_profile_disabled', 'Activity reconciliation requires an enabled site-owned profile.' );
        $site_uuid = (string) MAD4B_SCP_Site_Profile::site_uuid();
        $entity_id = isset( $input['entity_id'] ) ? (string) $input['entity_id'] : '';
        $identity = isset( $contract['sync_identity_key'] ) ? $contract['sync_identity_key'] : 'post_id';
        if ( ! self::ref_ok( $entity_id ) || ( 'post_id' === $identity &&
            ( ! ctype_digit( $entity_id ) || (int) $entity_id < 1 ) ) )
            return self::fail( 'mad4b_activity_reconcile_identity_invalid', 'Use a stable entity ID according to configured profile identity policy.' );
        if ( 'post_id' === $identity &&
            ( ! function_exists( 'get_post_type' ) || get_post_type( (int) $entity_id ) !== $profile['post_type'] ||
                ! current_user_can( 'edit_post', (int) $entity_id ) ) )
            return self::fail( 'mad4b_activity_reconcile_cpt_identity_mismatch', 'WordPress entity does not match site profile CPT/capabilities.' );
        $targets = isset( $contract['sync_targets'] ) ? $contract['sync_targets'] : array();
        $fields = isset( $contract['attribute_meta_keys'] ) ? $contract['attribute_meta_keys'] : array();
        $owners = isset( $contract['field_owners'] ) ? $contract['field_owners'] : array();
        if ( ! is_array( $fields ) || count( $fields ) > self::MAX_FIELDS || ! is_array( $targets ) || count( $targets ) > 12 )
            return self::fail( 'mad4b_activity_reconcile_contract_invalid', 'The effective site profile has invalid source/field limits.' );
        $observed = isset( $input['observations'] ) ? $input['observations'] : null;
        $baseline = isset( $input['baseline'] ) ? $input['baseline'] : null;
        if ( ! is_array( $observed ) || ! is_array( $baseline ) ||
            count( $observed ) > self::MAX_SOURCES || count( $baseline ) > self::MAX_SOURCES )
            return self::fail( 'mad4b_activity_reconcile_evidence_missing', 'Bounded observations and a previous baseline are required.' );
        $sources = array( 'wordpress' => array( 'field_keys' => $fields, 'direction' => 'bidirectional', 'provider' => 'wordpress' ) );
        $context_sources = array();
        foreach ( $targets as $id => $t ) {
            if ( is_array( $t ) && isset( $t['purpose'] ) && 'record_data' !== $t['purpose'] ) {
                $context_sources[ $id ] = array( 'provider' => isset( $t['provider'] ) ? $t['provider'] : '',
                    'source_ref' => isset( $t['source_ref'] ) ? $t['source_ref'] : '',
                    'purpose' => $t['purpose'],
                    'requires_policy_impact_review' => 'editorial_policy' === $t['purpose'] );
                continue;
            }
            if ( ! is_array( $t ) || ! isset( $t['field_keys'] ) || ! is_array( $t['field_keys'] ) ||
                isset( $sources[ $id ] ) )
                return self::fail( 'mad4b_activity_reconcile_source_invalid', 'Source IDs and fields must be unique and configured.' );
            $sources[ $id ] = $t;
        }
        if ( array_diff( array_keys( $observed ), array_keys( $sources ) ) ||
            array_diff( array_keys( $baseline ), array_keys( $sources ) ) )
            return self::fail( 'mad4b_activity_reconcile_source_unconfigured', 'Snapshots reference an unconfigured source.' );
        $max_age = isset( $input['max_snapshot_age_seconds'] ) ? (int) $input['max_snapshot_age_seconds'] : 3600;
        if ( $max_age < 60 || $max_age > self::MAX_SNAPSHOT_AGE )
            return self::fail( 'mad4b_activity_reconcile_freshness_invalid', 'Snapshot freshness must be within supported limits.' );
        $snapshots = array(); $checkpoints = array(); $errors = array(); $seen_resource_ids = array();
        // Every source participating in a profile requires a snapshot and a
        // checkpoint. Never treat absent data as an empty/deleted record.
        foreach ( $sources as $id => $t ) {
            if ( ! isset( $observed[ $id ] ) || ! self::has_valid_observation( $observed[ $id ], $max_age ) ) {
                $errors[] = array( 'source' => $id, 'code' => 'snapshot_missing_or_stale' );
                continue;
            }
            $item = $observed[ $id ];
            $expected_resource = 'wordpress' === $id && 'post_id' === $identity
                ? $entity_id : ( isset( $t['source_ref'] ) ? (string) $t['source_ref'] : '' );
            if ( '' !== $expected_resource && $item['resource_id'] !== $expected_resource ) {
                $errors[] = array( 'source' => $id, 'code' => 'configured_source_resource_mismatch' );
                continue;
            }
            $resource_domain = isset( $t['provider'] ) ? (string) $t['provider'] : 'wordpress';
            $resource_key = $resource_domain . ':' . $item['resource_id'];
            if ( isset( $seen_resource_ids[ $resource_key ] ) ) {
                $errors[] = array( 'source' => $id, 'code' => 'source_resource_duplicate',
                    'other_source' => $seen_resource_ids[ $resource_key ] );
                continue;
            }
            $seen_resource_ids[ $resource_key ] = $id;
            if ( 'present' !== $item['state'] ) {
                $errors[] = array( 'source' => $id, 'code' => 'missing_or_deleted_requires_reconciliation' );
                continue;
            }
            $allowed = array_values( array_intersect( $fields, $t['field_keys'] ) );
            $hashes = self::normalize_value_hashes( $item['fields'], $allowed );
            if ( is_wp_error( $hashes ) ) return $hashes;
            if ( ! isset( $baseline[ $id ] ) || ! is_array( $baseline[ $id ] ) ) {
                $errors[] = array( 'source' => $id, 'code' => 'checkpoint_missing' );
                continue;
            }
            $record = $baseline[ $id ];
            if ( array_diff( array_keys( $record ), array( 'resource_id', 'revision', 'field_hashes' ) ) ||
                ! isset( $record['resource_id'], $record['revision'], $record['field_hashes'] ) ||
                ! self::ref_ok( $record['resource_id'] ) || ! self::valid_revision( $record['revision'] ) ||
                ! is_array( $record['field_hashes'] ) ||
                $record['resource_id'] !== $item['resource_id'] ) {
                $errors[] = array( 'source' => $id, 'code' => 'checkpoint_identity_mismatch' );
                continue;
            }
            if ( array_diff( array_keys( $record['field_hashes'] ), $allowed ) ) {
                $errors[] = array( 'source' => $id, 'code' => 'checkpoint_field_scope_invalid' );
                continue;
            }
            foreach ( $record['field_hashes'] as $hash ) {
                if ( ! is_string( $hash ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $hash ) )
                    return self::fail( 'mad4b_activity_reconcile_checkpoint_hash_invalid', 'Checkpoint field hash is malformed.' );
            }
            if ( array_diff( array_keys( $hashes ), array_keys( $record['field_hashes'] ) ) ||
                array_diff( array_keys( $record['field_hashes'] ), array_keys( $hashes ) ) ) {
                $errors[] = array( 'source' => $id, 'code' => 'checkpoint_coverage_incomplete' );
                continue;
            }
            $snapshots[ $id ] = array( 'revision' => $item['revision'], 'resource_id' => $item['resource_id'],
                'field_hashes' => $hashes );
            $checkpoints[ $id ] = $record;
        }
        $items = array(); $conflicts = $errors; $proposals = array();
        if ( ! $errors ) foreach ( $fields as $field ) {
            $involved = array();
            foreach ( $sources as $id => $t ) {
                if ( ! in_array( $field, $t['field_keys'], true ) ) continue;
                $involved[] = $id;
            }
            $owner = isset( $owners[ $field ] ) ? $owners[ $field ] : 'manual_review';
            if ( ! in_array( $owner, array_merge( $involved, array( 'manual_review' ) ), true ) ) {
                $conflicts[] = array( 'field' => $field, 'code' => 'configured_owner_not_participating' );
                continue;
            }
            $changed = array(); $values = array();
            foreach ( $involved as $id ) {
                $current = $snapshots[ $id ]['field_hashes'][ $field ];
                $prev = $checkpoints[ $id ]['field_hashes'][ $field ];
                $values[ $id ] = $current;
                if ( ! hash_equals( $current, $prev ) ) $changed[] = $id;
            }
            $previous_hashes = array();
            foreach ( $involved as $id ) $previous_hashes[] = $checkpoints[ $id ]['field_hashes'][ $field ];
            if ( count( array_unique( $previous_hashes ) ) > 1 ) {
                $conflicts[] = array( 'field' => $field, 'code' => 'baseline_already_divergent' );
                continue;
            }
            if ( ! $changed ) {
                if ( count( array_unique( array_values( $values ) ) ) > 1 )
                    $conflicts[] = array( 'field' => $field, 'code' => 'preexisting_divergence' );
                else $items[] = array( 'field' => $field, 'state' => 'in_sync' );
                continue;
            }
            if ( 'manual_review' === $owner || count( $changed ) > 1 ||
                ! in_array( $owner, $changed, true ) ) {
                $code = 'manual_review' === $owner ? 'manual_field_owner' :
                    ( count( $changed ) > 1 ? 'concurrent_source_edit' : 'non_owner_changed' );
                $conflicts[] = array( 'field' => $field, 'code' => $code, 'changed_sources' => $changed );
                continue;
            }
            // When a canonical source alone changed, propose a per-target
            // refresh, but DO NOT assume the provider supports that mutation.
            $recipients = array();
            foreach ( $involved as $id ) if ( $owner !== $id ) {
                $direction = $sources[ $id ]['direction'];
                if ( 'wordpress' === $id || in_array( $direction, array( 'export', 'bidirectional' ), true ) )
                    $recipients[] = $id;
                else $conflicts[] = array( 'field' => $field, 'code' => 'destination_not_writable', 'source' => $id );
            }
            $proposal = array( 'field' => $field, 'source_id' => $owner,
                'destination_ids' => $recipients, 'source_field_sha256' => $values[ $owner ],
                'action' => 'propose_copy_after_verified_provider_readback' );
            $proposals[] = $proposal;
            $items[] = array( 'field' => $field, 'state' => 'owner_changed', 'source' => $owner );
        }
        $intent = array(
            'contract' => self::CONTRACT, 'site_uuid' => $site_uuid,
            'profile_slug' => $profile['slug'], 'post_type' => $profile['post_type'],
            'profile_revision' => $profile['revision'], 'profile_authority_sha256' => $profile['authority_sha256'],
            'entity_id' => $entity_id, 'identity_strategy' => $identity,
            'snapshots' => $snapshots, 'checkpoints' => $checkpoints,
            'field_owners' => $owners, 'context_sources' => $context_sources,
            'field_results' => $items,
            'conflicts' => $conflicts, 'copy_proposals' => $proposals,
        );
        $digest = self::digest( $intent );
        if ( '' === $digest ) return self::fail( 'mad4b_activity_reconcile_plan_encoding_failed', 'Reconciliation plan serialization failed.' );
        return array( 'contract' => self::CONTRACT,
            'site_uuid' => $site_uuid, 'profile_slug' => $profile['slug'],
            'entity_id' => $entity_id, 'plan_sha256' => $digest,
            'sources_expected' => array_keys( $sources ),
            'separate_context_sources' => $context_sources,
            'context_policy_impact_not_assumed' => true,
            'source_revisions' => array_map( static function ( $row ) { return $row['revision']; }, $snapshots ),
            'field_results' => $items, 'conflicts' => $conflicts,
            'copy_proposals' => $proposals,
            'has_conflicts' => ! empty( $conflicts ),
            'client_supplied_evidence_trusted' => false,
            'checkpoint_authoritatively_persisted' => false,
            'provider_receipts_independently_verified' => false,
            'ready_for_automatic_apply' => false,
            'dry_run' => true, 'read_only' => true, 'mutation_performed' => false,
            'next_actions' => array( 'fetch_provider_authenticated_snapshots',
                'compare_with_last_accepted_persisted_checkpoint',
                'review_conflicts_and_independent_authority_per_field',
                'apply_exact_write_to_supported_provider_with_cas',
                'verify_both_provider_readbacks_then_advance_checkpoint' ) );
    }
}

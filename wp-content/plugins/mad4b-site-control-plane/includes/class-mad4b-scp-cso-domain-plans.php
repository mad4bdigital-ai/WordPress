<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Typed native proposals. No boot hooks, writes or release application. */
final class MAD4B_SCP_CSO_Domain_Plans {
    const CONTRACT = 'mad4b.cso01.domain-plan.v1';
    const TTL = 300;
    const MAX_BYTES = 131072;
    const MAX_SITES = 20;

    public static function error( $reason ) {
        return new WP_Error( 'mad4b_cso_domain_' . $reason, 'Current typed native contracts and independently observed evidence are required.', array( 'reason' => $reason, 'authorizing' => false, 'mutation_performed' => false ) );
    }
    public static function sha( $value ) { return is_string( $value ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $value ); }
    public static function slug( $value ) { return is_string( $value ) && 1 === preg_match( '/^[a-zA-Z0-9_-]{1,64}$/D', $value ); }
    public static function select( array $data, array $keys ) { return array_intersect_key( $data, array_flip( $keys ) ); }

    public static function context( array $input, $flag = 'operations' ) {
        if ( ! class_exists( 'MAD4B_SCP_CSO_Scope' ) || ! class_exists( 'MAD4B_SCP_CSO_Registry' ) ) return self::error( 'foundation_unavailable' );
        if ( ! MAD4B_SCP_CSO_Scope::enabled( $flag ) ) return self::error( 'rollout_disabled' );
        if ( ! class_exists( 'MAD4B_SCP_Policy' ) || true !== MAD4B_SCP_Policy::can_read() ) return self::error( 'read_permission_required' );
        if ( ! is_array( $input['scope'] ?? null ) ) return self::error( 'scope_required' );
        $encoded = wp_json_encode( $input );
        if ( ! is_string( $encoded ) || strlen( $encoded ) > self::MAX_BYTES ) return self::error( 'input_budget_exceeded' );
        $safe = MAD4B_SCP_CSO_Scope::safe_data( $input );
        if ( true !== $safe ) return is_wp_error( $safe ) ? $safe : self::error( 'unsafe_input_denied' );
        $scope = MAD4B_SCP_CSO_Scope::current(); if ( is_wp_error( $scope ) ) return $scope;
        $same = MAD4B_SCP_CSO_Scope::assert_current( $input['scope'] );
        return is_wp_error( $same ) ? $same : $scope;
    }

    /** Mandatory preparation is checked by the original MAD4B read dispatcher. */
    public static function read( $name, array $params, array $preparation ) {
        $descriptor = MAD4B_SCP_CSO_Registry::describe( $name, $params );
        if ( is_wp_error( $descriptor ) ) return $descriptor;
        if ( true !== ( $descriptor['read_only'] ?? $descriptor['readonly'] ?? null ) || 'read' !== ( $descriptor['lane'] ?? null ) ) return self::error( 'native_read_contract_required' );
        $result = MAD4B_SCP_CSO_Registry::read( $name, $params, $preparation );
        if ( is_wp_error( $result ) ) return $result;
        if ( is_array( $result ) && 'mad4b.cso01.native-read.v1' === ( $result['contract'] ?? null ) ) {
            if ( true !== ( $result['read_only'] ?? null ) || true === ( $result['mutation_performed'] ?? false ) || true === ( $result['authorizing'] ?? false ) ) return self::error( 'native_read_output_invalid' );
            $result = $result['result'] ?? null;
        }
        if ( is_array( $result ) && 'mad4b.chatgpt-read-execute.v1' === ( $result['contract'] ?? null ) ) {
            if ( true === ( $result['mutation_performed'] ?? false ) || true === ( $result['authorizing'] ?? false ) ) return self::error( 'native_read_output_invalid' );
            $result = $result['result'] ?? null;
        }
        if ( ! is_array( $result ) || true === ( $result['mutation_performed'] ?? false ) || true === ( $result['authorizing'] ?? false ) ) return self::error( 'native_read_output_invalid' );
        return $result;
    }
    public static function descriptor_summary( array $descriptor ) {
        return self::select( $descriptor, array( 'ability_name', 'schema_sha256', 'adapter_sha256', 'capability_binding', 'lane', 'read_only', 'readonly', 'provider', 'storage_kind', 'native_route_available', 'storage', 'storage_status', 'certification_status', 'cso_conformance_certified' ) );
    }
    public static function field_summary( array $values ) {
        $out = array();
        foreach ( $values as $key => $value ) $out[] = array( 'field' => (string) $key, 'type' => is_array( $value ) ? 'array_or_object' : gettype( $value ), 'value_disclosed' => false );
        usort( $out, static function( $a, $b ) { return strcmp( $a['field'], $b['field'] ); } );
        return $out;
    }
    public static function finish( $family, array $scope, array $facts, array $blockers = array() ) {
        $same = MAD4B_SCP_CSO_Scope::assert_current( $scope ); if ( is_wp_error( $same ) ) return $same;
        $blockers = array_values( array_unique( $blockers ) ); sort( $blockers, SORT_STRING ); $now = time();
        $plan = array( 'contract' => self::CONTRACT, 'operation_family' => $family, 'scope' => $scope,
            'issued_at' => $now, 'expires_at' => $now + self::TTL, 'status' => $blockers ? 'BLOCKED' : 'PLANNED',
            'proposal_ready' => ! $blockers, 'blockers' => $blockers, 'facts' => $facts, 'read_only' => true,
            'authorizing' => false, 'execution_supported' => false, 'mutation_performed' => false,
            'production_authorized' => false, 'capability_grants_created' => false );
        $plan['plan_sha256'] = MAD4B_SCP_CSO_Scope::digest( $plan ); if ( is_wp_error( $plan['plan_sha256'] ) ) return $plan['plan_sha256'];
        // Pure integrity helper: flags, scope and expiry above are explicit service-owned fields.
        $sealed = MAD4B_SCP_CSO_Scope::seal( $plan, 'mad4b.cso01.domain-plan.' . $family );
        return is_wp_error( $sealed ) ? $sealed : array_merge( $plan, array( 'sealed_plan' => $sealed ) );
    }

    /** Actual profile planners own media rights, SEO helpers, WPML, revision and publish guards. */
    public static function content_plan( array $input ) {
        $scope = self::context( $input, 'forms' ); if ( is_wp_error( $scope ) ) return $scope;
        $name = $input['capability'] ?? ''; $target = $input['target'] ?? null; $values = $input['values'] ?? array();
        if ( ! is_string( $name ) || ! is_array( $values ) || ! is_array( $target ) || 'post' !== ( $target['kind'] ?? null ) || ! self::slug( $target['post_type'] ?? null ) || ! is_int( $target['id'] ?? null ) || $target['id'] < 0 || ! is_string( $target['expected_revision'] ?? null ) || strlen( $target['expected_revision'] ) > 191 ) return self::error( 'content_target_invalid' );
        $target = self::select( $target, array( 'kind', 'id', 'post_type', 'expected_revision' ) );
        $descriptor = MAD4B_SCP_CSO_Registry::describe( $name, $values ); if ( is_wp_error( $descriptor ) ) return $descriptor;
        $valid = self::validate_values( $values, $descriptor['input_schema'] ?? null ); if ( is_wp_error( $valid ) ) return $valid;
        if ( ! class_exists( 'MAD4B_SCP_Content_Experience_Profiles' ) ) return self::error( 'content_native_profile_required' );
        $profile = MAD4B_SCP_Content_Experience_Profiles::profile_for_ability( $name );
        if ( is_wp_error( $profile ) || ! is_array( $profile ) ) return self::error( 'content_native_profile_required' );
        $routes = MAD4B_SCP_Content_Experience_Profiles::profile_routes( $profile['slug'], $profile['revision'] );
        $phase = array_search( $name, array_intersect_key( $routes, array_flip( array( 'create_plan', 'update_plan', 'publish_plan' ) ) ), true );
        if ( false === $phase || true !== ( $descriptor['read_only'] ?? $descriptor['readonly'] ?? null ) ) return self::error( 'content_native_plan_required' );
        if ( ( $profile['post_type'] ?? null ) !== $target['post_type'] ) return self::error( 'content_post_type_mismatch' );
        if ( 'create_plan' === $phase && 0 !== $target['id'] ) return self::error( 'content_create_target_invalid' );
        if ( 'create_plan' !== $phase && ( $target['id'] < 1 || '' === $target['expected_revision'] || ( $values['post_id'] ?? null ) !== $target['id'] || ( $values['expected_modified_gmt'] ?? null ) !== $target['expected_revision'] ) ) return self::error( 'content_revision_binding_mismatch' );
        if ( ! is_array( $input['preparation'] ?? null ) ) return self::error( 'native_preparation_required' );
        $native = self::read( $name, $values, $input['preparation'] ); if ( is_wp_error( $native ) ) return $native;
        if ( 'mad4b.content-experience-operation-plan.v1' !== ( $native['contract'] ?? null ) || ! self::sha( $native['plan_sha256'] ?? null ) || ( $native['profile_slug'] ?? null ) !== $profile['slug'] || ( $native['profile_revision'] ?? null ) !== $profile['revision'] || ( $native['operation'] ?? null ) !== str_replace( '_plan', '', $phase ) || ( $native['post_type'] ?? null ) !== $target['post_type'] ) return self::error( 'content_plan_provenance_invalid' );
        $media = array();
        foreach ( array( 'media_publish_rights', 'remote_media_provenance_rights', 'remote_media_manifest_receipt' ) as $key ) if ( is_array( $native[ $key ] ?? null ) ) $media[ $key ] = self::select( $native[ $key ], array( 'contract', 'valid', 'ready', 'state', 'checked_field_count', 'checked_item_count', 'checked_attachment_count', 'remote_attachment_count', 'nearest_expiry', 'evaluated_on' ) );
        return self::finish( 'content_plan', $scope, array( 'target' => $target, 'descriptor' => self::descriptor_summary( $descriptor ),
            'native_plan_sha256' => $native['plan_sha256'], 'profile_slug' => $profile['slug'], 'profile_revision' => $profile['revision'],
            'operation' => $native['operation'] ?? '', 'fields' => self::field_summary( $values ), 'media' => $media,
            'taxonomy_count' => count( is_array( $values['taxonomies'] ?? null ) ? $values['taxonomies'] : array() ),
            'helper_ids' => array_keys( is_array( $values['helpers'] ?? null ) ? $values['helpers'] : array() ),
            'publication_guards_enforced_by' => $name, 'independent_verifier' => $routes['verify'] ?? '',
            'apply_capability' => $routes[ str_replace( '_plan', '_apply', $phase ) ] ?? '',
            'per_step_approval_required' => true, 'global_transaction' => false ) );
    }

    /** Observe provider business semantics; never infer a write route from an object ID. */
    public static function object_contract( array $input ) {
        $scope = self::context( $input, 'forms' ); if ( is_wp_error( $scope ) ) return $scope;
        $target = $input['target'] ?? null; $name = $input['capability'] ?? ''; $values = $input['values'] ?? array();
        if ( ! is_string( $name ) || ! is_array( $values ) || ! is_array( $target ) || ! self::slug( $target['type'] ?? null ) || ! is_int( $target['id'] ?? null ) || $target['id'] < 0 || ! is_string( $target['expected_revision'] ?? null ) || '' === $target['expected_revision'] || strlen( $target['expected_revision'] ) > 191 ) return self::error( 'object_target_invalid' );
        $target = self::select( $target, array( 'type', 'id', 'expected_revision' ) );
        $descriptor = MAD4B_SCP_CSO_Registry::describe( $name, $values ); if ( is_wp_error( $descriptor ) ) return $descriptor;
        $valid = self::validate_values( $values, $descriptor['input_schema'] ?? null ); if ( is_wp_error( $valid ) ) return $valid;
        $provider = $descriptor['provider'] ?? array(); $storage = $descriptor['storage'] ?? array(); $blockers = array();
        $provider_id = $provider['provider_id'] ?? $provider['id'] ?? null;
        if ( ! self::sha( $provider['certification_generation_sha256'] ?? $provider['certification_sha256'] ?? null ) ) $blockers[] = 'current_provider_certification_descriptor_required';
        if ( 'native_ability' !== ( $descriptor['storage_kind'] ?? $storage['kind'] ?? null ) || true !== ( $descriptor['native_route_available'] ?? $storage['native_route_available'] ?? null ) ) $blockers[] = 'native_provider_route_required';
        if ( 'WRITE_CANDIDATE' !== ( $descriptor['storage_status'] ?? null ) ) $blockers[] = 'native_business_write_route_unavailable';
        $cert = class_exists( 'MAD4B_SCP_Provider_Compatibility_Certification' ) && method_exists( 'MAD4B_SCP_Provider_Compatibility_Certification', 'ability_status' ) && is_string( $provider_id ) ? MAD4B_SCP_Provider_Compatibility_Certification::ability_status( $provider_id, $name ) : array();
        $traits = class_exists( 'MAD4B_SCP_Capability_Traits' ) && is_string( $cert['capability_id'] ?? null ) ? MAD4B_SCP_Capability_Traits::profile( $provider_id, $cert['capability_id'] ) : array();
        $business = ! is_wp_error( $traits ) && is_array( $traits ) && true === ( $traits['descriptor_binding_ready'] ?? null ) && in_array( $name, is_array( $traits['ability_names'] ?? null ) ? $traits['ability_names'] : array(), true ) && 'mad4b_adapter_semantics' === ( $traits['traits']['traits_scope'] ?? null ) && true === ( $cert['structural_compatible'] ?? null ) && true === ( $cert['artifact_authority_bound'] ?? null ) && true === ( $cert['write_eligible'] ?? null );
        if ( ! $business ) $blockers[] = 'native_business_validation_and_side_effect_contract_required';
        $revision_verified = false;
        if ( 'woocommerce/update-product' === $name ) {
            if ( 'product' !== $target['type'] || $target['id'] < 1 || ( $values['product_id'] ?? null ) !== $target['id'] || ( $values['expected_sha256'] ?? null ) !== $target['expected_revision'] ) return self::error( 'commerce_target_binding_mismatch' );
            if ( is_array( $input['read_preparation'] ?? null ) ) {
                $observed = self::read( 'woocommerce/get-product', array( 'product_id' => $target['id'] ), $input['read_preparation'] ); if ( is_wp_error( $observed ) ) return $observed;
                $revision_verified = self::sha( $observed['sha256'] ?? null ) && hash_equals( $target['expected_revision'], $observed['sha256'] ) && ( $observed['product']['id'] ?? null ) === $target['id'];
                if ( ! $revision_verified ) return self::error( 'commerce_object_revision_drift' );
            }
        }
        if ( ! $revision_verified ) $blockers[] = 'independent_native_object_revision_read_required';
        if ( true !== ( $storage['write_supported'] ?? null ) ) $blockers[] = 'certified_native_postcondition_route_required';
        $risky = in_array( $target['type'], array( 'order', 'payment', 'refund', 'subscription', 'shop_order', 'shop_subscription' ), true );
        if ( $risky ) $blockers[] = 'distinct_commerce_effect_policy_and_owner_approval_required';
        return self::finish( 'object_contract', $scope, array( 'target' => $target, 'descriptor' => self::descriptor_summary( $descriptor ),
            'fields' => self::field_summary( $values ), 'business_contract_observed' => $business, 'target_revision_verified' => $revision_verified,
            'native_business_validation_required_at_commit' => true, 'side_effects' => $risky ? array( 'payment', 'inventory', 'emails', 'webhooks', 'tax' ) : array( 'native_hooks', 'cache', 'relations' ),
            'arbitrary_meta_or_sql_allowed' => false, 'global_transaction' => false,
            'next_step' => $blockers ? 'certify_typed_native_object_and_readback_contract' : 'prepare_original_native_business_ability_and_separate_approval' ), $blockers );
    }

    /** A coordinator's MAC is never a signature or grant for a foreign site. */
    public static function multisite_plan( array $input ) {
        $scope = self::context( $input, 'multisite' ); if ( is_wp_error( $scope ) ) return $scope;
        $sites = $input['sites'] ?? null;
        if ( ! is_array( $sites ) || ! $sites || count( $sites ) > self::MAX_SITES ) return self::error( 'multisite_budget_invalid' );
        $children = array(); $seen = array();
        foreach ( $sites as $site ) {
            if ( ! is_array( $site ) || ! is_array( $site['scope'] ?? null ) || ! is_array( $site['target'] ?? null ) ) return self::error( 'multisite_child_invalid' );
            $child = $site['scope']; $target = $site['target'];
            $keys = array( 'contract', 'binding_sha256', 'scope_sha256', 'site_uuid', 'origin', 'environment', 'source_sha', 'package_sha256', 'runtime_generation', 'restore_epoch', 'profile_sha256', 'deployment_sha256', 'external_record_sha256', 'actor_sha256', 'blog_id', 'locale' );
            if ( 'mad4b.cso01.scope.v1' !== ( $child['contract'] ?? null ) || $child !== self::select( $child, $keys ) ) return self::error( 'multisite_scope_invalid' );
            foreach ( array( 'binding_sha256', 'package_sha256', 'runtime_generation', 'profile_sha256', 'deployment_sha256', 'external_record_sha256', 'actor_sha256' ) as $field ) if ( ! self::sha( $child[ $field ] ?? null ) ) return self::error( 'multisite_scope_invalid' );
            if ( ! self::uuid( $child['site_uuid'] ?? null ) || ! self::origin( $child['origin'] ?? null ) || ! in_array( $child['environment'] ?? '', array( 'staging', 'production', 'development', 'local' ), true ) || ! is_int( $child['blog_id'] ?? null ) || $child['blog_id'] < 1 || ! is_int( $child['restore_epoch'] ?? null ) || $child['restore_epoch'] < 1 || ! is_string( $child['source_sha'] ?? null ) || ! preg_match( '/^[a-f0-9]{40}$/D', $child['source_sha'] ) || ! is_string( $child['locale'] ?? null ) || ! preg_match( '/^[A-Za-z]{2,8}(?:[_-][A-Za-z0-9]{2,8}){0,3}$/D', $child['locale'] ) ) return self::error( 'multisite_scope_invalid' );
            $key = $child['site_uuid'] . '|' . self::origin_key( $child['origin'] ) . '|' . $child['blog_id'] . '|' . $child['environment'];
            if ( isset( $seen[ $key ] ) ) return self::error( 'multisite_duplicate_site' ); $seen[ $key ] = true;
            if ( ! self::slug( $target['type'] ?? null ) || ! is_int( $target['id'] ?? null ) || $target['id'] < 0 || ! is_string( $target['expected_revision'] ?? null ) || '' === $target['expected_revision'] || strlen( $target['expected_revision'] ) > 191 || ! is_string( $site['capability'] ?? null ) || ! preg_match( '~^[a-z0-9._-]+/[a-z0-9._/-]+$~D', $site['capability'] ) ) return self::error( 'multisite_target_invalid' );
            $a = MAD4B_SCP_CSO_Scope::digest( $child ); $b = MAD4B_SCP_CSO_Scope::digest( $scope );
            if ( is_wp_error( $a ) || is_wp_error( $b ) ) return self::error( 'multisite_scope_invalid' ); $local = hash_equals( $a, $b );
            $descriptor = $local ? MAD4B_SCP_CSO_Registry::describe( $site['capability'] ) : null;
            $verified = $local && is_array( $descriptor ) && self::sha( $site['schema_sha256'] ?? null ) && self::sha( $descriptor['schema_sha256'] ?? null ) && hash_equals( $site['schema_sha256'], $descriptor['schema_sha256'] );
            $children[] = array( 'scope' => $child, 'target' => self::select( $target, array( 'type', 'id', 'expected_revision' ) ), 'capability' => $site['capability'],
                'schema_sha256' => self::sha( $site['schema_sha256'] ?? null ) ? $site['schema_sha256'] : '', 'local_descriptor_verified' => $verified,
                'remote_receipt_verified' => false, 'status' => 'UNVERIFIED_SITE_PLAN',
                'blockers' => array( $local ? 'independent_site_authority_and_target_readback_required' : 'fresh_independent_target_site_connection_and_authority_required' ),
                'authorizing' => false, 'execution_supported' => false, 'grant_reuse_allowed' => false, 'secret_transfer_allowed' => false, 'cross_blog_execution_allowed' => false );
        }
        return self::finish( 'multisite_plan', $scope, array( 'site_count' => count( $children ), 'children' => $children, 'verified_execution_count' => 0,
            'coordinator_grants_authority' => false, 'site_local_receipts_required' => true, 'tenant_residency_and_locale_revalidation_required' => true ), array( 'independent_per_site_acceptance_required' ) );
    }

    public static function promotion_plan( array $input ) {
        $scope = self::context( $input, 'production_proposal' ); if ( is_wp_error( $scope ) ) return $scope;
        if ( 'staging' !== $scope['environment'] ) return self::error( 'promotion_source_must_be_staging' );
        $artifact = $input['artifact'] ?? null; $destination = $input['destination'] ?? null;
        if ( ! is_array( $artifact ) || ( $artifact['source_sha'] ?? null ) !== $scope['source_sha'] || ( $artifact['package_sha256'] ?? null ) !== $scope['package_sha256'] || ! self::sha( $artifact['artifact_sha256'] ?? null ) ) return self::error( 'promotion_exact_artifact_required' );
        if ( ! is_array( $destination ) || 'production' !== ( $destination['environment'] ?? null ) || ! self::origin( $destination['origin'] ?? null ) || self::origin_key( $destination['origin'] ) === self::origin_key( $scope['origin'] ) || ! self::sha( $destination['profile_sha256'] ?? null ) || ! self::uuid( $destination['site_uuid'] ?? null ) ) return self::error( 'promotion_destination_invalid' );
        $changes = $input['changes'] ?? array(); if ( ! is_array( $changes ) || count( $changes ) > 100 ) return self::error( 'promotion_diff_budget_invalid' ); $diff = array();
        foreach ( $changes as $change ) {
            if ( ! is_array( $change ) || ! is_string( $change['capability'] ?? null ) || ! preg_match( '~^[a-z0-9._-]+/[a-z0-9._/-]+$~D', $change['capability'] ) || ! self::sha( $change['source_schema_sha256'] ?? null ) || ! self::sha( $change['target_schema_sha256'] ?? null ) || ! is_string( $change['expected_target_revision'] ?? null ) || '' === $change['expected_target_revision'] || strlen( $change['expected_target_revision'] ) > 191 ) return self::error( 'promotion_diff_invalid' );
            $diff[] = self::select( $change, array( 'capability', 'source_schema_sha256', 'target_schema_sha256', 'expected_target_revision' ) );
        }
        $material = self::select( $artifact, array( 'source_sha', 'package_sha256', 'artifact_sha256' ) ); $digest = MAD4B_SCP_CSO_Scope::digest( $material );
        $bound = ! is_wp_error( $digest ) && class_exists( 'MAD4B_SCP_Site_Profile' ) && method_exists( 'MAD4B_SCP_Site_Profile', 'verify_deployment_binding_proof' ) && is_string( $artifact['proof'] ?? null ) && true === MAD4B_SCP_Site_Profile::verify_deployment_binding_proof( 'mad4b.cso01.promotion-artifact.v1', $digest, $artifact['proof'] );
        // A deployment-family MAC is not certification by an independent release signer.
        $blockers = array( 'independent_release_artifact_signature_and_staging_linkage_required', 'separate_production_authority_and_owner_approval_required', 'independent_production_readback_and_rollback_required' );
        if ( ! $bound ) $blockers[] = 'artifact_deployment_binding_proof_required'; $ready = false; $verdict_digest = ''; $gates = 0;
        if ( is_array( $input['staging_bundle'] ?? null ) && is_array( $input['preparation'] ?? null ) ) {
            $verdict = self::read( 'mad4b/production-readiness-evaluate', array( 'bundle' => $input['staging_bundle'] ), $input['preparation'] );
            if ( ! is_wp_error( $verdict ) ) {
                $gates = (int) ( $verdict['evidence_gate_count'] ?? 0 );
                $ready = 'mad4b.production-live-evidence-verdict.v1' === ( $verdict['contract'] ?? null ) && true === ( $verdict['production_ready'] ?? null ) && false === ( $verdict['production_authorized'] ?? null ) && true === ( $verdict['promotion_required'] ?? null ) && 'mad4b.production-evidence-trust.v1' === ( $verdict['evidence_trust_contract'] ?? null ) && $gates > 0 && $gates === (int) ( $verdict['trusted_evidence_gate_count'] ?? -1 ) && ( $verdict['candidate_identity']['source_commit_sha'] ?? null ) === $scope['source_sha'] && ( $verdict['candidate_identity']['package_manifest_digest'] ?? null ) === $scope['package_sha256'];
                if ( $ready ) $verdict_digest = MAD4B_SCP_CSO_Scope::digest( $verdict );
            }
        }
        if ( ! $ready ) $blockers[] = 'independent_staging_browser_host_provider_evidence_required';
        return self::finish( 'promotion_plan', $scope, array( 'artifact' => $material, 'artifact_binding_proof_verified' => $bound,
            'artifact_signature_verified' => false, 'artifact_linkage_to_staging_receipts_verified' => false,
            'destination' => self::select( $destination, array( 'site_uuid', 'origin', 'environment', 'profile_sha256' ) ), 'diff' => $diff,
            'staging_evidence_ready' => $ready, 'staging_verdict_sha256' => is_string( $verdict_digest ) ? $verdict_digest : '', 'trusted_staging_gate_count' => $ready ? $gates : 0,
            'staging_approval_inherited' => false, 'secret_transfer_allowed' => false, 'production_scope_and_actor_must_be_independently_recaptured' => true,
            'steps' => array( 'verify_signed_artifact_and_diff', 'verify_independent_staging_evidence', 'capture_production_scope_and_authority', 'obtain_separate_production_approval', 'apply_via_governed_release_executor', 'verify_production_postcondition_and_supported_rollback' ) ), $blockers );
    }
    private static function validate_values( array $values, $schema ) {
        if ( ! is_array( $schema ) || ! function_exists( 'rest_validate_value_from_schema' ) ) return self::error( 'native_schema_validator_unavailable' );
        foreach ( array( '_mad4b_approval_ticket_id', '_mad4b_context_receipt', 'approval_ticket_id', 'grant', 'grants', 'authority', 'sql', 'table', 'column' ) as $field ) if ( array_key_exists( $field, $values ) ) return self::error( 'authority_or_private_storage_input_denied' );
        return is_wp_error( rest_validate_value_from_schema( $values, $schema, 'values' ) ) ? self::error( 'native_input_schema_invalid' ) : true;
    }
    private static function uuid( $value ) { return is_string( $value ) && 1 === preg_match( '/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/D', $value ); }
    private static function origin( $value ) {
        if ( ! is_string( $value ) || strlen( $value ) > 240 ) return false; $parts = parse_url( $value );
        return is_array( $parts ) && 'https' === ( $parts['scheme'] ?? null ) && ! empty( $parts['host'] ) && ! isset( $parts['user'] ) && ! isset( $parts['pass'] ) && ! isset( $parts['query'] ) && ! isset( $parts['fragment'] ) && ( ! isset( $parts['path'] ) || '' === $parts['path'] || '/' === $parts['path'] );
    }
    private static function origin_key( $value ) {
        $parts = parse_url( $value ); return is_array( $parts ) ? strtolower( ( $parts['scheme'] ?? '' ) . '://' . ( $parts['host'] ?? '' ) ) . ':' . ( $parts['port'] ?? 443 ) : '';
    }
}

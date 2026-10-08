<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once __DIR__ . '/class-mad4b-scp-g6-contracts.php';

/** Source metadata admission only; no document fetch, parser, embedding or vector writes. */
final class MAD4B_SCP_G6_Knowledge_Admission {
	const CONTRACT = 'mad4b.g6-knowledge-source-admission.v1';
	public static function preview( $input = array() ) {
		$owner = MAD4B_SCP_G6_Contracts::owner();
		if ( is_wp_error( $owner ) ) return $owner;
		$budget = MAD4B_SCP_G6_Contracts::untrusted_arguments( $input );
		if ( is_wp_error( $budget ) ) return $budget;
		if ( ! is_array( $input ) || array_diff( array_keys( $input ), array( 'source_ref', 'source_sha256', 'artifact_sha256', 'site_uuid', 'kind', 'rights', 'expires_at', 'privacy_class', 'storage_region', 'deleted', 'rights_revoked' ) ) ) return MAD4B_SCP_G6_Contracts::error( 'source_schema', 'Only source metadata may be inspected; no document payloads, URLs or file paths.' );
		$site = MAD4B_SCP_G6_Contracts::site(); if ( is_wp_error( $site ) ) return $site;
		if ( ! isset( $input['site_uuid'] ) || $input['site_uuid'] !== $site ) return MAD4B_SCP_G6_Contracts::error( 'source_tenant', 'Source is outside the current site identity.' );
		if ( ! isset( $input['source_ref'] ) || ! MAD4B_SCP_G6_Contracts::id( $input['source_ref'] ) ) return MAD4B_SCP_G6_Contracts::error( 'source_ref', 'Exact source identity is required.' );
		foreach ( array( 'source_sha256', 'artifact_sha256' ) as $field ) if ( ! isset( $input[ $field ] ) || ! MAD4B_SCP_G6_Contracts::sha( $input[ $field ] ) ) return MAD4B_SCP_G6_Contracts::error( 'source_pin', 'Source and artifact provenance must be exact.' );
		if ( ! isset( $input['kind'] ) || ! in_array( $input['kind'], array( 'document', 'pdf', 'drive_asset', 'approved_html', 'form_schema' ), true ) ) return MAD4B_SCP_G6_Contracts::error( 'source_kind', 'Source kind is not admitted.' );
		if ( ! isset( $input['rights'] ) || ! in_array( $input['rights'], array( 'owner_provided', 'licensed', 'public_domain' ), true ) ) return MAD4B_SCP_G6_Contracts::error( 'source_rights', 'Explicit verified rights are required.' );
		if ( ! isset( $input['expires_at'] ) || ! is_int( $input['expires_at'] ) || $input['expires_at'] <= time() ) return MAD4B_SCP_G6_Contracts::error( 'source_rights_expired', 'Expired or missing rights require review.' );
		foreach ( array( 'deleted', 'rights_revoked' ) as $flag )
            if ( array_key_exists( $flag, $input ) && ! is_bool( $input[ $flag ] ) )
                return MAD4B_SCP_G6_Contracts::error( 'source_flags', 'Source revocation flags must be exact booleans.' );
        if ( ! empty( $input['deleted'] ) || ! empty( $input['rights_revoked'] ) ) return MAD4B_SCP_G6_Contracts::error( 'source_revoked', 'Deleted or revoked evidence cannot be re-ingested.' );
		if ( ! isset( $input['privacy_class'] ) || ! in_array( $input['privacy_class'], array( 'public', 'internal', 'restricted' ), true ) ) return MAD4B_SCP_G6_Contracts::error( 'source_privacy', 'Explicit source privacy class is required.' );
		$region = isset( $input['storage_region'] ) ? $input['storage_region'] : '';
		if ( ! is_string( $region ) || 1 !== preg_match( '/^[a-z]{2}(-[a-z0-9]{2,12})?$/D', $region ) ) return MAD4B_SCP_G6_Contracts::error( 'source_region', 'Approved residency is not proven.' );
		$binding = MAD4B_SCP_G6_Contracts::binding( $input['artifact_sha256'] );
		if ( is_wp_error( $binding ) ) return $binding;
		$result = array(
			'contract' => self::CONTRACT,
			'source_ref_sha256' => MAD4B_SCP_G6_Contracts::digest( $input['source_ref'] ),
			'source_sha256' => $input['source_sha256'],
			'artifact_sha256' => $input['artifact_sha256'],
			'rights' => $input['rights'],
			'rights_expires_at' => $input['expires_at'],
			'privacy_class' => $input['privacy_class'],
			'storage_region' => $region,
			'binding_sha256' => MAD4B_SCP_G6_Contracts::digest( $binding ),
			'admission_state' => 'APPROVAL_REQUIRED',
			'rights_document_readback_verified' => false,
			'provider_execution_admitted' => false,
			'vector_tenant_isolation_certified' => false,
			'embeddings_generated' => false,
			'content_retrieved' => false,
			'outbound_fetch_performed' => false,
			'ingestion_performed' => false,
			'authorizing' => false,
		);
		$result['admission_sha256'] = MAD4B_SCP_G6_Contracts::digest( $result );
		return $result;
	}
}

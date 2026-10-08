<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once __DIR__ . '/class-mad4b-scp-g6-contracts.php';

/**
 * Server-collected retrieval evidence is evaluated without returning passages.
 * This cannot substitute for vector-store readback or source license proofs.
 */
final class MAD4B_SCP_G6_Retrieval_Evaluation {
    const CONTRACT = 'mad4b.g6-retrieval-evaluation.v1';
    public static function evaluate( $request, $observations ) {
        $owner = MAD4B_SCP_G6_Contracts::owner();
        if ( is_wp_error( $owner ) ) return $owner;
        if ( ! is_array( $request ) || ! is_array( $observations ) || count( $observations ) > 100 )
            return MAD4B_SCP_G6_Contracts::error( 'retrieval_budget', 'A bounded retrieval evaluation is required.' );
        $bounded = MAD4B_SCP_G6_Contracts::data( array( 'request' => $request, 'observations' => $observations ) );
        if ( is_wp_error( $bounded ) ) return $bounded;
        if ( array_diff( array_keys( $request ), array( 'query_sha256', 'site_uuid', 'generation_sha256', 'region', 'minimum_citations' ) ) )
            return MAD4B_SCP_G6_Contracts::error( 'retrieval_request', 'Retrieval evaluation accepts no text or raw source material.' );
        $site = MAD4B_SCP_G6_Contracts::site();
        if ( is_wp_error( $site ) ) return $site;
        if ( ! isset( $request['site_uuid'] ) || $request['site_uuid'] !== $site ||
            ! isset( $request['query_sha256'], $request['generation_sha256'] ) ||
            ! MAD4B_SCP_G6_Contracts::sha( $request['query_sha256'] ) ||
            ! MAD4B_SCP_G6_Contracts::sha( $request['generation_sha256'] ) )
            return MAD4B_SCP_G6_Contracts::error( 'retrieval_scope', 'Retrieval query, site and generation must match exactly.' );
        $region = isset( $request['region'] ) ? $request['region'] : '';
        if ( ! is_string( $region ) || ! preg_match( '/^[a-z]{2}(-[a-z0-9]{2,12})?$/D', $region ) )
            return MAD4B_SCP_G6_Contracts::error( 'retrieval_region', 'Retrieval storage region must be explicit.' );
        $minimum = isset( $request['minimum_citations'] ) ? $request['minimum_citations'] : null;
        if ( ! is_int( $minimum ) || $minimum < 1 || $minimum > 20 )
            return MAD4B_SCP_G6_Contracts::error( 'retrieval_minimum', 'Minimum citation threshold must be bounded.' );
        // The request's generation must be verified against the current server context.
        $binding = MAD4B_SCP_G6_Contracts::binding( $request['query_sha256'] );
        if ( is_wp_error( $binding ) ) return $binding;
        if ( ! hash_equals( $binding['generation_sha256'], $request['generation_sha256'] ) )
            return MAD4B_SCP_G6_Contracts::error( 'retrieval_generation_changed', 'Retrieval evidence is bound to an obsolete runtime generation.' );
        $seen = array(); $rejections = array(); $citations = array();
        foreach ( $observations as $o ) {
            // Only exact metadata is allowed; passing snippets/instructions is a schema failure.
            $fields = array( 'source_sha256', 'chunk_sha256', 'site_uuid', 'generation_sha256', 'storage_region',
                'rights_expires_at', 'deleted', 'access_granted', 'citation_verified', 'embedding_current' );
            if ( ! is_array( $o ) || array_diff( array_keys( $o ), $fields ) ) return MAD4B_SCP_G6_Contracts::error( 'retrieval_payload_forbidden', 'Raw text or source instructions cannot enter evaluation.' );
            if ( ! isset( $o['source_sha256'], $o['chunk_sha256'] ) || ! MAD4B_SCP_G6_Contracts::sha( $o['source_sha256'] ) || ! MAD4B_SCP_G6_Contracts::sha( $o['chunk_sha256'] ) )
                return MAD4B_SCP_G6_Contracts::error( 'retrieval_pin', 'Source and chunk digests are required.' );
            foreach ( array( 'deleted', 'access_granted', 'citation_verified', 'embedding_current' ) as $flag )
                if ( ! array_key_exists( $flag, $o ) || ! is_bool( $o[ $flag ] ) )
                    return MAD4B_SCP_G6_Contracts::error( 'retrieval_flags', 'Retrieval trust flags must be exact booleans.' );
            $key = $o['source_sha256'] . ':' . $o['chunk_sha256'];
            if ( isset( $seen[ $key ] ) ) { $rejections[] = 'duplicate_citation'; continue; }
            $seen[ $key ] = true;
            if ( ! isset( $o['site_uuid'] ) || $site !== $o['site_uuid'] ) { $rejections[] = 'cross_site'; continue; }
            if ( ! isset( $o['generation_sha256'] ) || $request['generation_sha256'] !== $o['generation_sha256'] ) { $rejections[] = 'stale_embedding'; continue; }
            if ( ! isset( $o['storage_region'] ) || $region !== $o['storage_region'] ) { $rejections[] = 'residency_mismatch'; continue; }
            if ( ! isset( $o['rights_expires_at'] ) || ! is_int( $o['rights_expires_at'] ) || $o['rights_expires_at'] <= time() ) { $rejections[] = 'rights_expired'; continue; }
            if ( $o['deleted'] || ! $o['access_granted'] || ! $o['citation_verified'] || ! $o['embedding_current'] ) { $rejections[] = 'source_uncertified_or_revoked'; continue; }
            $citations[] = array( 'source_sha256' => $o['source_sha256'], 'chunk_sha256' => $o['chunk_sha256'] );
        }
        $out = array( 'contract' => self::CONTRACT,
            'query_sha256' => $request['query_sha256'],
            'site_ref_sha256' => MAD4B_SCP_G6_Contracts::digest( $site ),
            'generation_sha256' => $request['generation_sha256'],
            'citation_count' => count( $citations ),
            'citation_refs' => $citations,
            'minimum_met' => count( $citations ) >= $minimum,
            'rejection_reasons' => array_values( array_unique( $rejections ) ),
            'source_text_exposed' => false,
            'source_instructions_authorizing' => false,
            'provider_freshness_independently_verified' => false,
            'retrieval_executed' => false,
            'vector_store_certified' => false,
            'authorizing' => false );
        $out['evaluation_sha256'] = MAD4B_SCP_G6_Contracts::digest( $out );
        return $out;
    }
}

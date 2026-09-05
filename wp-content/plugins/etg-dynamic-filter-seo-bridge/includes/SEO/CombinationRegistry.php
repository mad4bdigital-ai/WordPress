<?php
namespace ETG\DynamicFilterSEOBridge\SEO;

use ETG\DynamicFilterSEOBridge\Config\Configuration;
use ETG\DynamicFilterSEOBridge\Config\ProfileRegistry;

final class CombinationRegistry {
	private $config;
	public function __construct( Configuration $config ) { $this->config = $config; }

	public function evaluate( array $context ): array {
		$filters = (array) ( $context['filters'] ?? array() );
		$profile = (array) ( $context['profile'] ?? array() );
		$count = count( $filters );
		$requires = $count > 1 || ( 1 === $count && ! empty( $profile['require_exact_for_single'] ) );
		if ( array_key_exists( 'require_exact_combination_approval', $profile ) && empty( $profile['require_exact_combination_approval'] ) ) { $requires = false; }
		elseif ( ! $profile && ! $this->config->get( 'require_exact_combination_approval', true ) ) { $requires = false; }
		$signature = $this->signature( $context );
		$legacySignature = $this->legacySignature( $context );
		$shape = ProfileRegistry::taxonomySetSignature( $filters );
		if ( ! $requires ) {
			return array( 'contract'=>'etg.dfsb.combination-authority.v2','required'=>false,'approved'=>true,'signature'=>$signature,'legacy_signature'=>$legacySignature,'taxonomy_set'=>$shape,'source'=>'not_required' );
		}
		$registry = isset( $profile['indexable_combinations'] ) ? (array) $profile['indexable_combinations'] : (array) $this->config->get( 'indexable_combinations', array() );
		if ( function_exists( 'apply_filters' ) ) { $proposal=(array)apply_filters('etg_filter_seo_indexable_combination_registry',$registry,$context);$proposal=array_map(static function($v){return strtolower(trim((string)$v));},$proposal);$registry=array_values(array_filter($registry,static function($v)use($proposal){return in_array(strtolower(trim((string)$v)),$proposal,true);})); }
		$normalized = array();
		foreach ( $registry as $entry ) { $entry = strtolower( trim( (string) $entry ) ); if ( '' !== $entry ) { $normalized[] = $entry; } }
		$normalized = array_unique( $normalized );
		$approved = in_array( strtolower( $signature ), $normalized, true ) || in_array( strtolower( $legacySignature ), $normalized, true );
		return array( 'contract'=>'etg.dfsb.combination-authority.v2','required'=>true,'approved'=>$approved,'signature'=>$signature,'legacy_signature'=>$legacySignature,'taxonomy_set'=>$shape,'source'=>'profile_configuration' );
	}

	public function signature( array $context ): string {
		$parts = array();
		$profileId = sanitize_key( (string) ( $context['profile_id'] ?? ( $context['profile']['id'] ?? '' ) ) );
		if ( '' !== $profileId ) { $parts[] = $profileId; }
		$language = sanitize_key( (string) ( $context['language'] ?? '' ) );
		if ( '' !== $language ) { $parts[] = $language; }
		$filters = (array) ( $context['filters'] ?? array() );
		ksort( $filters );
		foreach ( $filters as $taxonomy => $slug ) { $parts[] = sanitize_key( (string) $taxonomy ) . '=' . sanitize_title( (string) $slug ); }
		return implode( '|', array_filter( $parts, 'strlen' ) );
	}

	private function legacySignature( array $context ): string {
		$parts = array( sanitize_key( (string) ( $context['language'] ?? '' ) ) );
		$filters = (array) ( $context['filters'] ?? array() ); ksort( $filters );
		foreach ( $filters as $taxonomy => $slug ) { $parts[] = sanitize_key( (string) $taxonomy ) . '=' . sanitize_title( (string) $slug ); }
		return implode( '|', array_filter( $parts, 'strlen' ) );
	}
}

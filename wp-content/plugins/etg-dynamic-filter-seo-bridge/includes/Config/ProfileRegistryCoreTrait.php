<?php
namespace ETG\DynamicFilterSEOBridge\Config;

trait ProfileRegistryCoreTrait {
	public function __construct( Configuration $config ) { $this->config = $config; }

	public function all(): array {
		if ( null !== $this->profiles ) { return $this->profiles; }
		$raw = (string) $this->config->get( 'profiles_json', '' );
		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) { $decoded = array(); }
		if ( count( $decoded ) > self::MAX_PROFILES ) { $this->normalizationErrors[] = 'profile_count_limit_exceeded'; }
		$out = array();
		foreach ( array_slice( $decoded, 0, self::MAX_PROFILES ) as $profile ) {
			if ( ! is_array( $profile ) ) { continue; }
			$profile = $this->normalizeProfile( $profile );
			if ( '' === $profile['id'] ) { $this->normalizationErrors[] = 'profile_id_empty'; continue; }
			if ( isset( $out[ $profile['id'] ] ) ) { $this->normalizationErrors[] = 'duplicate_profile_id:' . $profile['id']; continue; }
			$out[ $profile['id'] ] = $profile;
		}
		if ( function_exists( 'apply_filters' ) ) {
			$filtered = (array) apply_filters( 'etg_filter_seo_surface_profiles', $out, $this->config );
			if ( count( $filtered ) > self::MAX_PROFILES ) { $this->normalizationErrors[] = 'filtered_profile_count_limit_exceeded'; }
			$normalized = array();
			foreach ( array_slice( $filtered, 0, self::MAX_PROFILES, true ) as $key => $candidate ) {
				if ( ! is_array( $candidate ) ) { continue; }
				if ( empty( $candidate['id'] ) && is_string( $key ) ) { $candidate['id'] = $key; }
				$candidate = $this->normalizeProfile( $candidate );
				$id = (string) $candidate['id'];
				if ( '' === $id ) { $this->normalizationErrors[] = 'filtered_profile_id_empty'; continue; }
				if ( isset( $normalized[ $id ] ) ) { $this->normalizationErrors[] = 'duplicate_filtered_profile_id:' . $id; continue; }
				$normalized[ $id ] = $candidate;
			}
			$out = $normalized;
		}
		$this->profiles = $out;
		return $out;
	}

	public function get( string $id ): array {
		$profiles = $this->all();
		$id = sanitize_key( $id );
		return isset( $profiles[ $id ] ) ? (array) $profiles[ $id ] : array();
	}

	public function allowedTaxonomies(): array {
		$out = array();
		foreach ( $this->all() as $profile ) {
			foreach ( array_keys( (array) $profile['taxonomy_rules'] ) as $taxonomy ) { $out[] = sanitize_key( $taxonomy ); }
		}
		return array_values( array_unique( array_filter( $out ) ) );
	}

	public function resolve( array $parsed ): array { return $this->resolveInternal( $parsed, false ); }
	public function resolveForEvidence( array $parsed ): array { return $this->resolveInternal( $parsed, true ); }

	private function resolveInternal( array $parsed, bool $allowDisabled ): array {
		$archive = sanitize_title( (string) ( $parsed['archive'] ?? '' ) );
		$archivePath = $this->normalizeArchivePath( (string) ( $parsed['archive_path'] ?? '' ) );
		$provider = sanitize_key( (string) ( $parsed['provider'] ?? '' ) );
		$queryId = sanitize_key( (string) ( $parsed['query_id'] ?? '' ) );
		$profiles = $this->all();
		if ( $this->normalizationErrors ) { return $this->resolution( true, false, 'profile_registry_invalid' ); }
		$archiveMatches = array();
		foreach ( $profiles as $profile ) { if ( $this->archiveMatches( $profile, $archive, $archivePath ) ) { $archiveMatches[] = $profile; } }
		if ( ! $archiveMatches ) { return $this->resolution( false, false, 'archive_not_profiled' ); }
		$candidates = $allowDisabled ? $archiveMatches : array_values( array_filter( $archiveMatches, static function ( $profile ) { return ! empty( $profile['enabled'] ); } ) );
		if ( ! $candidates ) { return $this->resolution( false, false, 'profile_disabled', $archiveMatches[0] ); }
		$providerMatches = array();
		foreach ( $candidates as $profile ) { if ( $this->profileSupportsProvider( $profile, $provider ) ) { $providerMatches[] = $profile; } }
		if ( ! $providerMatches ) { return $this->resolution( true, false, 'provider_not_profiled', $candidates[0] ); }
		$routeMatches = array();
		foreach ( $providerMatches as $profile ) { if ( $this->profileSupportsRoute( $profile, $provider, $queryId ) ) { $routeMatches[] = $profile; } }
		if ( ! $routeMatches ) { return $this->resolution( true, false, 'query_not_profiled', $providerMatches[0] ); }
		if ( 1 !== count( $routeMatches ) ) { return $this->resolution( true, false, 'ambiguous_profile', $routeMatches[0] ); }
		$profile = $routeMatches[0];
		$rules = (array) $profile['taxonomy_rules'];
		foreach ( array_keys( (array) ( $parsed['filters'] ?? array() ) ) as $taxonomy ) {
			if ( ! isset( $rules[ sanitize_key( (string) $taxonomy ) ] ) ) { return $this->resolution( true, false, 'taxonomy_not_profiled', $profile ); }
		}
		return $this->resolution( true, true, $allowDisabled && empty( $profile['enabled'] ) ? 'profile_matched_for_evidence' : 'profile_matched', $profile );
	}

	public function validationErrors(): array {
		$profiles = $this->all();
		$errors = (array) $this->normalizationErrors;
		if ( ! $profiles ) { $errors[] = 'profiles_empty_or_invalid'; return array_values( array_unique( $errors ) ); }
		$seenKeys = array();
		$enabledCount = 0;
		$globalEnabled = method_exists( $this->config, 'enabled' ) ? (bool) $this->config->enabled() : (bool) $this->config->get( 'enabled', false );
		foreach ( $profiles as $id => $profile ) {
			$publication = (array) ( $profile['publication'] ?? array() );
			if ( ! empty( $publication['elementor_content_verified'] ) && empty( $publication['elementor_verification_evidence_id'] ) ) { $errors[] = 'profile:' . $id . ':elementor_verification_evidence_required'; }
			if ( ! empty( $publication['provider_observation_verified'] ) && empty( $publication['provider_observation_evidence_id'] ) ) { $errors[] = 'profile:' . $id . ':provider_observation_evidence_required'; }
			if ( ! empty( $publication['result_count_parity_verified'] ) && empty( $publication['result_count_parity_evidence_id'] ) ) { $errors[] = 'profile:' . $id . ':result_count_parity_evidence_required'; }
			if ( empty( $profile['enabled'] ) ) { continue; }
			$enabledCount++;
			if ( empty( $profile['archive_paths'] ) ) { $errors[] = 'profile:' . $id . ':exact_archive_paths_required'; }
			if ( empty( $profile['routes'] ) ) { $errors[] = 'profile:' . $id . ':exact_routes_required'; }
			if ( empty( $profile['taxonomy_rules'] ) ) { $errors[] = 'profile:' . $id . ':empty_taxonomy_rules'; }
			if ( empty( $profile['allowed_taxonomy_sets'] ) ) { $errors[] = 'profile:' . $id . ':empty_allowed_taxonomy_sets'; }
			if ( ! empty( $profile['require_post_type_binding'] ) && empty( $profile['post_types'] ) ) { $errors[] = 'profile:' . $id . ':post_type_binding_without_post_types'; }
			if ( ! empty( $profile['require_post_type_binding'] ) && ! in_array( (string) ( $profile['post_type_authority'] ?? '' ), array( 'query_builder', 'main_query', 'either', 'both' ), true ) ) { $errors[] = 'profile:' . $id . ':invalid_post_type_authority'; }
			if ( $globalEnabled ) {
				if ( ! empty( $publication['require_elementor_content'] ) && empty( $publication['elementor_content_verified'] ) ) { $errors[] = 'profile:' . $id . ':elementor_content_unverified'; }
				if ( ! empty( $publication['sitemap'] ) && ! empty( $profile['require_provider_observation_for_index'] ) && empty( $publication['provider_observation_verified'] ) ) { $errors[] = 'profile:' . $id . ':publication_provider_observation_unverified'; }
				if ( ! empty( $publication['sitemap'] ) && ! empty( $publication['require_result_count_parity_for_publication'] ) && empty( $publication['result_count_parity_verified'] ) ) { $errors[] = 'profile:' . $id . ':publication_result_count_parity_unverified'; }
			}
			$roles = array();
			foreach ( (array) $profile['taxonomy_rules'] as $taxonomy => $rule ) {
				$role = sanitize_key( (string) ( $rule['role'] ?? '' ) );
				if ( '' === $role ) { $errors[] = 'profile:' . $id . ':empty_role:' . $taxonomy; continue; }
				if ( isset( $roles[ $role ] ) ) { $errors[] = 'profile:' . $id . ':duplicate_role:' . $role; }
				$roles[ $role ] = true;
			}
			$archives = array();
			foreach ( (array) $profile['archive_paths'] as $path ) { $archives[] = $this->canonicalArchiveAuthority( (string) $path ); }
			$archives = array_values( array_unique( array_filter( $archives ) ) );
			foreach ( $archives as $archiveAuthority ) {
				foreach ( (array) $profile['routes'] as $route ) {
					$key = 'path:' . $archiveAuthority . '|' . (string) ( $route['provider'] ?? '' ) . '|' . (string) ( $route['query_id'] ?? '' );
					if ( isset( $seenKeys[ $key ] ) && $seenKeys[ $key ] !== $id ) { $errors[] = 'ambiguous_profile_match:' . $key; }
					else { $seenKeys[ $key ] = $id; }
				}
			}
		}
		if ( $globalEnabled && 0 === $enabledCount ) { $errors[] = 'no_enabled_profiles'; }
		return array_values( array_unique( $errors ) );
	}

	public function discovery(): array {
		$out = array( 'post_types' => array(), 'taxonomies' => array() );
		if ( function_exists( 'get_post_types' ) ) {
			$objects = get_post_types( array( 'public' => true ), 'objects' );
			foreach ( array_slice( is_array( $objects ) ? $objects : array(), 0, 100, true ) as $name => $object ) { $out['post_types'][ sanitize_key( (string) $name ) ] = array( 'label' => isset( $object->label ) ? (string) $object->label : (string) $name ); }
		}
		if ( function_exists( 'get_taxonomies' ) ) {
			$objects = get_taxonomies( array( 'public' => true ), 'objects' );
			foreach ( array_slice( is_array( $objects ) ? $objects : array(), 0, 150, true ) as $name => $object ) {
				$out['taxonomies'][ sanitize_key( (string) $name ) ] = array( 'label' => isset( $object->label ) ? (string) $object->label : (string) $name, 'object_type' => array_values( array_map( 'sanitize_key', isset( $object->object_type ) ? (array) $object->object_type : array() ) ) );
			}
		}
		return $out;
	}

	public function blueprint( string $postType, array $taxonomies, string $profileId = '' ): array {
		$postType = sanitize_key( $postType );
		$profileId = sanitize_key( $profileId ?: $postType );
		$discovery = $this->discovery();
		$warnings = array();
		if ( '' === $postType || ! isset( $discovery['post_types'][ $postType ] ) ) { $warnings[] = 'post_type_not_discovered'; }
		$rules = array(); $priority = 10; $accepted = array();
		foreach ( array_slice( $taxonomies, 0, 20 ) as $taxonomy ) {
			$taxonomy = sanitize_key( (string) $taxonomy ); if ( '' === $taxonomy ) { continue; }
			$object = (array) ( $discovery['taxonomies'][ $taxonomy ] ?? array() );
			if ( ! $object ) { $warnings[] = 'taxonomy_not_discovered:' . $taxonomy; continue; }
			if ( $postType && ! in_array( $postType, (array) ( $object['object_type'] ?? array() ), true ) ) { $warnings[] = 'taxonomy_not_attached:' . $taxonomy; continue; }
			$rules[ $taxonomy ] = array( 'role'=>$taxonomy,'priority'=>$priority,'gallery_priority'=>$priority,'index_single'=>false,'min_results'=>3,'required_meta_key'=>'','required_meta_values'=>array(),'meta_constraint_scope'=>'single','field_map'=>array() );
			$accepted[] = $taxonomy; $priority += 10;
		}
		$profile = array(
			'id'=>$profileId,'enabled'=>false,'inherit_global_defaults'=>false,'post_types'=>$postType?array($postType):array(),'require_post_type_binding'=>true,'post_type_authority'=>'query_builder','require_provider_observation_for_index'=>true,
			'archive_slugs'=>array(),'archive_paths'=>array(),'providers'=>array(),'query_ids'=>array(),'routes'=>array(),'max_filters'=>min(10,max(1,count($accepted))),'composition_mode'=>'generic','canonical_mode'=>'filtered','require_exact_combination_approval'=>true,'require_exact_for_single'=>false,
			'allowed_taxonomy_sets'=>array(),'min_results_by_depth'=>array('1'=>3,'2'=>3,'3'=>3),'taxonomy_rules'=>$rules,'indexable_combinations'=>array(),
			'content'=>array('required'=>true,'require_meta_description'=>true,'min_chars'=>250,'min_chars_by_depth'=>array('1'=>250,'2'=>400,'3'=>500),'min_unique_segments_by_depth'=>array('1'=>1,'2'=>2,'3'=>2)),
			'publication'=>array('sitemap'=>false,'hreflang'=>true,'schema'=>true,'social'=>true,'include_images_in_sitemap'=>true,'require_elementor_content'=>true,'elementor_render_when_global_off'=>false,'elementor_content_verified'=>false,'elementor_verification_evidence_id'=>'','provider_observation_verified'=>false,'provider_observation_evidence_id'=>'','require_result_count_parity_for_publication'=>true,'result_count_parity_verified'=>false,'result_count_parity_evidence_id'=>'','max_preview_urls'=>50,'max_publication_urls'=>100),
		);
		return array( 'contract'=>'etg.dfsb.profile-blueprint.v2','synthetic'=>true,'authorizing'=>false,'warnings'=>array_values(array_unique($warnings)),'profile'=>$profile );
	}

	public static function taxonomySetSignature( array $filters ): string {
		$taxonomies = array_values( array_filter( array_map( 'sanitize_key', array_keys( $filters ) ) ) );
		sort( $taxonomies, SORT_STRING );
		return implode( '+', $taxonomies );
	}
}

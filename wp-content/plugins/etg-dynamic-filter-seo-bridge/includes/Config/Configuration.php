<?php
namespace ETG\DynamicFilterSEOBridge\Config;

final class Configuration {
	public const OPTION_NAME = 'etg_dfsb_settings';

	public function defaults(): array {
		$defaults = $this->defaultsWithoutFilters();
		return function_exists( 'apply_filters' ) ? (array) apply_filters( 'etg_filter_seo_configuration_defaults', $defaults ) : $defaults;
	}

	public function all(): array {
		$stored = function_exists( 'get_option' ) ? get_option( self::OPTION_NAME, array() ) : array();
		$stored = is_array( $stored ) ? $stored : array();
		$config = array_merge( $this->defaults(), $stored );
		$config = $this->sanitize( $config );
		if ( function_exists( 'apply_filters' ) ) { $config=(array)apply_filters('etg_filter_seo_configuration',$config); $config=$this->sanitize($config); }
		return $config;
	}

	public function get( string $key, $default = null ) {
		$config = $this->all();
		return array_key_exists( $key, $config ) ? $config[ $key ] : $default;
	}

	public function enabled(): bool { return (bool) $this->get( 'enabled', false ); }

	public function revision(): string {
		$encoded = function_exists( 'wp_json_encode' ) ? wp_json_encode( $this->all() ) : json_encode( $this->all() );
		return substr( hash( 'sha256', (string) $encoded ), 0, 16 );
	}

	public function sanitize( $input ): array {
		$input = is_array( $input ) ? $input : array();
		$d = $this->defaultsWithoutFilters();
		$out = array();
		$out['enabled'] = $this->boolValue( $input, 'enabled', $d['enabled'] );
		$out['archive_slugs'] = $this->slugList( $input['archive_slugs'] ?? $d['archive_slugs'] );
		$out['providers'] = $this->keyList( $input['providers'] ?? $d['providers'] );
		$out['query_ids'] = $this->keyList( $input['query_ids'] ?? $d['query_ids'] );
		$out['allowed_taxonomies'] = $this->keyList( $input['allowed_taxonomies'] ?? $d['allowed_taxonomies'] );
		$out['max_filters'] = $this->boundedInt( $input['max_filters'] ?? $d['max_filters'], 1, 10 );
		$out['allowed_query_params'] = $this->keyList( $input['allowed_query_params'] ?? $d['allowed_query_params'] );
		$out['tracking_query_params'] = $this->keyList( $input['tracking_query_params'] ?? $d['tracking_query_params'] );
		$out['enable_jet_engine_result_count_adapter'] = $this->boolValue( $input, 'enable_jet_engine_result_count_adapter', $d['enable_jet_engine_result_count_adapter'] );
		$out['trust_legacy_result_count'] = $this->boolValue( $input, 'trust_legacy_result_count', $d['trust_legacy_result_count'] );
		$out['require_result_count_for_index'] = $this->boolValue( $input, 'require_result_count_for_index', $d['require_result_count_for_index'] );
		$out['min_results_location'] = $this->boundedInt( $input['min_results_location'] ?? $d['min_results_location'], 1, 1000000 );
		$out['min_results_pair'] = $this->boundedInt( $input['min_results_pair'] ?? $d['min_results_pair'], 1, 1000000 );
		$out['min_results_triple'] = $this->boundedInt( $input['min_results_triple'] ?? $d['min_results_triple'], 1, 1000000 );
		$out['index_single_tour_type'] = $this->boolValue( $input, 'index_single_tour_type', $d['index_single_tour_type'] );
		$out['indexable_location_levels'] = $this->keyList( $input['indexable_location_levels'] ?? $d['indexable_location_levels'] );
		$out['require_exact_combination_approval'] = $this->boolValue( $input, 'require_exact_combination_approval', $d['require_exact_combination_approval'] );
		$out['indexable_combinations'] = $this->lineList( $input['indexable_combinations'] ?? $d['indexable_combinations'] );
		$out['require_content_readiness'] = $this->boolValue( $input, 'require_content_readiness', $d['require_content_readiness'] );
		$out['require_meta_description'] = $this->boolValue( $input, 'require_meta_description', $d['require_meta_description'] );
		$out['min_content_chars'] = $this->boundedInt( $input['min_content_chars'] ?? $d['min_content_chars'], 0, 10000 );
		$canonical = sanitize_key( (string) ( $input['canonical_mode'] ?? $d['canonical_mode'] ) );
		$out['canonical_mode'] = in_array( $canonical, array( 'filtered', 'archive' ), true ) ? $canonical : 'filtered';
		$out['diagnostics_enabled'] = $this->boolValue( $input, 'diagnostics_enabled', $d['diagnostics_enabled'] );
		$out['log_decisions'] = $this->boolValue( $input, 'log_decisions', $d['log_decisions'] );
		$out['profiles_json'] = $this->profilesJson( $input['profiles_json'] ?? $d['profiles_json'] );
		return $out;
	}

	public function validationErrors(): array {
		$config = $this->all();
		$raw = (string) ( $config['profiles_json'] ?? '' );
		if ( '' === $raw ) { return array( 'profiles_json_invalid' ); }
		$decoded = json_decode( $raw, true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) || empty( $decoded ) ) { return array( 'profiles_json_invalid' ); }
		return array();
	}

	private function defaultsWithoutFilters(): array {
		return array(
			'enabled' => false,
			'archive_slugs' => array( 'tours-and-activities' ),
			'providers' => array( 'jet-engine' ),
			'query_ids' => array( 'tours_query_archive' ),
			'allowed_taxonomies' => array( 'location_jet', 'tour-types_jet', 'tour-styles_jet' ),
			'max_filters' => 3,
			'allowed_query_params' => array(),
			'tracking_query_params' => array( 'gclid', 'fbclid', 'msclkid' ),
			'enable_jet_engine_result_count_adapter' => true,
			'trust_legacy_result_count' => false,
			'require_result_count_for_index' => true,
			'min_results_location' => 1,
			'min_results_pair' => 3,
			'min_results_triple' => 3,
			'index_single_tour_type' => false,
			'indexable_location_levels' => array( 'city', 'landmark' ),
			'require_exact_combination_approval' => true,
			'indexable_combinations' => array(),
			'require_content_readiness' => true,
			'require_meta_description' => true,
			'min_content_chars' => 80,
			'canonical_mode' => 'filtered',
			'diagnostics_enabled' => true,
			'log_decisions' => false,
			'profiles_json' => $this->defaultProfilesJson(),
		);
	}

	private function defaultProfilesJson(): string {
		$profiles = array(
			array(
				'id' => 'tours',
				'enabled' => true,
				'inherit_global_defaults' => true,
				'post_types' => array(),
				'require_post_type_binding' => false,
				'post_type_authority' => 'query_builder',
				'archive_slugs' => array( 'tours-and-activities' ),
				'archive_paths' => array( '/tours-and-activities/' ),
				'providers' => array( 'jet-engine' ),
				'query_ids' => array( 'tours_query_archive' ),
				'routes' => array( array( 'provider'=>'jet-engine','query_id'=>'tours_query_archive' ) ),
				'max_filters' => 3,
				'composition_mode' => 'travel',
				'canonical_mode' => 'filtered',
				'require_exact_combination_approval' => true,
				'require_exact_for_single' => false,
				'allowed_taxonomy_sets' => array( 'location_jet', 'location_jet+tour-types_jet', 'location_jet+tour-types_jet+tour-styles_jet' ),
				'min_results_by_depth' => array( '1'=>1, '2'=>3, '3'=>3 ),
				'taxonomy_rules' => array(
					'location_jet' => array( 'role'=>'location','priority'=>10,'gallery_priority'=>20,'index_single'=>true,'min_results'=>1,'required_meta_key'=>'location_level','required_meta_values'=>array('city','landmark'),'meta_constraint_scope'=>'single' ),
					'tour-types_jet' => array( 'role'=>'tour_type','priority'=>20,'gallery_priority'=>30,'index_single'=>false,'min_results'=>3 ),
					'tour-styles_jet' => array( 'role'=>'style','priority'=>30,'gallery_priority'=>10,'index_single'=>false,'min_results'=>3 ),
				),
				'indexable_combinations' => array(),
				'content' => array( 'required'=>true,'require_meta_description'=>true,'min_chars'=>80 ),
			),
		);
		return (string) json_encode( $profiles, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	private function profilesJson( $value ): string {
		$valid = true;
		if ( is_array( $value ) ) { $decoded = $value; }
		else {
			$value = trim( (string) $value );
			if ( '' === $value || strlen( $value ) > 1000000 ) { $valid=false; $decoded=array(); }
			else { $decoded = json_decode( $value, true ); if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) { $valid=false; $decoded=array(); } }
		}
		if ( ! $valid ) {
			if ( function_exists('add_settings_error') ) { add_settings_error('etg_dfsb','profiles_json_invalid','Surface Profiles JSON is invalid; the previous valid profile snapshot was preserved.','error'); }
			return $this->previousProfilesJson();
		}
		if ( count( $decoded ) > 50 ) { if(function_exists('add_settings_error')){add_settings_error('etg_dfsb','profiles_limit','Surface Profiles JSON exceeds the 50-profile Alpha limit; the previous valid snapshot was preserved.','error');} return $this->previousProfilesJson(); }
		$encoded = json_encode( $decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $encoded ) ) { return $this->previousProfilesJson(); }
		return $encoded;
	}

	private function previousProfilesJson(): string {
		if ( function_exists('get_option') ) {
			$stored=get_option(self::OPTION_NAME,array());
			$previous=is_array($stored)?(string)($stored['profiles_json']??''):'';
			if(''!==$previous){$decoded=json_decode($previous,true);if(JSON_ERROR_NONE===json_last_error()&&is_array($decoded)){return $previous;}}
		}
		return $this->defaultProfilesJson();
	}

	private function boolValue( array $input, string $key, bool $default ): bool {
		if ( ! array_key_exists( $key, $input ) ) { return $default; }
		$value = $input[ $key ];
		if ( is_string( $value ) ) { return in_array( strtolower( $value ), array( '1', 'true', 'yes', 'on' ), true ); }
		return (bool) $value;
	}
	private function keyList( $value ): array { return $this->normalizeList( $value, 'sanitize_key' ); }
	private function slugList( $value ): array { return $this->normalizeList( $value, 'sanitize_title' ); }
	private function lineList( $value ): array { if ( is_string( $value ) ) { $value = preg_split( '/[\r\n]+/', $value ); } $value = is_array( $value ) ? $value : array(); $out=array(); foreach($value as $line){$line=strtolower(trim((string)$line)); if(''!==$line){$out[]=$line;}} return array_values(array_unique($out)); }
	private function normalizeList( $value, string $sanitizer ): array { if ( is_string( $value ) ) { $value = preg_split( '/[\r\n,]+/', $value ); } $value=is_array($value)?$value:array(); $out=array(); foreach($value as $item){$item=call_user_func($sanitizer,(string)$item); if(''!==$item){$out[]=$item;}} return array_values(array_unique($out)); }
	private function boundedInt( $value, int $min, int $max ): int { $value=is_numeric($value)?(int)$value:$min; return max($min,min($max,$value)); }
}

<?php
namespace ETG\DynamicFilterSEOBridge\Config;

require_once __DIR__ . '/ProfileRegistryCoreTrait.php';
require_once __DIR__ . '/ProfileRegistryNormalizationTrait.php';
require_once __DIR__ . '/ProfileRegistryHelperTrait.php';

final class ProfileRegistry {
	private const MAX_PROFILES = 50;
	private const MAX_ROUTES = 20;
	private const MAX_TAXONOMY_RULES = 50;
	private const MAX_COMBINATIONS = 500;

	private $config;
	private $profiles;
	private $normalizationErrors = array();

	use ProfileRegistryCoreTrait;
	use ProfileRegistryNormalizationTrait;
	use ProfileRegistryHelperTrait;

	private function narrowFilteredProfiles(array $base,array $proposal):array{
		$out=array();
		foreach($base as $id=>$baseProfile){
			if(!isset($proposal[$id])||!is_array($proposal[$id])){continue;}
			$candidate=$this->normalizeProfile($proposal[$id]);$n=$baseProfile;
			$n['enabled']=!empty($baseProfile['enabled'])&&!empty($candidate['enabled']);
			foreach(array('post_types','archive_paths','providers','query_ids','allowed_taxonomy_sets','indexable_combinations') as $key){$n[$key]=array_values(array_intersect((array)($baseProfile[$key]??array()),(array)($candidate[$key]??array())));}
			$baseRoutes=(array)($baseProfile['routes']??array());$candRoutes=(array)($candidate['routes']??array());$n['routes']=array_values(array_filter($baseRoutes,static function($route)use($candRoutes){return in_array($route,$candRoutes,true);}));
			$n['max_filters']=min((int)$baseProfile['max_filters'],(int)$candidate['max_filters']);
			$n['require_post_type_binding']=!empty($baseProfile['require_post_type_binding'])||!empty($candidate['require_post_type_binding']);$n['post_type_authority']=(string)$baseProfile['post_type_authority'];
			$n['require_provider_observation_for_index']=!empty($baseProfile['require_provider_observation_for_index'])||!empty($candidate['require_provider_observation_for_index']);
			$n['canonical_mode']=(string)$baseProfile['canonical_mode'];$n['composition_mode']=(string)$baseProfile['composition_mode'];
			$n['require_exact_combination_approval']=!empty($baseProfile['require_exact_combination_approval'])||!empty($candidate['require_exact_combination_approval']);$n['require_exact_for_single']=!empty($baseProfile['require_exact_for_single'])||!empty($candidate['require_exact_for_single']);
			$n['taxonomy_rules']=array();foreach((array)$baseProfile['taxonomy_rules'] as $taxonomy=>$rule){if(!isset($candidate['taxonomy_rules'][$taxonomy])){continue;}$c=(array)$candidate['taxonomy_rules'][$taxonomy];$r=$rule;$r['index_single']=!empty($rule['index_single'])&&!empty($c['index_single']);$r['min_results']=max((int)$rule['min_results'],(int)$c['min_results']);$n['taxonomy_rules'][$taxonomy]=$r;}
			$n['min_results_by_depth']=$baseProfile['min_results_by_depth'];foreach((array)$candidate['min_results_by_depth'] as $depth=>$min){$n['min_results_by_depth'][$depth]=max((int)($n['min_results_by_depth'][$depth]??1),(int)$min);}
			$n['content']=$baseProfile['content'];$n['content']['required']=!empty($baseProfile['content']['required'])||!empty($candidate['content']['required']);$n['content']['require_meta_description']=!empty($baseProfile['content']['require_meta_description'])||!empty($candidate['content']['require_meta_description']);$n['content']['min_chars']=max((int)$baseProfile['content']['min_chars'],(int)$candidate['content']['min_chars']);foreach(array('min_chars_by_depth','min_unique_segments_by_depth') as $key){foreach((array)($candidate['content'][$key]??array()) as $depth=>$min){$n['content'][$key][$depth]=max((int)($n['content'][$key][$depth]??0),(int)$min);}}
			$bp=(array)$baseProfile['publication'];$cp=(array)$candidate['publication'];$n['publication']=$bp;foreach(array('sitemap','hreflang','schema','social','include_images_in_sitemap','elementor_render_when_global_off') as $key){$n['publication'][$key]=!empty($bp[$key])&&!empty($cp[$key]);}$n['publication']['require_elementor_content']=!empty($bp['require_elementor_content'])||!empty($cp['require_elementor_content']);$n['publication']['require_result_count_parity_for_publication']=!empty($bp['require_result_count_parity_for_publication'])||!empty($cp['require_result_count_parity_for_publication']);$n['publication']['max_preview_urls']=min((int)$bp['max_preview_urls'],(int)$cp['max_preview_urls']);$n['publication']['max_publication_urls']=min((int)$bp['max_publication_urls'],(int)$cp['max_publication_urls']);
			$out[$id]=$this->normalizeProfile($n);
		}
		return $out;
	}

	public function fingerprintForRawProfile( array $profile ): string {
		$normalized = $this->normalizeProfile( $profile );
		return (string) ( $normalized['authority_fingerprint'] ?? self::authorityFingerprint( $normalized ) );
	}

	public static function authorityFingerprint( array $profile ): string {
		$copy = $profile;
		unset( $copy['enabled'], $copy['authority_fingerprint'] );
		if ( isset( $copy['publication'] ) && is_array( $copy['publication'] ) ) {
			foreach ( array(
				'elementor_content_verified', 'elementor_verification_evidence_id', 'elementor_verification_authority_fingerprint', 'elementor_evidence_current',
				'provider_observation_verified', 'provider_observation_evidence_id', 'provider_observation_authority_fingerprint', 'provider_observation_evidence_current',
				'result_count_parity_verified', 'result_count_parity_evidence_id', 'result_count_parity_authority_fingerprint', 'result_count_parity_evidence_current',
				'elementor_render_when_global_off', 'max_preview_urls', 'max_publication_urls'
			) as $key ) { unset( $copy['publication'][ $key ] ); }
		}
		$canonical = self::canonicalizeFingerprintValue( array('plugin_version'=>defined('ETG_DFSB_VERSION')?(string)ETG_DFSB_VERSION:'unknown','profile'=>$copy) );
		$encoded = function_exists( 'wp_json_encode' ) ? wp_json_encode( $canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) : json_encode( $canonical );
		return hash( 'sha256', (string) $encoded );
	}

	private static function canonicalizeFingerprintValue( $value ) {
		if ( ! is_array( $value ) ) { return $value; }
		if ( self::isListArray( $value ) ) {
			$out = array();
			foreach ( $value as $item ) { $out[] = self::canonicalizeFingerprintValue( $item ); }
			usort( $out, static function ( $a, $b ) { return strcmp( json_encode( $a ), json_encode( $b ) ); } );
			return $out;
		}
		ksort( $value, SORT_STRING );
		foreach ( $value as $key => $item ) { $value[ $key ] = self::canonicalizeFingerprintValue( $item ); }
		return $value;
	}

	private static function isListArray( array $value ): bool {
		if ( array() === $value ) { return true; }
		return array_keys( $value ) === range( 0, count( $value ) - 1 );
	}
}


<?php
namespace ETG\DynamicFilterSEOBridge\SEO;

use ETG\DynamicFilterSEOBridge\Config\Configuration;

final class CanonicalBuilder {
	private $config;
	public function __construct( Configuration $config ) { $this->config = $config; }
	public function build( array $context, $fallback = '' ): string {
		if ( empty( $context['in_scope'] ) || empty( $context['request_path'] ) ) { return is_string( $fallback ) ? $fallback : ''; }
		$profile = (array) ( $context['profile'] ?? array() );
		$mode = isset( $profile['canonical_mode'] ) ? (string) $profile['canonical_mode'] : (string) $this->config->get( 'canonical_mode', 'filtered' );
		if ( ! in_array( $mode, array( 'filtered', 'archive' ), true ) ) { $mode = 'filtered'; }
		$path=(string)$context['request_path'];
		if('archive'===$mode&&!empty($context['archive_path'])){$path=(string)$context['archive_path'];}
		if(function_exists('user_trailingslashit')){$path=user_trailingslashit('/'.ltrim($path,'/'));}
		$url=home_url($path);
		if('filtered'===$mode&&!empty($context['canonical_query_params'])){$url=add_query_arg((array)$context['canonical_query_params'],$url);}
		if(function_exists('apply_filters')){$url=(string)apply_filters('etg_filter_seo_canonical_url',$url,$context,$mode);}
		return esc_url_raw($url);
	}
}

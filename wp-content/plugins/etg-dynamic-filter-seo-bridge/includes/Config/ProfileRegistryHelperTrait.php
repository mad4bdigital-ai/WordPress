<?php
namespace ETG\DynamicFilterSEOBridge\Config;

require_once dirname( __DIR__ ) . '/Identifiers/QueryId.php';

use ETG\DynamicFilterSEOBridge\Identifiers\QueryId;

trait ProfileRegistryHelperTrait {
	private function canonicalArchiveAuthority( string $path ): string {
		$path = $this->normalizeArchivePath( $path );
		if ( '' === $path ) { return ''; }
		$without = $this->withoutLanguagePrefix( $path );
		return '' !== $without ? $without : $path;
	}

	private function archiveMatches( array $profile, string $archive, string $archivePath ): bool {
		foreach ( (array) ( $profile['archive_paths'] ?? array() ) as $authority ) {
			$authority = $this->normalizeArchivePath( (string) $authority );
			if ( '' !== $authority && $this->archivePathEqualsAuthority( $archivePath, $authority ) ) { return true; }
		}
		return false;
	}

	private function archivePathEqualsAuthority( string $archivePath, string $authority ): bool {
		$archivePath = $this->normalizeArchivePath( $archivePath );
		$authority = $this->normalizeArchivePath( $authority );
		if ( '' === $archivePath || '' === $authority ) { return false; }
		if ( $archivePath === $authority ) { return true; }
		// A language-specific authority is already exact. Never allow another
		// language prefix to wrap it (for example /en/it/archive/).
		if ( '' !== $this->withoutLanguagePrefix( $authority ) ) { return false; }
		$without = $this->withoutLanguagePrefix( $archivePath );
		return '' !== $without && $without === $authority;
	}

	private function withoutLanguagePrefix( string $path ): string {
		$path = $this->normalizeArchivePath( $path );
		$bits = array_values( array_filter( explode( '/', trim( $path, '/' ) ), 'strlen' ) );
		if ( count( $bits ) < 2 ) { return ''; }
		$first = strtolower( (string) $bits[0] );
		if ( ! $this->isLanguagePrefix( $first ) ) { return ''; }
		array_shift( $bits );
		return $this->normalizeArchivePath( '/' . implode( '/', $bits ) . '/' );
	}

	private function isLanguagePrefix( string $segment ): bool {
		$segment = strtolower( trim( $segment ) );
		if ( ! preg_match( '/^[a-z0-9_-]{2,24}$/', $segment ) ) { return false; }
		$codes = array();
		if ( function_exists( 'apply_filters' ) ) { $codes = (array) apply_filters( 'etg_filter_seo_language_codes', array(), $this->config ); }
		foreach ( $codes as $code ) {
			$code = strtolower( trim( (string) $code ) );
			if ( '' !== $code && $segment === $code && preg_match( '/^[a-z0-9_-]{2,24}$/', $code ) ) { return true; }
		}
		return false;
	}

	private function profileSupportsProvider( array $profile, string $provider ): bool {
		foreach ( (array) ( $profile['routes'] ?? array() ) as $route ) {
			if ( (string) ( $route['provider'] ?? '' ) === $provider ) { return true; }
		}
		return false;
	}

	private function profileSupportsRoute( array $profile, string $provider, string $queryId ): bool {
		foreach ( (array) ( $profile['routes'] ?? array() ) as $route ) {
			$routeQuery = (string) ( ( $route['provider_query_id'] ?? '' ) ?: ( $route['query_id'] ?? '' ) );
			if ( (string) ( $route['provider'] ?? '' ) === $provider && $routeQuery === $queryId ) { return true; }
		}
		return false;
	}

	private function routesValue( $value ): array {
		$out = array();
		foreach ( array_slice( (array) $value, 0, self::MAX_ROUTES ) as $route ) {
			if ( ! is_array( $route ) ) { continue; }
			$provider = sanitize_key( (string) ( $route['provider'] ?? '' ) );
			$providerQuery = QueryId::normalize( ( $route['provider_query_id'] ?? '' ) ?: ( $route['query_id'] ?? '' ) );
			$queryBuilderQuery = QueryId::normalize( $route['query_builder_query_id'] ?? '' );
			if ( '' === $provider || '' === $providerQuery ) { continue; }
			$normalized = array( 'provider'=>$provider, 'query_id'=>$providerQuery, 'provider_query_id'=>$providerQuery );
			if ( '' !== $queryBuilderQuery ) { $normalized['query_builder_query_id'] = $queryBuilderQuery; }
			$out[] = $normalized;
		}
		return array_values( array_unique( $out, SORT_REGULAR ) );
	}

	private function queryIdListValue( $value ): array {
		if ( is_string( $value ) ) { $value = preg_split( '/[\r\n,]+/', $value ); }
		$out = array();
		foreach ( (array) $value as $item ) {
			$item = QueryId::normalize( $item );
			if ( '' !== $item ) { $out[] = $item; }
		}
		return array_values( array_unique( $out ) );
	}

	private function normalizeQueryIdValue( $value ): string { return QueryId::normalize( $value ); }

	private function pathList( $value ): array {
		if ( is_string( $value ) ) { $value = preg_split( '/[\r\n,]+/', $value ); }
		$out = array();
		foreach ( (array) $value as $path ) { $path = $this->normalizeArchivePath( (string) $path ); if ( '' !== $path ) { $out[] = $path; } }
		return array_values( array_unique( $out ) );
	}

	private function normalizeArchivePath( string $path ): string {
		$path = parse_url( $path, PHP_URL_PATH );
		$path = is_string( $path ) ? rawurldecode( $path ) : '';
		$path = preg_replace( '#/+#u', '/', $path );
		$path = preg_replace( '#[^\p{L}\p{N}_\-/]#u', '', $path );
		if ( ! is_string( $path ) ) { return ''; }
		return '' === $path ? '' : '/' . trim( strtolower( $path ), '/' ) . '/';
	}

	private function resolution( bool $inScope, bool $valid, string $reason, array $profile = array() ): array {
		return array( 'in_scope'=>$inScope, 'scope_valid'=>$valid, 'reason'=>$reason, 'profile_id'=>(string)($profile['id']??''), 'profile'=>$profile, 'configuration_revision'=>$this->config->revision() );
	}

	private function fieldMapValue( $value ): array {
		$value = is_array( $value ) ? $value : array();
		$allowed = array( 'seo_title','meta_description','focus_keyword','short_description','image','gallery','location_level' );
		$out = array();
		foreach ( $allowed as $canonical ) {
			if ( ! array_key_exists( $canonical, $value ) ) { continue; }
			$fields = $this->listValue( $value[ $canonical ], 'sanitize_key' );
			if ( $fields ) { $out[ $canonical ] = array_slice( $fields, 0, 20 ); }
		}
		return $out;
	}

	private function enumValue( $value, array $allowed, string $default ): string { $value = sanitize_key( (string) $value ); return in_array( $value, $allowed, true ) ? $value : $default; }

	private function boolValue( $value ): bool { if ( is_string( $value ) ) { return in_array( strtolower( trim( $value ) ), array( '1','true','yes','on' ), true ); } return (bool) $value; }

	private function boundedInt( $value, int $min, int $max ): int { $value = is_numeric( $value ) ? (int) $value : $min; return max( $min, min( $max, $value ) ); }

	private function listValue( $value, string $sanitizer ): array { if ( is_string( $value ) ) { $value = preg_split( '/[\r\n,]+/', $value ); } $out=array(); foreach ( (array) $value as $item ) { $item=call_user_func($sanitizer,(string)$item); if(''!==$item){$out[]=$item;} } return array_values(array_unique($out)); }

	private function capabilityListValue( $value ): array {
		if ( is_string( $value ) ) { $value = preg_split( '/[\r\n,]+/', $value ); }
		$out = array();
		foreach ( array_slice( (array) $value, 0, self::MAX_REQUIRED_CAPABILITIES ) as $capability ) {
			$capability = strtolower( trim( (string) $capability ) );
			if ( strlen( $capability ) > 80 ) { $capability = substr( $capability, 0, 80 ); }
			$capability = preg_replace( '/[^a-z0-9._-]/', '', $capability );
			if ( is_string( $capability ) && '' !== $capability ) { $out[] = $capability; }
		}
		return array_values( array_unique( $out ) );
	}

	private function lineList( $value ): array { if ( is_string( $value ) ) { $value=preg_split('/[\r\n]+/',$value); } $out=array(); foreach((array)$value as $line){$line=strtolower(trim((string)$line)); if(''!==$line){$out[]=$line;}} return array_values(array_unique($out)); }

	private function normalizeTaxonomySet( $set ): string { if ( is_string( $set ) ) { $set = preg_split( '/[+;,]+/', $set ); } $items=array_values(array_filter(array_map('sanitize_key',(array)$set))); sort($items,SORT_STRING); return implode('+',array_unique($items)); }
}

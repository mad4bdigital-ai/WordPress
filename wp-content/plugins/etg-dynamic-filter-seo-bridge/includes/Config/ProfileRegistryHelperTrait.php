<?php
namespace ETG\DynamicFilterSEOBridge\Config;

trait ProfileRegistryHelperTrait {
	private function canonicalArchiveAuthority( string $path ): string {
		$path = $this->normalizeArchivePath( $path ); if ( '' === $path ) { return ''; }
		foreach ( $this->activeLanguageCodes() as $language ) {
			$prefix = '/' . $language . '/';
			if ( 0 === strpos( $path, $prefix ) ) { $path = $this->normalizeArchivePath( '/' . ltrim( substr( $path, strlen( $prefix ) ), '/' ) ); break; }
		}
		return $path;
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
		if ( '' === $archivePath || '' === $authority ) { return false; }
		if ( $archivePath === $authority ) { return true; }
		foreach ( $this->activeLanguageCodes() as $language ) {
			$prefix = '/' . $language . '/';
			if ( 0 === strpos( $archivePath, $prefix ) ) {
				$without = '/' . ltrim( substr( $archivePath, strlen( $prefix ) ), '/' );
				$without = $this->normalizeArchivePath( $without );
				if ( $without === $authority ) { return true; }
			}
		}
		return false;
	}

	private function activeLanguageCodes(): array {
		if ( ! function_exists( 'apply_filters' ) ) { return array(); }
		$languages = apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) );
		if ( ! is_array( $languages ) ) { return array(); }
		$out = array();
		foreach ( array_keys( $languages ) as $code ) { $code = sanitize_key( (string) $code ); if ( '' !== $code ) { $out[] = $code; } }
		return array_values( array_unique( $out ) );
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
			$providerQuery = sanitize_key( (string) ( ( $route['provider_query_id'] ?? '' ) ?: ( $route['query_id'] ?? '' ) ) );
			$queryBuilderQuery = sanitize_key( (string) ( $route['query_builder_query_id'] ?? '' ) );
			if ( '' === $provider || '' === $providerQuery ) { continue; }
			$normalized = array( 'provider'=>$provider, 'query_id'=>$providerQuery, 'provider_query_id'=>$providerQuery );
			if ( '' !== $queryBuilderQuery ) { $normalized['query_builder_query_id'] = $queryBuilderQuery; }
			$out[] = $normalized;
		}
		return array_values( array_unique( $out, SORT_REGULAR ) );
	}

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

	private function lineList( $value ): array { if ( is_string( $value ) ) { $value=preg_split('/[\r\n]+/',$value); } $out=array(); foreach((array)$value as $line){$line=strtolower(trim((string)$line)); if(''!==$line){$out[]=$line;}} return array_values(array_unique($out)); }

	private function normalizeTaxonomySet( $set ): string { if ( is_string( $set ) ) { $set = preg_split( '/[+;,]+/', $set ); } $items=array_values(array_filter(array_map('sanitize_key',(array)$set))); sort($items,SORT_STRING); return implode('+',array_unique($items)); }
}

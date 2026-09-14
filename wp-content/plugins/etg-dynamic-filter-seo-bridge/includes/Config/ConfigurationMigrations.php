<?php
namespace ETG\DynamicFilterSEOBridge\Config;

final class ConfigurationMigrations {
    public const CURRENT_SCHEMA_VERSION = 1;

    public static function project( array $stored, array $currentDefaults, array $legacyDefaults ): array {
        if ( ! $stored ) {
            $config = $currentDefaults;
            $config['schema_version'] = self::CURRENT_SCHEMA_VERSION;
            $config['migration_state'] = 'current';
            $config['compatibility_profile'] = '';
            return self::result( $config, 'fresh_install', array() );
        }

        $schema = isset( $stored['schema_version'] ) && is_numeric( $stored['schema_version'] ) ? (int) $stored['schema_version'] : 0;
        if ( $schema > self::CURRENT_SCHEMA_VERSION ) {
            $config = array_merge( $currentDefaults, $stored );
            $config['schema_version'] = $schema;
            $config['migration_state'] = 'future_schema_unsupported';
            $config['enabled'] = false;
            return self::result( $config, 'future_schema', array( 'future_schema_unsupported:' . $schema ) );
        }

        if ( self::CURRENT_SCHEMA_VERSION === $schema ) {
            $config = array_merge( $currentDefaults, $stored );
            $config['schema_version'] = self::CURRENT_SCHEMA_VERSION;
            $config['migration_state'] = (string) ( $stored['migration_state'] ?? 'current' );
            $config['compatibility_profile'] = self::compatibilityProfile( $stored['compatibility_profile'] ?? '' );
            return self::result( $config, 'current_schema', array() );
        }

        $classification = self::classifyLegacy( $stored );
        if ( 'etg' === $classification ) {
            $config = array_merge( $legacyDefaults, $stored );
            if ( ! self::validProfilesJson( $stored['profiles_json'] ?? '' ) ) {
                $config['profiles_json'] = (string) $legacyDefaults['profiles_json'];
            }
            $config['schema_version'] = self::CURRENT_SCHEMA_VERSION;
            $config['migration_state'] = 'legacy_etg_projected';
            $config['compatibility_profile'] = 'alpha13';
            return self::result( $config, 'legacy_etg', array() );
        }

        if ( 'generic' === $classification ) {
            $config = array_merge( $currentDefaults, $stored );
            $config['schema_version'] = self::CURRENT_SCHEMA_VERSION;
            $config['migration_state'] = 'legacy_generic_projected';
            $config['compatibility_profile'] = 'alpha13';
            return self::result( $config, 'legacy_generic', array() );
        }

        $config = array_merge( $currentDefaults, $stored );
        $config['schema_version'] = self::CURRENT_SCHEMA_VERSION;
        $config['migration_state'] = 'legacy_configuration_review_required';
        $config['compatibility_profile'] = 'alpha13';
        $config['enabled'] = false;
        if ( ! self::validProfilesJson( $stored['profiles_json'] ?? '' ) ) {
            $config['profiles_json'] = '[]';
        }
        return self::result( $config, 'legacy_ambiguous', array( 'legacy_configuration_review_required' ) );
    }

    public static function classifyLegacy( array $stored ): string {
        $profiles = self::decodedProfiles( $stored['profiles_json'] ?? '' );
        if ( $profiles ) {
            if ( self::profilesContainEtgSignature( $profiles ) ) { return 'etg'; }
            return 'generic';
        }

        $score = 0;
        if ( in_array( 'tours-and-activities', self::normalizeList( $stored['archive_slugs'] ?? array() ), true ) ) { $score++; }
        if ( in_array( 'jet-engine', self::normalizeList( $stored['providers'] ?? array() ), true ) ) { $score++; }
        if ( in_array( 'tours_query_archive', self::normalizeList( $stored['query_ids'] ?? array(), false ), true ) ) { $score++; }
        $taxonomies = self::normalizeList( $stored['allowed_taxonomies'] ?? array() );
        if ( ! array_diff( array( 'location_jet', 'tour-types_jet', 'tour-styles_jet' ), $taxonomies ) ) { $score += 2; }
        if ( isset( $stored['index_single_tour_type'] ) || isset( $stored['indexable_location_levels'] ) || isset( $stored['min_results_location'] ) ) { $score++; }
        if ( $score >= 3 ) { return 'etg'; }

        return 'ambiguous';
    }

    public static function validProfilesJson( $value ): bool {
        if ( is_array( $value ) ) { return true; }
        $raw = trim( (string) $value );
        if ( '' === $raw || strlen( $raw ) > 1000000 ) { return false; }
        $decoded = json_decode( $raw, true );
        return JSON_ERROR_NONE === json_last_error() && is_array( $decoded );
    }

    private static function decodedProfiles( $value, bool $allowEmpty = false ): array {
        if ( is_array( $value ) ) { $decoded = $value; }
        else {
            $raw = trim( (string) $value );
            if ( '' === $raw || strlen( $raw ) > 1000000 ) { return array(); }
            $decoded = json_decode( $raw, true );
            if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) { return array(); }
        }
        if ( ! $decoded && ! $allowEmpty ) { return array(); }
        return $decoded;
    }

    private static function profilesContainEtgSignature( array $profiles ): bool {
        foreach ( $profiles as $profile ) {
            if ( ! is_array( $profile ) ) { continue; }
            $score = 0;
            if ( 'tours' === self::cleanKey( $profile['id'] ?? '' ) ) { $score += 2; }
            if ( 'travel' === self::cleanKey( $profile['composition_mode'] ?? '' ) ) { $score++; }
            if ( in_array( '/tours-and-activities/', array_map( array( __CLASS__, 'normalizePath' ), (array) ( $profile['archive_paths'] ?? array() ) ), true ) ) { $score++; }
            if ( in_array( 'tours_query_archive', self::normalizeList( $profile['query_ids'] ?? array(), false ), true ) ) { $score++; }
            $rules = array_map( array( __CLASS__, 'cleanKey' ), array_keys( (array) ( $profile['taxonomy_rules'] ?? array() ) ) );
            if ( ! array_diff( array( 'location_jet', 'tour-types_jet', 'tour-styles_jet' ), $rules ) ) { $score += 2; }
            if ( $score >= 3 ) { return true; }
        }
        return false;
    }

    private static function compatibilityProfile( $value ): string {
        $value = self::cleanKey( $value );
        return 'alpha13' === $value ? $value : '';
    }

    private static function result( array $config, string $source, array $errors ): array {
        return array(
            'config' => $config,
            'source' => $source,
            'errors' => array_values( array_unique( array_filter( $errors, 'strlen' ) ) ),
        );
    }

    private static function normalizeList( $value, bool $lower = true ): array {
        if ( is_string( $value ) ) { $value = preg_split( '/[\r\n,]+/', $value ); }
        $out = array();
        foreach ( (array) $value as $item ) {
            $item = trim( (string) $item );
            if ( $lower ) { $item = strtolower( $item ); }
            if ( '' !== $item ) { $out[] = $item; }
        }
        return array_values( array_unique( $out ) );
    }

    private static function cleanKey( $value ): string {
        $value = strtolower( trim( (string) $value ) );
        return preg_replace( '/[^a-z0-9_\-]/', '', $value ) ?: '';
    }

    private static function normalizePath( $value ): string {
        $value = trim( (string) $value );
        if ( '' === $value ) { return ''; }
        $path = parse_url( $value, PHP_URL_PATH );
        $path = is_string( $path ) ? $path : $value;
        return '/' . trim( $path, '/' ) . '/';
    }
}

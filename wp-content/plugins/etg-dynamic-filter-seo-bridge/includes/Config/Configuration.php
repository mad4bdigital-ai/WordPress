<?php
namespace ETG\DynamicFilterSEOBridge\Config;

require_once dirname( __DIR__ ) . '/Identifiers/QueryId.php';
require_once __DIR__ . '/ConfigurationMigrations.php';

use ETG\DynamicFilterSEOBridge\Identifiers\QueryId;

final class Configuration {
    public const OPTION_NAME = 'etg_dfsb_settings';

    public function defaults(): array {
        $defaults = $this->defaultsWithoutFilters();
        return function_exists( 'apply_filters' ) ? (array) apply_filters( 'etg_filter_seo_configuration_defaults', $defaults ) : $defaults;
    }

    public function all(): array {
        $stored = $this->storedRaw();
        $projection = ConfigurationMigrations::project( $stored, $this->defaults(), $this->legacyDefaultsWithoutFilters() );
        $source = (string) ( $projection['source'] ?? '' );
        $config = $this->sanitize( (array) ( $projection['config'] ?? array() ) );

        // Only a positively identified ETG Alpha13 installation may expose the
        // legacy compatibility fingerprint. Generic/ambiguous legacy data stays
        // preserved without becoming ETG merely because the old option key exists.
        if ( in_array( $source, array( 'legacy_generic', 'legacy_ambiguous' ), true ) ) {
            $config['compatibility_profile'] = '';
        }
        if ( 'legacy_ambiguous' === $source ) {
            $config['migration_state'] = 'legacy_configuration_review_required';
            $config['enabled'] = false;
        }
        if ( 'future_schema' === $source ) {
            $config['migration_state'] = 'future_schema_unsupported';
            $config['enabled'] = false;
        }

        if ( function_exists( 'apply_filters' ) ) {
            $immutable = array(
                'schema_version' => $config['schema_version'],
                'migration_state' => $config['migration_state'],
                'compatibility_profile' => $config['compatibility_profile'],
                'data_retention' => $config['data_retention'],
            );
            $filtered = (array) apply_filters( 'etg_filter_seo_configuration', $config );
            $config = $this->sanitize( array_merge( $config, $filtered, $immutable ) );
            if ( 'legacy_ambiguous' === $source || 'future_schema' === $source ) { $config['enabled'] = false; }
            if ( in_array( $source, array( 'legacy_generic', 'legacy_ambiguous' ), true ) ) { $config['compatibility_profile'] = ''; }
        }
        return $config;
    }

    public function get( string $key, $default = null ) {
        $config = $this->all();
        return array_key_exists( $key, $config ) ? $config[ $key ] : $default;
    }

    public function enabled(): bool { return (bool) $this->get( 'enabled', false ); }

    public function migrationStatus(): array {
        $config = $this->all();
        $state = (string) ( $config['migration_state'] ?? 'current' );
        return array(
            'contract' => 'etg.dfsb.configuration-migration-status.v1',
            'schema_version' => (int) ( $config['schema_version'] ?? ConfigurationMigrations::CURRENT_SCHEMA_VERSION ),
            'supported_schema_version' => ConfigurationMigrations::CURRENT_SCHEMA_VERSION,
            'state' => $state,
            'compatibility_profile' => (string) ( $config['compatibility_profile'] ?? '' ),
            'data_retention' => (string) ( $config['data_retention'] ?? 'preserve' ),
            'requires_resolution' => 'legacy_configuration_review_required' === $state,
            'future_schema_unsupported' => 'future_schema_unsupported' === $state,
        );
    }

    public function revision(): string {
        $encoded = function_exists( 'wp_json_encode' ) ? wp_json_encode( $this->all() ) : json_encode( $this->all() );
        return substr( hash( 'sha256', (string) $encoded ), 0, 16 );
    }

    /** Runtime-safe normalization. This method never writes persistent state. */
    public function sanitize( $input ): array {
        $input = is_array( $input ) ? $input : array();
        $d = $this->defaultsWithoutFilters();
        $out = array();
        $schema = isset( $input['schema_version'] ) && is_numeric( $input['schema_version'] ) ? max( 0, (int) $input['schema_version'] ) : ConfigurationMigrations::CURRENT_SCHEMA_VERSION;
        $out['schema_version'] = $schema;
        $out['migration_state'] = $this->migrationState( $input['migration_state'] ?? 'current' );
        $out['compatibility_profile'] = 'alpha13' === $this->cleanKey( $input['compatibility_profile'] ?? '' ) ? 'alpha13' : '';
        $retention = $this->cleanKey( $input['data_retention'] ?? $d['data_retention'] );
        $out['data_retention'] = in_array( $retention, array( 'preserve', 'delete_on_uninstall' ), true ) ? $retention : 'preserve';
        $out['enabled'] = $this->boolValue( $input, 'enabled', $d['enabled'] );
        $out['archive_slugs'] = $this->slugList( $input['archive_slugs'] ?? $d['archive_slugs'] );
        $out['providers'] = $this->keyList( $input['providers'] ?? $d['providers'] );
        $out['query_ids'] = $this->queryIdList( $input['query_ids'] ?? $d['query_ids'] );
        $out['allowed_taxonomies'] = $this->keyList( $input['allowed_taxonomies'] ?? $d['allowed_taxonomies'] );
        $out['max_filters'] = $this->boundedInt( $input['max_filters'] ?? $d['max_filters'], 1, 10 );
        $out['allowed_query_params'] = $this->keyList( $input['allowed_query_params'] ?? $d['allowed_query_params'] );
        $out['tracking_query_params'] = $this->keyList( $input['tracking_query_params'] ?? $d['tracking_query_params'] );
        $out['enable_jet_engine_result_count_adapter'] = $this->boolValue( $input, 'enable_jet_engine_result_count_adapter', $d['enable_jet_engine_result_count_adapter'] );
        $out['trust_legacy_result_count'] = $this->boolValue( $input, 'trust_legacy_result_count', $d['trust_legacy_result_count'] );
        $out['require_result_count_for_index'] = $this->boolValue( $input, 'require_result_count_for_index', $d['require_result_count_for_index'] );
        $out['require_provider_observation_for_index'] = $this->boolValue( $input, 'require_provider_observation_for_index', $d['require_provider_observation_for_index'] );
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
        $canonical = $this->cleanKey( $input['canonical_mode'] ?? $d['canonical_mode'] );
        $out['canonical_mode'] = in_array( $canonical, array( 'filtered', 'archive' ), true ) ? $canonical : 'filtered';
        $out['publication_max_urls'] = $this->boundedInt( $input['publication_max_urls'] ?? $d['publication_max_urls'], 1, 500 );
        $out['publication_cache_ttl'] = $this->boundedInt( $input['publication_cache_ttl'] ?? $d['publication_cache_ttl'], 300, 86400 );
        $out['diagnostics_enabled'] = $this->boolValue( $input, 'diagnostics_enabled', $d['diagnostics_enabled'] );
        $out['log_decisions'] = $this->boolValue( $input, 'log_decisions', $d['log_decisions'] );
        $out['profiles_json'] = $this->profilesJson( $input['profiles_json'] ?? $d['profiles_json'] );
        if ( $schema > ConfigurationMigrations::CURRENT_SCHEMA_VERSION || 'future_schema_unsupported' === $out['migration_state'] ) {
            $out['migration_state'] = 'future_schema_unsupported';
            $out['enabled'] = false;
        }
        if ( 'legacy_configuration_review_required' === $out['migration_state'] ) { $out['enabled'] = false; }
        return $out;
    }

    /** Settings API callback. It is the only configuration path allowed to resolve migration state. */
    public function sanitizeForStorage( $input ): array {
        $input = is_array( $input ) ? $input : array();
        $stored = $this->storedRaw();
        $storedSchema = isset( $stored['schema_version'] ) && is_numeric( $stored['schema_version'] ) ? (int) $stored['schema_version'] : 0;
        if ( $storedSchema > ConfigurationMigrations::CURRENT_SCHEMA_VERSION ) {
            // Older code must never overwrite or downgrade a future schema.
            return $stored;
        }

        $resolution = $this->cleanKey( $input['migration_resolution'] ?? '' );
        unset( $input['migration_resolution'] );
        $classification = $stored && 0 === $storedSchema ? ConfigurationMigrations::classifyLegacy( $stored ) : '';

        if ( 'ambiguous' === $classification ) {
            if ( 'use_alpha13' === $resolution ) {
                $candidate = array_merge( $this->legacyDefaultsWithoutFilters(), $stored, $input );
                $candidate['schema_version'] = ConfigurationMigrations::CURRENT_SCHEMA_VERSION;
                $candidate['migration_state'] = 'current';
                $candidate['compatibility_profile'] = 'alpha13';
                return $this->sanitize( $candidate );
            }
            if ( 'preserve_generic' === $resolution ) {
                $candidate = array_merge( $this->defaultsWithoutFilters(), $stored, $input );
                $candidate['schema_version'] = ConfigurationMigrations::CURRENT_SCHEMA_VERSION;
                $candidate['migration_state'] = 'current';
                $candidate['compatibility_profile'] = '';
                return $this->sanitize( $candidate );
            }
            $candidate = array_merge( $this->defaultsWithoutFilters(), $stored, $input );
            $candidate['schema_version'] = ConfigurationMigrations::CURRENT_SCHEMA_VERSION;
            $candidate['migration_state'] = 'legacy_configuration_review_required';
            $candidate['compatibility_profile'] = '';
            $candidate['enabled'] = false;
            return $this->sanitize( $candidate );
        }

        if ( 'etg' === $classification ) {
            $candidate = array_merge( $this->legacyDefaultsWithoutFilters(), $stored, $input );
            if ( ! ConfigurationMigrations::validProfilesJson( $stored['profiles_json'] ?? '' ) ) { $candidate['profiles_json'] = $this->legacyProfilesJson(); }
            $candidate['schema_version'] = ConfigurationMigrations::CURRENT_SCHEMA_VERSION;
            $candidate['migration_state'] = 'legacy_etg_projected';
            $candidate['compatibility_profile'] = 'alpha13';
            return $this->sanitize( $candidate );
        }

        if ( 'generic' === $classification ) {
            $candidate = array_merge( $this->defaultsWithoutFilters(), $stored, $input );
            $candidate['schema_version'] = ConfigurationMigrations::CURRENT_SCHEMA_VERSION;
            $candidate['migration_state'] = 'legacy_generic_projected';
            $candidate['compatibility_profile'] = '';
            return $this->sanitize( $candidate );
        }

        $candidate = array_merge( $this->defaultsWithoutFilters(), $stored, $input );
        $candidate['schema_version'] = ConfigurationMigrations::CURRENT_SCHEMA_VERSION;
        $candidate['migration_state'] = $this->migrationState( $stored['migration_state'] ?? 'current' );
        if ( 'legacy_etg_projected' !== $candidate['migration_state'] ) { $candidate['migration_state'] = 'current'; }
        $candidate['compatibility_profile'] = 'alpha13' === $this->cleanKey( $stored['compatibility_profile'] ?? $input['compatibility_profile'] ?? '' ) ? 'alpha13' : '';
        return $this->sanitize( $candidate );
    }

    public function validationErrors(): array {
        $config = $this->all();
        if ( 'future_schema_unsupported' === (string) ( $config['migration_state'] ?? '' ) ) { return array( 'future_schema_unsupported' ); }
        if ( 'legacy_configuration_review_required' === (string) ( $config['migration_state'] ?? '' ) ) { return array( 'legacy_configuration_review_required' ); }
        $raw = (string) ( $config['profiles_json'] ?? '' );
        $decoded = json_decode( $raw, true );
        if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) { return array( 'profiles_json_invalid' ); }
        if ( ! empty( $config['enabled'] ) && empty( $decoded ) ) { return array( 'profiles_empty_or_invalid' ); }
        return array();
    }

    private function defaultsWithoutFilters(): array {
        return array(
            'schema_version' => ConfigurationMigrations::CURRENT_SCHEMA_VERSION,
            'migration_state' => 'current',
            'compatibility_profile' => '',
            'data_retention' => 'preserve',
            'enabled' => false,
            'archive_slugs' => array(),
            'providers' => array(),
            'query_ids' => array(),
            'allowed_taxonomies' => array(),
            'max_filters' => 3,
            'allowed_query_params' => array(),
            'tracking_query_params' => array( 'gclid', 'fbclid', 'msclkid' ),
            'enable_jet_engine_result_count_adapter' => false,
            'trust_legacy_result_count' => false,
            'require_result_count_for_index' => false,
            'require_provider_observation_for_index' => false,
            'min_results_location' => 1,
            'min_results_pair' => 3,
            'min_results_triple' => 3,
            'index_single_tour_type' => false,
            'indexable_location_levels' => array(),
            'require_exact_combination_approval' => true,
            'indexable_combinations' => array(),
            'require_content_readiness' => false,
            'require_meta_description' => false,
            'min_content_chars' => 0,
            'canonical_mode' => 'filtered',
            'publication_max_urls' => 100,
            'publication_cache_ttl' => 21600,
            'diagnostics_enabled' => true,
            'log_decisions' => false,
            'profiles_json' => '[]',
        );
    }

    private function legacyDefaultsWithoutFilters(): array {
        $defaults = $this->defaultsWithoutFilters();
        return array_merge( $defaults, array(
            'archive_slugs' => array( 'tours-and-activities' ),
            'providers' => array( 'jet-engine' ),
            'query_ids' => array( 'tours_query_archive' ),
            'allowed_taxonomies' => array( 'location_jet', 'tour-types_jet', 'tour-styles_jet' ),
            'enable_jet_engine_result_count_adapter' => true,
            'require_result_count_for_index' => true,
            'require_provider_observation_for_index' => true,
            'indexable_location_levels' => array( 'city', 'landmark' ),
            'require_content_readiness' => true,
            'require_meta_description' => true,
            'min_content_chars' => 250,
            'profiles_json' => $this->legacyProfilesJson(),
        ) );
    }

    private function legacyProfilesJson(): string {
        $profiles = array(
            array(
                'id'=>'tours','enabled'=>false,'inherit_global_defaults'=>true,'post_types'=>array(),'require_post_type_binding'=>false,'post_type_authority'=>'query_builder','require_provider_observation_for_index'=>true,
                'archive_slugs'=>array('tours-and-activities'),'archive_paths'=>array('/tours-and-activities/'),'providers'=>array('jet-engine'),'query_ids'=>array('tours_query_archive'),'routes'=>array(array('provider'=>'jet-engine','query_id'=>'tours_query_archive')),
                'max_filters'=>3,'composition_mode'=>'travel','canonical_mode'=>'filtered','require_exact_combination_approval'=>true,'require_exact_for_single'=>false,
                'allowed_taxonomy_sets'=>array('location_jet','location_jet+tour-types_jet','location_jet+tour-types_jet+tour-styles_jet'),'min_results_by_depth'=>array('1'=>1,'2'=>3,'3'=>3),
                'taxonomy_rules'=>array(
                    'location_jet'=>array('role'=>'location','priority'=>10,'gallery_priority'=>20,'index_single'=>true,'min_results'=>1,'required_meta_key'=>'location_level','required_meta_values'=>array('city','landmark'),'meta_constraint_scope'=>'single'),
                    'tour-types_jet'=>array('role'=>'tour_type','priority'=>20,'gallery_priority'=>30,'index_single'=>false,'min_results'=>3),
                    'tour-styles_jet'=>array('role'=>'style','priority'=>30,'gallery_priority'=>10,'index_single'=>false,'min_results'=>3),
                ),
                'indexable_combinations'=>array(),
                'content'=>array('required'=>true,'require_meta_description'=>true,'min_chars'=>250,'min_chars_by_depth'=>array('1'=>250,'2'=>400,'3'=>500),'min_unique_segments_by_depth'=>array('1'=>1,'2'=>2,'3'=>2)),
                'publication'=>array('metadata'=>true,'sitemap'=>true,'multilingual'=>true,'hreflang'=>true,'schema'=>true,'social'=>true,'include_images_in_sitemap'=>true,'require_elementor_content'=>true,'elementor_render_when_global_off'=>false,'elementor_content_verified'=>false,'elementor_verification_evidence_id'=>'','provider_observation_verified'=>false,'provider_observation_evidence_id'=>'','require_result_count_parity_for_publication'=>true,'result_count_parity_verified'=>false,'result_count_parity_evidence_id'=>'','max_preview_urls'=>50,'max_publication_urls'=>100),
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
            else { $decoded=json_decode($value,true); if(JSON_ERROR_NONE!==json_last_error()||!is_array($decoded)){$valid=false;$decoded=array();} }
        }
        if ( ! $valid ) {
            if ( function_exists('add_settings_error') ) { add_settings_error('etg_dfsb','profiles_json_invalid','Surface Profiles JSON is invalid; the previous valid profile snapshot was preserved.','error'); }
            return $this->previousProfilesJson();
        }
        if ( count( $decoded ) > 50 ) {
            if(function_exists('add_settings_error')){add_settings_error('etg_dfsb','profiles_limit','Surface Profiles JSON exceeds the 50-profile limit; the previous valid snapshot was preserved.','error');}
            return $this->previousProfilesJson();
        }
        $encoded=json_encode($decoded,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        return is_string($encoded)?$encoded:$this->previousProfilesJson();
    }

    private function previousProfilesJson(): string {
        $stored=$this->storedRaw();
        $previous=(string)($stored['profiles_json']??'');
        if(''!==$previous){$decoded=json_decode($previous,true);if(JSON_ERROR_NONE===json_last_error()&&is_array($decoded)){return$previous;}}
        return '[]';
    }

    private function storedRaw(): array {
        if ( ! function_exists( 'get_option' ) ) return array();
        $stored=get_option(self::OPTION_NAME,array());
        return is_array($stored)?$stored:array();
    }

    private function migrationState( $value ): string {
        $value=$this->cleanKey($value);
        $allowed=array('current','legacy_etg_projected','legacy_generic_projected','legacy_configuration_review_required','future_schema_unsupported');
        return in_array($value,$allowed,true)?$value:'current';
    }

    private function boolValue( array $input, string $key, bool $default ): bool {
        if ( ! array_key_exists( $key, $input ) ) return $default;
        $value=$input[$key];
        if(is_string($value))return in_array(strtolower($value),array('1','true','yes','on'),true);
        return(bool)$value;
    }
    private function keyList($value):array{return$this->normalizeList($value,'key');}
    private function slugList($value):array{return$this->normalizeList($value,'slug');}
    private function queryIdList($value):array{
        if(is_string($value))$value=preg_split('/[\r\n,]+/',$value);$value=is_array($value)?$value:array();$out=array();
        foreach($value as$item){$item=QueryId::normalize($item);if(''!==$item)$out[]=$item;}return array_values(array_unique($out));
    }
    private function lineList($value):array{if(is_string($value))$value=preg_split('/[\r\n]+/',$value);$value=is_array($value)?$value:array();$out=array();foreach($value as$line){$line=strtolower(trim((string)$line));if(''!==$line)$out[]=$line;}return array_values(array_unique($out));}
    private function normalizeList($value,string$type):array{
        if(is_string($value))$value=preg_split('/[\r\n,]+/',$value);$value=is_array($value)?$value:array();$out=array();
        foreach($value as$item){$item='slug'===$type?$this->cleanSlug($item):$this->cleanKey($item);if(''!==$item)$out[]=$item;}return array_values(array_unique($out));
    }
    private function cleanKey($value):string{if(function_exists('sanitize_key'))return sanitize_key((string)$value);return preg_replace('/[^a-z0-9_\-]/','',strtolower(trim((string)$value)))?:'';}
    private function cleanSlug($value):string{if(function_exists('sanitize_title'))return sanitize_title((string)$value);$value=strtolower(trim((string)$value));$value=preg_replace('/[^a-z0-9_\-]+/','-',$value);return trim((string)$value,'-');}
    private function boundedInt($value,int$min,int$max):int{$value=is_numeric($value)?(int)$value:$min;return max($min,min($max,$value));}
}

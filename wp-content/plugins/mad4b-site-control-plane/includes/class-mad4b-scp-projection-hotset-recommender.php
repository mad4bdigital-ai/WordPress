<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Read-only telemetry-driven hot-set recommendations.
 *
 * Rankings are advisory only. They cannot mutate projection state, create
 * authority, or recommend privileged/mutating/breakglass abilities.
 */
final class MAD4B_SCP_Projection_Hotset_Recommender {
	const CONTRACT = 'mad4b.projection-hotset-recommender.v1';
	const METRIC_DOMAIN = 'mad4b.projection-hotset-metric.v1';
	const DEFAULT_QUOTA = 8;
	const MAX_QUOTA = 12;
	const DEFAULT_HOURS = 24;
	const MAX_HOURS = 168;
	const MAX_CANDIDATES = 1000;

	public static function metric_name( $ability_name ) {
		$name = trim( (string) $ability_name );
		if ( '' === $name || strlen( $name ) > 191 ) return '';
		return 'projection.hotset.use.' . substr( hash( 'sha256', self::METRIC_DOMAIN . '|' . $name ), 0, 32 );
	}

	public static function record_usage( $ability_name, $source = 'fixed_dispatch' ) {
		$ability_name = trim( (string) $ability_name );
		$source = sanitize_key( (string) $source );
		if ( ! in_array( $source, array( 'fixed_dispatch', 'direct_projection' ), true ) ) {
			return new WP_Error( 'mad4b_projection_hotset_metric_source_invalid', 'Hot-set telemetry source is invalid.' );
		}
		if ( '' === $ability_name || ! function_exists( 'wp_has_ability' ) || ! wp_has_ability( $ability_name ) ) {
			return new WP_Error( 'mad4b_projection_hotset_metric_ability_invalid', 'Hot-set telemetry requires a registered Ability identity.' );
		}
		$metric = self::metric_name( $ability_name );
		if ( '' === $metric || ! class_exists( 'MAD4B_SCP_Runtime_Metrics' ) ) {
			return new WP_Error( 'mad4b_projection_hotset_metrics_unavailable', 'Hot-set telemetry storage is unavailable.' );
		}
		$combined = MAD4B_SCP_Runtime_Metrics::record( $metric, 1 );
		if ( is_wp_error( $combined ) ) return $combined;
		$source_metric = 'projection.hotset.' . ( 'direct_projection' === $source ? 'direct' : 'fixed' ) . '.' . substr( hash( 'sha256', self::METRIC_DOMAIN . '|' . $ability_name ), 0, 32 );
		$source_result = MAD4B_SCP_Runtime_Metrics::record( $source_metric, 1 );
		return array(
			'contract' => self::CONTRACT,
			'ability_name' => $ability_name,
			'source' => $source,
			'metric_name' => $metric,
			'combined_recorded' => true,
			'source_recorded' => ! is_wp_error( $source_result ),
			'authority_effect' => 'none',
			'authorizing' => false,
		);
	}

	public static function recommend( $quota = self::DEFAULT_QUOTA, $hours = self::DEFAULT_HOURS ) {
		$quota = max( 1, min( self::MAX_QUOTA, absint( $quota ) ) );
		$hours = max( 1, min( self::MAX_HOURS, absint( $hours ) ) );
		if ( ! class_exists( 'MAD4B_SCP_ChatGPT_Tool_Projection' ) || ! class_exists( 'MAD4B_SCP_Runtime_Metrics' ) ) {
			return new WP_Error( 'mad4b_projection_hotset_recommender_unavailable', 'Projection or runtime metrics service is unavailable.' );
		}
		$universe = MAD4B_SCP_ChatGPT_Tool_Projection::all_site_ability_names();
		if ( count( $universe ) > self::MAX_CANDIDATES ) {
			return new WP_Error(
				'mad4b_projection_hotset_candidate_budget_exceeded',
				'Registered Ability universe exceeds the bounded recommendation scan budget.',
				array( 'candidate_count' => count( $universe ), 'max_candidates' => self::MAX_CANDIDATES )
			);
		}
		$base = class_exists( 'MAD4B_SCP_Servers' ) ? MAD4B_SCP_Servers::chatgpt_base_tools() : array();
		$current = array_flip( MAD4B_SCP_ChatGPT_Tool_Projection::projected_ability_names() );
		$candidates = array();
		$metric_to_ability = array();
		$excluded = array( 'base_tool'=>0, 'not_projection_eligible'=>0, 'privileged_or_mutating'=>0, 'breakglass'=>0, 'invalid'=>0 );
		foreach ( $universe as $ability_name ) {
			$ability_name = (string) $ability_name;
			if ( in_array( $ability_name, $base, true ) ) { ++$excluded['base_tool']; continue; }
			$row = MAD4B_SCP_ChatGPT_Tool_Projection::describe_ability( $ability_name );
			if ( is_wp_error( $row ) || ! is_array( $row ) ) { ++$excluded['invalid']; continue; }
			if ( empty( $row['projection_eligible'] ) ) { ++$excluded['not_projection_eligible']; continue; }
			if ( ! empty( $row['breakglass'] ) ) { ++$excluded['breakglass']; continue; }
			if ( 'read' !== ( isset( $row['lane'] ) ? (string) $row['lane'] : '' ) || empty( $row['readonly'] ) ) {
				++$excluded['privileged_or_mutating']; continue;
			}
			$metric = self::metric_name( $ability_name );
			if ( '' === $metric ) { ++$excluded['invalid']; continue; }
			$metric_to_ability[ $metric ] = $ability_name;
			$candidates[ $ability_name ] = array(
				'ability_name' => $ability_name,
				'metric_name' => $metric,
				'usage_count' => 0,
				'currently_projected' => isset( $current[ $ability_name ] ),
				'input_schema_sha256' => isset( $row['input_schema_sha256'] ) ? (string) $row['input_schema_sha256'] : '',
				'classification_sha256' => isset( $row['classification_sha256'] ) ? (string) $row['classification_sha256'] : '',
				'lane' => 'read',
				'readonly' => true,
			);
		}
		$metric_names = array_keys( $metric_to_ability );
		for ( $offset = 0; $offset < count( $metric_names ); $offset += MAD4B_SCP_Runtime_Metrics::MAX_NAMES ) {
			$batch = array_slice( $metric_names, $offset, MAD4B_SCP_Runtime_Metrics::MAX_NAMES );
			$summary = MAD4B_SCP_Runtime_Metrics::summary( $batch, $hours );
			if ( is_wp_error( $summary ) ) return $summary;
			foreach ( isset( $summary['metrics'] ) && is_array( $summary['metrics'] ) ? $summary['metrics'] : array() as $metric ) {
				$name = isset( $metric['name'] ) ? (string) $metric['name'] : '';
				if ( ! isset( $metric_to_ability[ $name ] ) ) continue;
				$ability = $metric_to_ability[ $name ];
				$candidates[ $ability ]['usage_count'] = max( 0, (int) ( isset( $metric['count'] ) ? $metric['count'] : 0 ) );
			}
		}
		$ranked = array_values( array_filter( $candidates, static function ( $row ) { return (int) $row['usage_count'] > 0; } ) );
		usort( $ranked, static function ( $a, $b ) {
			if ( (int) $a['usage_count'] === (int) $b['usage_count'] ) return strcmp( (string) $a['ability_name'], (string) $b['ability_name'] );
			return (int) $a['usage_count'] > (int) $b['usage_count'] ? -1 : 1;
		} );
		$recommended = array_slice( $ranked, 0, $quota );
		$recommended_names = array_map( static function ( $row ) { return (string) $row['ability_name']; }, $recommended );
		$additions = array_values( array_filter( $recommended_names, static function ( $name ) use ( $current ) { return ! isset( $current[ $name ] ); } ) );
		return array(
			'contract' => self::CONTRACT,
			'hours' => $hours,
			'quota' => $quota,
			'candidate_budget' => self::MAX_CANDIDATES,
			'candidate_count' => count( $candidates ),
			'recommended_abilities' => $recommended,
			'recommended_names' => $recommended_names,
			'suggested_additions' => $additions,
			'excluded' => $excluded,
			'ranking_signal' => 'successful_fixed_dispatch_or_final_direct_admission_count',
			'auto_apply' => false,
			'projection_mutation_performed' => false,
			'privileged_candidates_excluded' => true,
			'breakglass_candidates_excluded' => true,
			'fixed_dispatch_correctness_independent' => true,
			'authority_effect' => 'none',
			'read_only' => true,
			'mutation_performed' => false,
			'authorizing' => false,
		);
	}
}

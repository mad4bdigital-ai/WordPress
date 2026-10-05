<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Deny-only request abuse budgets.
 *
 * Rate buckets are bound to live site/principal/client fingerprints and use the
 * existing transactional metric_buckets table as an atomic counter substrate.
 * They never grant authority, never relax policy, and never trust client supplied
 * identity fields. Complexity checks run before rate accounting.
 */
final class MAD4B_SCP_Abuse_Budget {
	const CONTRACT = 'mad4b.abuse-budget.v1';
	const METRIC_PREFIX = 'abuse.';
	private static $config = null;

	public static function clear_cache() { self::$config = null; }

	public static function policy() {
		$config = self::config();
		if ( is_wp_error( $config ) ) return $config;
		$out = $config;
		$out['authorizing'] = false;
		$out['identity_source'] = 'live_site_principal_client_context';
		$out['counter_storage'] = 'transactional_metric_buckets';
		return $out;
	}

	public static function admit( $surface, $input = array() ) {
		$surface = sanitize_key( (string) $surface );
		$config = self::config();
		if ( is_wp_error( $config ) ) return $config;
		if ( ! isset( $config['surfaces'][ $surface ] ) || ! is_array( $config['surfaces'][ $surface ] ) ) {
			return new WP_Error( 'mad4b_abuse_surface_unknown', 'Abuse-budget surface is not certified.', array( 'surface'=>$surface ) );
		}
		$limits = $config['surfaces'][ $surface ];
		$complexity = self::inspect_complexity( $input, $limits );
		if ( is_wp_error( $complexity ) ) return $complexity;
		$identity = self::identity();
		if ( is_wp_error( $identity ) ) return $identity;
		$rate = self::consume_rate( $surface, $identity, $limits, (int)$config['window_seconds'] );
		if ( is_wp_error( $rate ) ) return $rate;
		return array(
			'contract'=>self::CONTRACT,
			'surface'=>$surface,
			'site_fingerprint'=>$identity['site_fingerprint'],
			'principal_fingerprint'=>$identity['principal_fingerprint'],
			'client_fingerprint'=>$identity['client_fingerprint'],
			'complexity'=>$complexity,
			'rate'=>$rate,
			'authorizing'=>false,
		);
	}

	public static function inspect_complexity( $input, array $limits ) {
		$metrics = array(
			'nodes'=>0,'max_depth'=>0,'total_bytes'=>0,'max_string_bytes'=>0,'metadata_bytes'=>0,
			'schema_properties'=>0,'schema_alternatives'=>0,'search_terms'=>0,'regex_metacharacters'=>0
		);
		$encoded = function_exists( 'wp_json_encode' )
			? wp_json_encode( $input, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
			: json_encode( $input, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false === $encoded || ! is_string( $encoded ) ) return new WP_Error( 'mad4b_abuse_input_encode_failed', 'Request input cannot be bounded for abuse admission.' );
		$metrics['total_bytes'] = strlen( $encoded );
		if ( $metrics['total_bytes'] > (int)$limits['max_total_bytes'] ) {
			return self::complexity_error( 'mad4b_abuse_total_bytes_exceeded', 'Request exceeds the certified total byte budget.', $metrics, $limits );
		}
		$walk = self::walk( $input, 0, '', $metrics, $limits );
		if ( is_wp_error( $walk ) ) return $walk;
		return $metrics;
	}

	private static function walk( $value, $depth, $key, array &$metrics, array $limits, $metadata_context = false ) {
		++$metrics['nodes'];
		$metrics['max_depth'] = max( $metrics['max_depth'], (int)$depth );
		if ( $metrics['nodes'] > (int)$limits['max_nodes'] ) return self::complexity_error( 'mad4b_abuse_node_budget_exceeded', 'Request contains too many nested values.', $metrics, $limits );
		if ( $depth > (int)$limits['max_depth'] ) return self::complexity_error( 'mad4b_abuse_depth_exceeded', 'Request nesting exceeds the certified complexity budget.', $metrics, $limits );
		$metadata_context = $metadata_context || 1 === preg_match( '/(?:metadata|meta|headers|context|evidence)/i', (string)$key );

		if ( is_string( $value ) ) {
			$bytes = strlen( $value );
			$metrics['max_string_bytes'] = max( $metrics['max_string_bytes'], $bytes );
			if ( $metadata_context ) {
				$metrics['metadata_bytes'] += $bytes;
				if ( $metrics['metadata_bytes'] > (int)$limits['max_metadata_bytes'] ) return self::complexity_error( 'mad4b_abuse_metadata_bytes_exceeded', 'Request metadata exceeds the certified byte budget.', $metrics, $limits );
			}
			if ( $bytes > (int)$limits['max_string_bytes'] ) return self::complexity_error( 'mad4b_abuse_string_bytes_exceeded', 'Request contains an oversized string value.', $metrics, $limits );
			if ( preg_match( '/(?:query|search|term|pattern|regex)/i', (string)$key ) ) {
				$trimmed = trim( $value );
				$terms = '' === $trimmed ? 0 : count( preg_split( '/\s+/', $trimmed, -1, PREG_SPLIT_NO_EMPTY ) );
				$metrics['search_terms'] += $terms;
				if ( $metrics['search_terms'] > (int)$limits['max_search_terms'] ) return self::complexity_error( 'mad4b_abuse_search_terms_exceeded', 'Search input contains too many terms.', $metrics, $limits );
				$meta = preg_match_all( '/[.\\*+?{}\[\]()|]/', $value, $unused );
				$metrics['regex_metacharacters'] += false === $meta ? 0 : (int)$meta;
				if ( $metrics['regex_metacharacters'] > (int)$limits['max_regex_metacharacters']
					|| preg_match( '/(?:\([^)]*[+*][^)]*\)[+*]|\\[1-9]|\(\?[=!<]|\{\d{4,},?\d*\})/', $value ) ) {
					return self::complexity_error( 'mad4b_abuse_search_complexity_denied', 'Search/regex input exceeds the certified complexity budget.', $metrics, $limits );
				}
			}
			return true;
		}

		if ( is_array( $value ) ) {
			if ( 'properties' === $key ) {
				$metrics['schema_properties'] += count( $value );
				if ( $metrics['schema_properties'] > (int)$limits['max_schema_properties'] ) return self::complexity_error( 'mad4b_abuse_schema_properties_exceeded', 'Schema contains too many properties.', $metrics, $limits );
			}
			if ( in_array( $key, array( 'oneOf','anyOf','allOf' ), true ) ) {
				$metrics['schema_alternatives'] += count( $value );
				if ( $metrics['schema_alternatives'] > (int)$limits['max_schema_alternatives'] ) return self::complexity_error( 'mad4b_abuse_schema_alternatives_exceeded', 'Schema alternatives exceed the certified complexity budget.', $metrics, $limits );
			}
			foreach ( $value as $child_key => $child ) {
				$result = self::walk( $child, $depth + 1, is_string($child_key)?$child_key:(string)$key, $metrics, $limits, $metadata_context );
				if ( is_wp_error( $result ) ) return $result;
			}
		}
		return true;
	}

	private static function identity() {
		$context = class_exists( 'MAD4B_SCP_Identity_Context' ) ? MAD4B_SCP_Identity_Context::current() : array();
		if ( is_wp_error( $context ) ) return $context;
		$site_uuid = class_exists( 'MAD4B_SCP_Site_Profile' ) && method_exists( 'MAD4B_SCP_Site_Profile', 'site_uuid' ) ? (string)MAD4B_SCP_Site_Profile::site_uuid() : '';
		$blog_id = function_exists( 'get_current_blog_id' ) ? (int)get_current_blog_id() : 1;
		$site_material = '' !== $site_uuid ? 'site_uuid:' . $site_uuid : 'blog:' . $blog_id . '|url:' . ( function_exists('home_url') ? (string)home_url('/') : '' );
		$subject = isset($context['subject_fingerprint']) ? strtolower(trim((string)$context['subject_fingerprint'])) : '';
		if ( 1 !== preg_match('/^[a-f0-9]{64}$/D',$subject) ) {
			$user_id = isset($context['wp_user_id']) ? (int)$context['wp_user_id'] : ( function_exists('get_current_user_id') ? (int)get_current_user_id() : 0 );
			$subject = hash('sha256',$user_id>0?'wp-user:'.$user_id:'anonymous');
		}
		$client = isset($context['client_fingerprint']) ? strtolower(trim((string)$context['client_fingerprint'])) : '';
		if ( 1 !== preg_match('/^[a-f0-9]{64}$/D',$client) ) {
			$server = class_exists('MAD4B_SCP_Transport_Context') && method_exists('MAD4B_SCP_Transport_Context','current_server_id') ? (string)MAD4B_SCP_Transport_Context::current_server_id() : '';
			$session = isset($context['session_fingerprint']) ? (string)$context['session_fingerprint'] : '';
			$origin = isset($context['origin']) ? (string)$context['origin'] : '';
			$client = hash('sha256','server:'.$server.'|session:'.$session.'|origin:'.$origin);
		}
		return array(
			'site_fingerprint'=>hash('sha256',$site_material),
			'principal_fingerprint'=>$subject,
			'client_fingerprint'=>$client,
		);
	}

	private static function consume_rate( $surface, array $identity, array $limits, $window_seconds ) {
		global $wpdb;
		if ( class_exists('MAD4B_SCP_Database_Topology') && method_exists('MAD4B_SCP_Database_Topology','assert_write_ready') ) {
			$ready = MAD4B_SCP_Database_Topology::assert_write_ready( false );
			if ( is_wp_error( $ready ) ) return self::rate_storage_error( 'Rate-limit writer topology is unavailable.', $surface, $ready );
		}
		if ( ! class_exists('MAD4B_SCP_Schema') || ! method_exists('MAD4B_SCP_Schema','tables') ) return self::rate_storage_error( 'Rate-limit schema is unavailable.', $surface );
		$tables = MAD4B_SCP_Schema::tables();
		if ( empty($tables['metric_buckets']) ) return self::rate_storage_error( 'Rate-limit bucket storage is unavailable.', $surface );
		$limit = max(1,(int)$limits['requests_per_window']);
		$window_seconds = max(1,min(3600,(int)$window_seconds));
		$now = class_exists('MAD4B_SCP_Time_Policy') && method_exists('MAD4B_SCP_Time_Policy','now_epoch') ? (int)MAD4B_SCP_Time_Policy::now_epoch() : time();
		$window_start = $now - ($now % $window_seconds);
		$bucket_key = hash('sha256',self::CONTRACT.'|'.$surface.'|'.$identity['site_fingerprint'].'|'.$identity['principal_fingerprint'].'|'.$identity['client_fingerprint'].'|'.$window_start);
		$metric_name = self::METRIC_PREFIX.$surface.'.'.substr($identity['site_fingerprint'],0,8).'.'.substr($identity['principal_fingerprint'],0,8).'.'.substr($identity['client_fingerprint'],0,8);
		$bucket_start = gmdate('Y-m-d H:i:s',$window_start);
		$updated_at = gmdate('Y-m-d H:i:s',$now);
		$sql = $wpdb->prepare(
			"INSERT INTO {$tables['metric_buckets']} (bucket_key,metric_name,bucket_start,count_value,sum_value,min_value,max_value,updated_at)
			 VALUES (%s,%s,%s,1,1,1,1,%s)
			 ON DUPLICATE KEY UPDATE count_value=count_value+1,sum_value=sum_value+1,min_value=LEAST(min_value,1),max_value=GREATEST(max_value,1),updated_at=VALUES(updated_at)",
			$bucket_key,$metric_name,$bucket_start,$updated_at
		);
		$written = $wpdb->query($sql);
		if ( false === $written ) return self::rate_storage_error( 'Rate-limit bucket update failed.', $surface, null, array( 'storage_phase' => 'bucket_update' ) );
		$count = $wpdb->get_var( $wpdb->prepare( "SELECT count_value FROM {$tables['metric_buckets']} WHERE BINARY bucket_key=BINARY %s LIMIT 1", $bucket_key ) );
		if ( ! is_numeric($count) ) return self::rate_storage_error( 'Rate-limit bucket readback failed.', $surface, null, array( 'storage_phase' => 'bucket_readback' ) );
		$count=(int)$count;
		if ( $count > $limit ) return new WP_Error(
			'mad4b_rate_limit_exceeded',
			'Request rate exceeds the certified per-site/principal/client budget.',
			array('surface'=>$surface,'limit'=>$limit,'count'=>$count,'window_seconds'=>$window_seconds,'retry_after_seconds'=>max(1,$window_start+$window_seconds-$now),'authorizing'=>false)
		);
		return array('count'=>$count,'limit'=>$limit,'window_seconds'=>$window_seconds,'remaining'=>max(0,$limit-$count),'authorizing'=>false);
	}

	private static function rate_storage_error( $message, $surface = '', $cause = null, array $extra = array() ) {
		$data = array(
			'contract' => self::CONTRACT,
			'surface' => sanitize_key( (string) $surface ),
			'retryable' => true,
			'blind_retry_allowed' => false,
			'authorizing' => false,
			'recovery_read_ability' => 'mad4b/session-safe-diagnostics',
			'bounded_repair_ability' => 'mad4b/query-monitor-db-attribution-bootstrap',
			'recheck_action' => 'retry_original_operation_after_topology_repair',
		);
		if ( is_wp_error( $cause ) ) {
			$data['cause_code'] = sanitize_key( (string) $cause->get_error_code() );
			$cause_data = $cause->get_error_data();
			$cause_data = is_array( $cause_data ) ? $cause_data : array();
			$data['topology_blockers'] = isset( $cause_data['blockers'] ) && is_array( $cause_data['blockers'] )
				? array_values( array_unique( array_filter( array_map( 'sanitize_key', $cause_data['blockers'] ) ) ) )
				: array();
		}
		foreach ( $extra as $key => $value ) {
			$key = sanitize_key( (string) $key );
			if ( '' === $key ) continue;
			$data[ $key ] = is_scalar( $value ) || is_array( $value ) ? $value : (string) $value;
		}
		return new WP_Error( 'mad4b_abuse_rate_storage_unavailable', (string) $message, $data );
	}

	private static function complexity_error( $code, $message, array $metrics, array $limits ) {
		return new WP_Error( $code, $message, array('contract'=>self::CONTRACT,'metrics'=>$metrics,'limits'=>$limits,'authorizing'=>false) );
	}

	private static function config() {
		if ( null !== self::$config ) return self::$config;
		$path = dirname(__DIR__) . '/config/abuse-budget.json';
		if ( ! is_readable($path) ) return self::$config = new WP_Error( 'mad4b_abuse_budget_config_missing', 'Abuse-budget configuration is unavailable.' );
		$data = json_decode((string)file_get_contents($path),true);
		if ( ! is_array($data) || self::CONTRACT !== (isset($data['contract'])?(string)$data['contract']:'') || empty($data['surfaces']) || ! is_array($data['surfaces']) ) {
			return self::$config = new WP_Error( 'mad4b_abuse_budget_config_invalid', 'Abuse-budget configuration is invalid.' );
		}
		$window = isset($data['window_seconds'])?(int)$data['window_seconds']:0;
		if ( $window < 1 || $window > 3600 ) return self::$config = new WP_Error( 'mad4b_abuse_budget_config_invalid', 'Abuse-budget rate window is invalid.' );
		$required=array('requests_per_window','max_total_bytes','max_depth','max_nodes','max_string_bytes','max_metadata_bytes','max_schema_properties','max_schema_alternatives','max_search_terms','max_regex_metacharacters');
		foreach(array('discovery','prepare','execute') as $surface){
			if(empty($data['surfaces'][$surface])||!is_array($data['surfaces'][$surface])) return self::$config = new WP_Error('mad4b_abuse_budget_config_invalid','Abuse-budget surface configuration is incomplete.');
			foreach($required as $key) if(!isset($data['surfaces'][$surface][$key])||(int)$data['surfaces'][$surface][$key]<1) return self::$config = new WP_Error('mad4b_abuse_budget_config_invalid','Abuse-budget limit is invalid.',array('surface'=>$surface,'limit'=>$key));
		}
		return self::$config=$data;
	}
}

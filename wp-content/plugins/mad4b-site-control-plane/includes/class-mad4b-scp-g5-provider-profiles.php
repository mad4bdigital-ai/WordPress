<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Required G5 reference profiles.
 *
 * These rows are acceptance targets, not a closed provider allowlist. Additional
 * providers remain eligible only when introduced by reviewed server code through
 * the existing pinned adapter interface; clients/config packs cannot invent one.
 */
final class MAD4B_SCP_G5_Provider_Profiles {
	const CONTRACT = 'mad4b.feature007-g5-provider-reference-profiles.v1';

	public static function catalog() {
		return array(
			'ga4' => self::profile( 'Google Analytics 4', 'analytics', 'stored.growth-evidence.v1', array( 'traffic', 'engagement', 'conversion' ), array( 'account', 'property' ) ),
			'google-search-console' => self::profile( 'Google Search Console', 'search-measurement', 'stored.growth-evidence.v1', array( 'query', 'page', 'clicks', 'impressions', 'position' ), array( 'account', 'property' ) ),
			'semrush' => self::profile( 'Semrush', 'research', 'stored.growth-evidence.v1', array( 'keyword', 'domain', 'backlink', 'competitor', 'ai-visibility' ), array( 'account', 'profile' ) ),
			'se-ranking' => self::profile( 'SE Ranking', 'research', 'stored.growth-evidence.v1', array( 'keyword', 'domain', 'backlink', 'competitor', 'ai-visibility' ), array( 'account', 'profile' ) ),
			'ahrefs' => self::profile( 'Ahrefs', 'research', 'stored.growth-evidence.v1', array( 'keyword', 'domain', 'backlink', 'competitor', 'ai-visibility' ), array( 'account', 'profile' ) ),
			'serp-mesh' => array(
				'label' => 'Existing SERP provider mesh',
				'family' => 'search-measurement',
				'strategy_id' => 'existing-search-provider-mesh.v1',
				'capability_classes' => array( 'serp', 'rank', 'feature-set' ),
				'setup_dimensions' => array( 'existing-search-profile' ),
				'implementation_owner' => 'MAD4B_SCP_Search_Providers',
				'requires_new_adapter' => false,
				'reference_only' => true,
				'authorizing' => false,
			),
		);
	}

	private static function profile( $label, $family, $strategy, array $capabilities, array $setup ) {
		return array(
			'label' => $label,
			'family' => $family,
			'strategy_id' => $strategy,
			'capability_classes' => $capabilities,
			'setup_dimensions' => $setup,
			'implementation_owner' => 'reviewed_server_code_adapter',
			'requires_new_adapter' => true,
			'reference_only' => true,
			'authorizing' => false,
		);
	}

	public static function coverage( array $registered_rows = array(), array $search_rows = array() ) {
		$registered = array();
		foreach ( $registered_rows as $row ) {
			$id = isset( $row['provider_id'] ) ? (string) $row['provider_id'] : '';
			if ( '' !== $id ) $registered[ $id ] = true;
		}
		$search_ready = false;
		foreach ( $search_rows as $row ) if ( ! empty( $row['provider_id'] ) ) { $search_ready = true; break; }

		$rows = array();
		foreach ( self::catalog() as $id => $profile ) {
			$state = 'adapter_required';
			if ( 'serp-mesh' === $id ) $state = $search_ready ? 'existing_search_mesh_observed' : 'existing_search_mesh_unavailable';
			elseif ( isset( $registered[ $id ] ) ) $state = 'registered_server_adapter_observed';
			$rows[] = array(
				'profile_id' => $id,
				'label' => $profile['label'],
				'family' => $profile['family'],
				'strategy_id' => $profile['strategy_id'],
				'capability_classes' => $profile['capability_classes'],
				'setup_dimensions' => $profile['setup_dimensions'],
				'state' => $state,
				'execution_admitted' => false,
				'authorizing' => false,
			);
		}
		return array(
			'contract' => self::CONTRACT,
			'profiles' => $rows,
			'profile_count' => count( $rows ),
			'closed_allowlist' => false,
			'additional_reviewed_server_code_adapters_allowed' => true,
			'client_defined_provider_identity_allowed' => false,
			'generic_outbound_http_allowed' => false,
			'authorizing' => false,
		);
	}
}

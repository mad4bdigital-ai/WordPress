<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Bounded request-local evidence; never repairs registrations or grants. */
final class MAD4B_SCP_MCP_Catalog_Diagnostics {
	const CONTRACT = 'mad4b.mcp-catalog-evidence.v1';
	const MAX_TOOLS = 36;
	private static $booted = false;
	private static $failure_logged = false;

	public static function boot() {
		if ( self::$booted ) return;
		self::$booted = true;
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'observe_response' ), PHP_INT_MAX, 3 );
	}

	public static function inspect( $server, array $expected_abilities ) {
		$out = array( 'contract' => self::CONTRACT, 'observed' => false, 'ready' => false, 'tool_count' => 0, 'actual_names' => array(), 'actual_abilities' => array(), 'missing_abilities' => array(), 'unexpected_abilities' => array(), 'blocker' => 'mcp_server_not_materialized' );
		if ( ! is_object( $server ) || ! method_exists( $server, 'get_tools' ) || ! method_exists( $server, 'get_mcp_tool' ) ) return $out;
		try {
			$tools = $server->get_tools();
			if ( ! is_array( $tools ) ) return $out;
			$out['observed'] = true;
			$out['tool_count'] = count( $tools );
			if ( count( $tools ) > self::MAX_TOOLS || count( $expected_abilities ) > self::MAX_TOOLS ) { $out['blocker'] = 'mcp_catalog_budget_exceeded'; return $out; }
			foreach ( $tools as $name => $dto ) {
				if ( ! is_string( $name ) || ! preg_match( '/^[A-Za-z0-9_.-]{1,128}$/D', $name ) || ! is_object( $dto ) || ! method_exists( $dto, 'getName' ) || $name !== $dto->getName() ) { $out['blocker'] = 'mcp_tool_identity_invalid'; return $out; }
				$tool = $server->get_mcp_tool( $name );
				$meta = is_object( $tool ) && method_exists( $tool, 'get_adapter_meta' ) ? $tool->get_adapter_meta() : array();
				$ability = is_array( $meta ) && isset( $meta['ability'] ) && is_string( $meta['ability'] ) ? $meta['ability'] : '';
				if ( ! preg_match( '/^[a-z0-9_-]+\/[a-z0-9_-]{1,128}$/D', $ability ) ) { $out['blocker'] = 'mcp_tool_ability_binding_invalid'; return $out; }
				$out['actual_names'][] = $name;
				$out['actual_abilities'][] = $ability;
			}
			sort( $out['actual_names'], SORT_STRING );
			sort( $out['actual_abilities'], SORT_STRING );
			$out['missing_abilities'] = array_values( array_diff( $expected_abilities, $out['actual_abilities'] ) );
			$out['unexpected_abilities'] = array_values( array_diff( $out['actual_abilities'], $expected_abilities ) );
			sort( $out['missing_abilities'], SORT_STRING );
			sort( $out['unexpected_abilities'], SORT_STRING );
			$out['inventory_fingerprint'] = hash( 'sha256', wp_json_encode( array( $out['actual_names'], $out['actual_abilities'] ) ) );
			$out['blocker'] = empty( $tools ) ? 'mcp_catalog_empty' : ( count( array_unique( $out['actual_abilities'] ) ) !== count( $tools ) ? 'mcp_catalog_duplicate_ability' : ( ! empty( $out['missing_abilities'] ) || ! empty( $out['unexpected_abilities'] ) ? 'mcp_catalog_projection_mismatch' : '' ) );
			$out['ready'] = '' === $out['blocker'];
		} catch ( Throwable $error ) { $out['blocker'] = 'mcp_catalog_inspection_failed'; }
		return $out;
	}

	public static function observe_response( $response, $rest_server, $request ) {
		unset( $rest_server );
		if ( ! is_object( $request ) || ! method_exists( $request, 'get_route' ) || '/mcp/mad4b-chatgpt' !== rtrim( (string) $request->get_route(), '/' ) || 'POST' !== $request->get_method() ) return $response;
		if ( ! class_exists( 'MAD4B_SCP_OAuth_Resource_Bridge', false ) || ! MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_active() ) return $response;
		$body = $request->get_json_params();
		$method = is_array( $body ) && isset( $body['method'] ) && is_string( $body['method'] ) ? $body['method'] : '';
		if ( ! in_array( $method, array( 'initialize', 'tools/list' ), true ) || ! is_object( $response ) || ! method_exists( $response, 'get_data' ) || ! method_exists( $response, 'header' ) ) return $response;
		$data = $response->get_data();
		$status = (int) $response->get_status();
		$outcome = $status >= 400 ? 'http_error' : ( ! is_array( $data ) || isset( $data['error'] ) || ! isset( $data['result'] ) ? 'rpc_error' : 'ready' );
		$count = null;
		if ( 'ready' === $outcome && 'tools/list' === $method ) {
			$listed = isset( $data['result']['tools'] ) ? $data['result']['tools'] : null;
			$count = is_array( $listed ) ? count( $listed ) : null;
			if ( null === $count ) $outcome = 'catalog_invalid';
			elseif ( 0 === $count ) $outcome = 'catalog_empty';
			elseif ( $count > self::MAX_TOOLS ) $outcome = 'catalog_budget_exceeded';
		}
		// No body, request ID, token, cookie, session value, or error message is copied.
		$evidence = array( 'contract' => self::CONTRACT, 'stage' => $method, 'http_status' => $status, 'outcome' => $outcome, 'session_header_present' => '' !== (string) $request->get_header( 'mcp-session-id' ), 'protocol_header_present' => '' !== (string) $request->get_header( 'mcp-protocol-version' ), 'tool_count' => $count );
		if ( is_array( $data ) && isset( $data['error']['code'] ) && is_int( $data['error']['code'] ) ) $evidence['rpc_error_code'] = $data['error']['code'];
		$response->header( 'X-MAD4B-MCP-Stage', $method );
		$response->header( 'X-MAD4B-MCP-Outcome', $outcome );
		if ( 'ready' !== $outcome && ! self::$failure_logged ) {
			self::$failure_logged = true;
			error_log( '[MAD4B MCP discovery] ' . wp_json_encode( $evidence ) );
		}
		return $response;
	}
}
MAD4B_SCP_MCP_Catalog_Diagnostics::boot();

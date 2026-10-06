<?php

/**
 * Test-only exact callback identities for provider MCP registration isolation.
 * No provider code is executed and no authority is created.
 */

namespace Hostinger\AiAssistant\Mcp {
	if ( ! class_exists( __NAMESPACE__ . '\\McpServer', false ) ) {
		class McpServer {
			public function create_server( $adapter = null ) {
				$GLOBALS['mad4b_hostinger_callback_hit'] = true;
			}
		}
	}
}

namespace ElementsKit_Lite\Mcp {
	if ( ! class_exists( __NAMESPACE__ . '\\Server', false ) ) {
		class Server {
			public function register_server( $adapter = null ) {
				$GLOBALS['mad4b_elementskit_callback_hit'] = true;
			}
		}
	}
}

namespace Jet_Engine\MCP_Tools {
	if ( ! class_exists( __NAMESPACE__ . '\\MAD4B_Test_REST_Registration', false ) ) {
		class MAD4B_Test_REST_Registration {
			public function register_features_api( $server = null ) {
				unset( $server );
				$GLOBALS['mad4b_jetengine_rest_registration_callback_hit'] = true;
				if ( ! function_exists( 'register_rest_route' ) ) return;

				$permission = static function () { return true; };
				$external_run_permission = static function () {
					return new \WP_Error( 'jetengine_external_auth_required', 'External JetEngine run transport requires provider authentication.', array( 'status' => 401 ) );
				};
				register_rest_route( 'jet-engine/v1', '/mcp', array(
					'methods' => array( 'GET', 'POST' ),
					'callback' => static function () { return \rest_ensure_response( array( 'ok' => true ) ); },
					'permission_callback' => $permission,
				) );
				register_rest_route( 'jet-engine/v1', '/mcp-tools', array(
					'methods' => 'GET',
					'callback' => static function () {
						return \rest_ensure_response( array( 'tools' => array(
							array(
								'name' => 'resource-get-configuration',
								'title' => 'Get JetEngine Configuration',
								'description' => 'Retrieve the provider configuration.',
								'annotations' => array( 'readOnlyHint' => true ),
								'inputSchema' => array(),
							),
							array(
								'name' => 'resource-get-website-config',
								'title' => 'Get JetEngine Website Config',
								'description' => 'Get JetEngine website config through the isolated native provider bridge.',
								'annotations' => array( 'readOnlyHint' => true ),
								'inputSchema' => array(),
							),
							array(
								'name' => 'tool-add-meta-box',
								'title' => 'Add Meta Box',
								'description' => 'Create a meta box from configuration.',
								'annotations' => array( 'readOnlyHint' => false ),
								'inputSchema' => array( 'type' => 'object', 'additionalProperties' => true ),
							),
							array(
								'name' => 'tool-add-query',
								'title' => 'Add Query',
								'description' => 'Create a JetEngine query.',
								'annotations' => array( 'readOnlyHint' => false ),
								'inputSchema' => array( 'type' => 'object', 'additionalProperties' => true ),
							),
							array(
								'name' => 'tool-add-listing',
								'title' => 'Add Listing',
								'description' => 'Create a listing backed by a query.',
								'annotations' => array( 'readOnlyHint' => false ),
								'inputSchema' => array( 'type' => 'object', 'additionalProperties' => true ),
							),
							array(
								'name' => 'resource-provider-error',
								'title' => 'Provider Error Diagnostic Fixture',
								'description' => 'Read-only fixture that returns a bounded provider error.',
								'annotations' => array( 'readOnlyHint' => true ),
								'inputSchema' => array(),
							),
						) ) );
					},
					'permission_callback' => $permission,
				) );
				register_rest_route( 'jet-engine/v1', '/mcp-tools/run/(?P<tool>[a-zA-Z0-9\-\/]+?)', array(
					'methods' => 'POST',
					'callback' => static function ( $request ) {
						$GLOBALS['mad4b_ci_jetengine_run_callback_reached'] = true;
						if ( 'resource-provider-error' === (string) $request->get_param( 'tool' ) ) {
							return new \WP_Error( 'jetengine_fixture_provider_failure', 'Fixture provider failure.', array( 'status' => 409 ) );
						}
						return \rest_ensure_response( array(
							'ok' => true,
							'tool' => (string) $request->get_param( 'tool' ),
							'input' => (array) $request->get_param( 'input' ),
						) );
					},
					'permission_callback' => $external_run_permission,
					'args' => array(
						'input' => array( 'type' => 'object', 'required' => false, 'default' => array() ),
					),
				) );
			}
		}
	}
}

namespace {
	if ( ! class_exists( 'MAD4B_Isolation_Unknown_Server_Callback', false ) ) {
		class MAD4B_Isolation_Unknown_Server_Callback {
			public function register_server( $adapter = null ) {
				$GLOBALS['mad4b_unknown_callback_hit'] = true;
			}
		}
	}
}

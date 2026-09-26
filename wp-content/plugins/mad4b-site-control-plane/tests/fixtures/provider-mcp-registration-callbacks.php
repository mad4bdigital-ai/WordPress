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

namespace {
	if ( ! class_exists( 'MAD4B_Isolation_Unknown_Server_Callback', false ) ) {
		class MAD4B_Isolation_Unknown_Server_Callback {
			public function register_server( $adapter = null ) {
				$GLOBALS['mad4b_unknown_callback_hit'] = true;
			}
		}
	}
}

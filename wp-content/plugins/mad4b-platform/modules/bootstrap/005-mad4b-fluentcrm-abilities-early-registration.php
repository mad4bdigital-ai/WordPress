<?php
/**
 * Local staging compatibility for FluentCRM 3.2.6 + WordPress Abilities API.
 * FluentCRM registers its callbacks at init priority 5, after the Abilities
 * API already dispatched init. Register only the category and abilities early;
 * leave FluentCRM's normal MCP server registration and permission checks alone.
 * The pinned version prevents applying the workaround after a vendor fix.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

add_action( 'plugins_loaded', static function () {
    if ( ! defined( 'FLUENTCRM_PLUGIN_VERSION' )
        || '3.2.6' !== (string) FLUENTCRM_PLUGIN_VERSION
        || ! function_exists( 'fluentcrm_get_option' )
        || 'yes' !== (string) fluentcrm_get_option( 'mcp_enabled', 'yes' )
        || ! class_exists( '\FluentCrm\App\Modules\MCP\MCPInit' ) ) {
        return;
    }

    $init = new \FluentCrm\App\Modules\MCP\MCPInit();
    add_action( 'wp_abilities_api_categories_init', array( $init, 'registerCategory' ), 10 );
    add_action( 'wp_abilities_api_init', array( $init, 'registerAbilities' ), 10 );
}, 20 );

// Fluent Boards 2.2.2 has the same late-API-bootstrap behavior. Register its
// native abilities before the WordPress registry lifecycle has fired.
add_action( 'plugins_loaded', static function () {
    if ( ! defined( 'FLUENT_BOARDS_PLUGIN_VERSION' )
        || '2.2.2' !== (string) FLUENT_BOARDS_PLUGIN_VERSION
        || ! function_exists( 'fluent_boards_get_option' )
        || 'yes' !== (string) fluent_boards_get_option( 'mcp_enabled', 'yes' )
        || ! class_exists( '\FluentBoards\App\Modules\MCP\MCPInit' ) ) {
        return;
    }
    $init = new \FluentBoards\App\Modules\MCP\MCPInit();
    add_action( 'wp_abilities_api_categories_init', array( $init, 'registerCategory' ), 10 );
    add_action( 'wp_abilities_api_init', array( $init, 'registerAbilities' ), 10 );
}, 20 );

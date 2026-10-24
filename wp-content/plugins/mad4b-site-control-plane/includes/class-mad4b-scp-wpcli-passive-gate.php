<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Bounded WP-CLI passive read optimization.
 *
 * This is a startup classification only; it never authorizes an MCP Ability,
 * owner action, content mutation, file write or database migration.
 */
final class MAD4B_SCP_WPCLI_Passive_Gate {
    public static function matches( $args, $raw = array() ) {
        if ( ! is_array( $args ) || count( $args ) < 2
            || ! is_string( $args[0] ) || ! is_string( $args[1] ) ) return false;

        $allowed = array(
            'option' => array( 'get', 'list' ),
            'plugin' => array( 'get', 'list', 'status', 'is-active' ),
            'theme'  => array( 'get', 'list', 'status', 'is-active' ),
            'core'   => array( 'is-installed', 'version' ),
        );

        if ( ! isset( $allowed[ $args[0] ] )
            || ! in_array( $args[1], $allowed[ $args[0] ], true ) ) return false;

        // Require a full runtime for scripts, alternate sites, identities or
        // contexts, even when the primary WP-CLI verb would be a passive read.
        foreach ( (array) $raw as $flag ) {
            if ( ! is_string( $flag ) ) return false;
            if ( preg_match( '/^--(?:require|exec|context|user|url|ssh|http|prompt)(?:=|$)/i', $flag ) )
                return false;
        }
        return true;
    }

    public static function allowed_request() {
        if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! class_exists( 'WP_CLI' )
            || ! method_exists( 'WP_CLI', 'get_runner' )
            || ( defined( 'MAD4B_SCP_FORCE_FULL_BOOT' ) && MAD4B_SCP_FORCE_FULL_BOOT ) )
            return false;

        $runner = WP_CLI::get_runner();
        if ( ! is_object( $runner ) ) return false;

        // Read the Runner argument value once. Repeated isset()/is_array()
        // inspection was found to reject real WP-CLI passive commands on
        // ARE Staging even though the same parsed arguments were valid.
        return self::matches( $runner->arguments ?? null, $_SERVER['argv'] ?? array() );
    }
}

<?php
if ( PHP_VERSION_ID < 70400 ) {
    fwrite( STDERR, "FAIL: PHP 7.4+ required\n" );
    exit( 1 );
}
define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['mad4b_test_filters'] = array();
$GLOBALS['mad4b_test_registered'] = array();

function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
    if ( ! isset( $GLOBALS['mad4b_test_filters'][ $hook ] ) ) $GLOBALS['mad4b_test_filters'][ $hook ] = array();
    $GLOBALS['mad4b_test_filters'][ $hook ][] = array(
        'callback' => $callback,
        'priority' => (int) $priority,
        'accepted_args' => (int) $accepted_args,
    );
    usort(
        $GLOBALS['mad4b_test_filters'][ $hook ],
        static function ( $a, $b ) { return $a['priority'] <=> $b['priority']; }
    );
    return true;
}
function has_filter( $hook, $callback = false ) {
    if ( empty( $GLOBALS['mad4b_test_filters'][ $hook ] ) ) return false;
    foreach ( $GLOBALS['mad4b_test_filters'][ $hook ] as $row ) {
        if ( false === $callback || $row['callback'] === $callback ) return $row['priority'];
    }
    return false;
}
function remove_filter( $hook, $callback, $priority = 10 ) {
    if ( empty( $GLOBALS['mad4b_test_filters'][ $hook ] ) ) return false;
    foreach ( $GLOBALS['mad4b_test_filters'][ $hook ] as $index => $row ) {
        if ( $row['callback'] === $callback && (int) $row['priority'] === (int) $priority ) {
            array_splice( $GLOBALS['mad4b_test_filters'][ $hook ], $index, 1 );
            return true;
        }
    }
    return false;
}
function apply_filters( $hook, $value ) {
    $args = func_get_args();
    array_shift( $args );
    if ( empty( $GLOBALS['mad4b_test_filters'][ $hook ] ) ) return $value;
    foreach ( $GLOBALS['mad4b_test_filters'][ $hook ] as $row ) {
        $call_args = array_slice( $args, 0, max( 1, (int) $row['accepted_args'] ) );
        $call_args[0] = $value;
        $value = call_user_func_array( $row['callback'], $call_args );
        $args[0] = $value;
    }
    return $value;
}
function wp_register_ability( $name, $args ) {
    $GLOBALS['mad4b_test_registered'][ $name ] = apply_filters( 'wp_register_ability_args', $args, $name );
    return true;
}
function do_action( $hook ) { return null; }

final class MAD4B_SCP_Staging_Write_Authority {
    const CONTRACT = 'mad4b.governed-write-authority.v1';
    public static function augment_write_ability( $args, $name ) {
        if ( ! is_array( $args ) ) return $args;
        $meta = isset( $args['meta'] ) && is_array( $args['meta'] ) ? $args['meta'] : array();
        $annotations = isset( $meta['annotations'] ) && is_array( $meta['annotations'] ) ? $meta['annotations'] : array();
        if ( ! array_key_exists( 'readonly', $annotations ) || false !== $annotations['readonly'] ) return $args;
        if ( ! isset( $args['meta'] ) || ! is_array( $args['meta'] ) ) $args['meta'] = array();
        if ( ! isset( $args['meta']['mcp'] ) || ! is_array( $args['meta']['mcp'] ) ) $args['meta']['mcp'] = array();
        $args['meta']['mcp']['mad4b_governed_write_authority'] = self::CONTRACT;
        return $args;
    }
}

add_filter(
    'wp_register_ability_args',
    array( 'MAD4B_SCP_Staging_Write_Authority', 'augment_write_ability' ),
    20,
    2
);

require dirname( __DIR__ ) . '/includes/class-mad4b-scp-abilities.php';

$abilities = new MAD4B_SCP_Abilities();
$abilities->register_abilities();

$fail = static function ( $message ) {
    fwrite( STDERR, 'FAIL: ' . $message . "\n" );
    exit( 1 );
};

if ( ! isset( $GLOBALS['mad4b_test_registered']['mad4b/enrollment-execute'] ) ) {
    $fail( 'enrollment dispatcher was not registered' );
}
$enrollment = $GLOBALS['mad4b_test_registered']['mad4b/enrollment-execute'];
$enrollment_mcp = isset( $enrollment['meta']['mcp'] ) && is_array( $enrollment['meta']['mcp'] )
    ? $enrollment['meta']['mcp']
    : array();
if ( isset( $enrollment_mcp['mad4b_governed_write_authority'] ) ) {
    $fail( 'enrollment dispatcher transport was widened into normal governed write authority' );
}

if ( ! isset( $GLOBALS['mad4b_test_registered']['mad4b/plugin-activate'] ) ) {
    $fail( 'ordinary governed mutation fixture was not registered' );
}
$normal = $GLOBALS['mad4b_test_registered']['mad4b/plugin-activate'];
$normal_mcp = isset( $normal['meta']['mcp'] ) && is_array( $normal['meta']['mcp'] )
    ? $normal['meta']['mcp']
    : array();
if ( MAD4B_SCP_Staging_Write_Authority::CONTRACT !== ( isset( $normal_mcp['mad4b_governed_write_authority'] ) ? $normal_mcp['mad4b_governed_write_authority'] : '' ) ) {
    $fail( 'generic write augmentation was not restored after enrollment dispatcher registration' );
}

$priority = has_filter(
    'wp_register_ability_args',
    array( 'MAD4B_SCP_Staging_Write_Authority', 'augment_write_ability' )
);
if ( 20 !== $priority ) {
    $fail( 'generic write augmentation filter was not restored at its original priority' );
}

echo "mad4b.enrollment-dispatch.registration-boundary.v1: PASS\n";

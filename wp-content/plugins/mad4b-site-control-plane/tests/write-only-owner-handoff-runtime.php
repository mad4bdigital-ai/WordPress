<?php
// A full-authority status response is not allowed to recursively compute
// the independent Write-only plan or owner receipt.
define( 'ABSPATH', __DIR__ . '/' );
class MAD4B_SCP_Staging_Write_Authority_Convergence {
    public static $calls = 0;
    public static function handshake() {
        ++self::$calls;
        throw new RuntimeException( 'Nested governed read must never run.' );
    }
}
class MAD4B_SCP_Staging_Write_Owner_Intent {
    public static $calls = 0;
    public static function page_intent_status( $input = null ) {
        ++self::$calls;
        throw new RuntimeException( 'Owner receipt cannot be inferred by Full Authority status.' );
    }
}
require dirname( __DIR__ ) . '/includes/class-mad4b-scp-full-staging-authority.php';
function check_handoff( $condition, $message ) {
    if ( ! $condition ) {
        fwrite( STDERR, 'FAIL ' . $message . PHP_EOL );
        exit( 1 );
    }
}
$method = new ReflectionMethod( 'MAD4B_SCP_Full_Staging_Authority', 'write_only_handoff' );
$method->setAccessible( true );
foreach ( array( '', str_repeat( 'a', 64 ), str_repeat( 'f', 64 ) ) as $generation ) {
    $res = $method->invoke( null, $generation );
    check_handoff( is_array( $res ) &&
        $res['contract'] === 'mad4b.staging-write-only-owner-handoff.v1' &&
        $res['state'] === 'separate_governed_read_required',
        'bounded independent Write-only read guidance' );
    check_handoff( ! array_key_exists( 'exact_apply', $res )
        && $res['owner_review_evaluated'] === false
        && $res['exact_apply_available'] === false
        && $res['blind_apply_allowed'] === false, 'no stale or synthesized plan' );
    check_handoff( $res['owner_review_read_ability'] === 'mad4b/staging-write-owner-review'
        && $res['apply_ability'] === 'mad4b/staging-write-authority-convergence-apply',
        'correct independent owner review and write lanes' );
    check_handoff( $res['read_only'] === true
        && $res['authorizing'] === false
        && $res['mutation_performed'] === false
        && $res['developer_authority_requested'] === false
        && $res['developer_breakglass_requested'] === false
        && $res['production_mutation_allowed'] === false,
        'no unauthorized power through transport guidance' );
}
check_handoff( MAD4B_SCP_Staging_Write_Authority_Convergence::$calls === 0
    && MAD4B_SCP_Staging_Write_Owner_Intent::$calls === 0,
    'full handshake no longer triggers recursive independent read' );
echo "WRITE_ONLY_OWNER_HANDOFF_RUNTIME_PASS\n";

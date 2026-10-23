<?php
/**
 * Standalone no-WordPress smoke test:
 * php tests/remote-execution/unified-operator-session-policy-smoke.php
 */
define( 'ABSPATH', __DIR__ . '/../../' );
require_once __DIR__ . '/../../wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-operator-session-policy.php';

$now = 1792740000;
$session = array(
    'contract' => 'mad4b.operator-session.v1',
    'environment' => 'staging',
    'site_uuid' => 'fixture-site-uuid',
    'target_fingerprint' => str_repeat( 'a', 64 ),
    'agent_public_id' => 'agent-1',
    'issued_at_epoch' => $now - 15,
    'expires_at_epoch' => $now + 840,
    'scopes' => array( 'shell', 'wp-cli', 'sql.read', 'sql.write', 'sql.schema' ),
);
$facts = array(
    'environment' => 'staging',
    'site_uuid' => 'fixture-site-uuid',
    'target_fingerprint' => str_repeat( 'a', 64 ),
    'agent_public_id' => 'agent-1',
    'now_epoch' => $now,
    'kill_switch_enabled' => false,
);
$count = 0;
function expect_decision( $name, $session, $operation, $facts, $allowed ) {
    global $count;
    $actual = MAD4B_SCP_Operator_Session_Policy::evaluate( $session, $operation, $facts );
    if ( $actual['eligible'] !== $allowed || $actual['authorized'] !== false ) {
        fwrite( STDERR, "FAIL $name: " . json_encode( $actual ) . PHP_EOL );
        exit( 1 );
    }
    if ( $actual['mutation_performed'] !== false || $actual['production_authorized'] !== false ) {
        fwrite( STDERR, "FAIL $name: policy unexpectedly authorizes host writes" . PHP_EOL );
        exit( 1 );
    }
    ++$count;
}
foreach ( array( 'shell', 'wp-cli', 'sql.read', 'sql.write', 'sql.schema' ) as $op ) {
    expect_decision( "in_scope_$op", $session, $op, $facts, true );
}
expect_decision( 'unknown_ability', $session, 'production.shell', $facts, false );
$changed = $facts;
$changed['environment'] = 'production';
expect_decision( 'production_denied', $session, 'shell', $changed, false );
$changed = $facts;
$changed['target_fingerprint'] = str_repeat( 'b', 64 );
expect_decision( 'target_change_denied', $session, 'shell', $changed, false );
$changed = $facts;
$changed['kill_switch_enabled'] = true;
expect_decision( 'kill_switch', $session, 'shell', $changed, false );
$changed = $facts;
$changed['now_epoch'] = $now + 841;
expect_decision( 'expired', $session, 'shell', $changed, false );
$changed = $session;
$changed['expires_at_epoch'] = $now + 950;
expect_decision( 'ttl_too_long', $changed, 'shell', $facts, false );
$changed = $session;
$changed['scopes'] = array( 'sql.read' );
expect_decision( 'scope_denied', $changed, 'sql.write', $facts, false );
$changed = $session;
$changed['agent_public_id'] = 'agent-2';
expect_decision( 'agent_changed', $changed, 'shell', $facts, false );
echo "PASS: $count policy decision checks; no authorization or execution performed" . PHP_EOL;

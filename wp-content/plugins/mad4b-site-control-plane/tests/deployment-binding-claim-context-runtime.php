<?php
/**
 * Isolated acceptance for a once-claimed exact WordPress native binding.
 * No site, network, files, DB, key or mutation is accessed by this fixture.
 */
if ( ! defined('ABSPATH') ) define('ABSPATH', dirname(__DIR__) . '/');
function wp_json_encode($value, $flags = 0) { return json_encode($value, $flags); }
require dirname(__DIR__) . '/includes/class-mad4b-scp-authorization.php';
function assertion($value, $why) {
    if (!$value) { fwrite(STDERR, 'FAIL: ' . $why . PHP_EOL); exit(1); }
}
$input = array(
    'expected_plan_sha256' => str_repeat('a',64),
    'expected_profile_digest' => str_repeat('b',64),
    'expected_site_uuid' => 'd745d81f-6fc4-5c6a-99dd-d953c92137bf',
    'expected_revision' => 6,
    'confirmation' => 'CREATE HOST-LOCAL STAGING BINDING',
    '_mad4b_approval_ticket_id' => '868d97c0-30d5-4258-935a-602cf22317e5'
);
$claim = array(
    'allowed' => true, 'reason_code' => 'execution_claimed',
    'approval_required' => true, 'approval_claimed' => true,
    'approval_ticket_id' => $input['_mad4b_approval_ticket_id']
);
$invoke = new ReflectionMethod('MAD4B_SCP_Authorization', 'invoke_claimed_callback');
$invoke->setAccessible(true);
assertion(!MAD4B_SCP_Authorization::native_binding_callback_is_claimed($input),
    'A direct PHP invocation does not have an approved callback receipt.');
$visited = 0;
$seen = $invoke->invoke(null, 'mad4b/deployment-binding-native-apply', $claim, $input,
    function($same_input) use (&$visited) {
        $visited++;
        $modified = $same_input;
        $modified['expected_revision']++;
        return MAD4B_SCP_Authorization::native_binding_callback_is_claimed($same_input)
            && !MAD4B_SCP_Authorization::native_binding_callback_is_claimed($modified);
    });
assertion($seen && 1 === $visited, 'Only the exact claimed ticket/input can enter the writer once.');
assertion(!MAD4B_SCP_Authorization::native_binding_callback_is_claimed($input),
    'Callback receipt is erased immediately after success.');
$wrong = $claim;
$wrong['approval_ticket_id'] = 'ffffffff-ffff-ffff-ffff-ffffffffffff';
$wrong_claim = $invoke->invoke(null, 'mad4b/deployment-binding-native-apply', $wrong, $input,
    function($arg) { return MAD4B_SCP_Authorization::native_binding_callback_is_claimed($arg); });
assertion(!$wrong_claim, 'A claim for a different ticket never enables a write.');
$wrong = $claim;
$wrong['approval_claimed'] = false;
$missing_claim = $invoke->invoke(null, 'mad4b/deployment-binding-native-apply', $wrong, $input,
    function($arg) { return MAD4B_SCP_Authorization::native_binding_callback_is_claimed($arg); });
assertion(!$missing_claim, 'Unclaimed approval never enables a write.');
$wrong = $claim;
$wrong['reason_code'] = 'preflight_allowed';
$preflight_only = $invoke->invoke(null, 'mad4b/deployment-binding-native-apply', $wrong, $input,
    function($arg) { return MAD4B_SCP_Authorization::native_binding_callback_is_claimed($arg); });
assertion(!$preflight_only, 'Permission preflight is not an execution claim.');
$unrelated = $invoke->invoke(null, 'mad4b/other-operation', $claim, $input,
    function($arg) { return MAD4B_SCP_Authorization::native_binding_callback_is_claimed($arg); });
assertion(!$unrelated, 'Other WordPress abilities cannot borrow the native claim.');
try {
    $invoke->invoke(null, 'mad4b/deployment-binding-native-apply', $claim, $input,
        function($arg) {
            assertion(MAD4B_SCP_Authorization::native_binding_callback_is_claimed($arg),
                'Valid receipt exists only inside callback.');
            throw new RuntimeException('intentional fixture exception');
        });
    assertion(false, 'Intentional exception must propagate.');
} catch (RuntimeException $expected) {
    assertion('intentional fixture exception' === $expected->getMessage(),
        'Only the fixture exception propagated.');
}
assertion(!MAD4B_SCP_Authorization::native_binding_callback_is_claimed($input),
    'Finally must erase a temporary approval even after provider exception.');
$google = $invoke->invoke(null, 'mad4b/google-revocation-retry', $claim, $input,
    function($arg) {
        $changed = $arg;
        $changed['expected_plan_sha256'] = str_repeat('f', 64);
        return MAD4B_SCP_Authorization::google_revocation_callback_is_claimed($arg)
            && !MAD4B_SCP_Authorization::google_revocation_callback_is_claimed($changed)
            && !MAD4B_SCP_Authorization::native_binding_callback_is_claimed($arg);
    });
assertion($google, 'Google proof is bound to its exact ability, ticket and input.');
assertion(!MAD4B_SCP_Authorization::google_revocation_callback_is_claimed($input),
    'Google callback receipt is erased after return.');
try {
    $invoke->invoke(null, 'mad4b/google-revocation-retry', $claim, $input,
        function($arg) { throw new RuntimeException('google fixture exception'); });
    assertion(false, 'Google fixture exception must propagate.');
} catch (RuntimeException $expected) {
    assertion('google fixture exception' === $expected->getMessage(), 'Exact Google fixture exception.');
}
assertion(!MAD4B_SCP_Authorization::google_revocation_callback_is_claimed($input),
    'Google proof is erased even when the provider throws.');
$nested = $invoke->invoke(null, 'mad4b/google-revocation-retry', $claim, $input,
    function($arg) use ($invoke, $claim) {
        return $invoke->invoke(null, 'mad4b/other-operation', $claim, $arg,
            function($nested_arg) { return MAD4B_SCP_Authorization::google_revocation_callback_is_claimed($nested_arg); });
    });
assertion(!$nested, 'A nested unrelated callback cannot borrow the outer Google claim.');
echo "PASS: exact governed native binding callback claim lifetime and denial policy\n";

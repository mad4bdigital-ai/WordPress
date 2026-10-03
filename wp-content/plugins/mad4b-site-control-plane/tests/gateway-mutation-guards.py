"""Prove behavioral CI rejects representative regressions, not just marker loss."""
from pathlib import Path
import runpy
import shutil
import subprocess
import tempfile

root = Path(__file__).resolve().parents[1]
mutations = [
    ('content/admin lane omission', 'class-mad4b-scp-unified-capability-gateway.php', "'content' => 'mad4b/write-execute',", "'content' => 'mad4b/missing-dispatch',", 'gateway-regression-runtime.php'),
    ('invalid bearer cookie fallback', 'class-mad4b-scp-ability-catalog-transport.php', "$request->get_header( 'authorization' ) && ! MAD4B_SCP_OAuth_Resource_Bridge::verified_bearer_active()", 'false', 'gateway-regression-runtime.php'),
    ('classification pin ignored', 'class-mad4b-scp-abilities.php', 'private function validate_prepared_classification( $ability_name, array $input ) {', 'private function validate_prepared_classification( $ability_name, array $input ) { return true;', 'gateway-regression-runtime.php'),
    ('eager schema search', 'class-mad4b-scp-unified-capability-gateway.php', '$ability = wp_get_ability( $ability_name );', '$ability = wp_get_ability( $ability_name ); $ability->get_input_schema();', 'gateway-regression-runtime.php'),
    ('cross-blog registry reuse', 'class-mad4b-scp-unified-capability-gateway.php', 'public static function runtime_blog_matches() {', 'public static function runtime_blog_matches() { return true;', 'gateway-regression-runtime.php'),
    ('ability build budget removed', 'class-mad4b-scp-ability-catalog-transport.php', 'count( $abilities ) > $max_abilities', 'false', 'catalog-transport-runtime.php'),
    ('byte build budget removed', 'class-mad4b-scp-ability-catalog-transport.php', '$definition_bytes > $max_bytes', 'false', 'catalog-transport-runtime.php'),
    ('deadline removed', 'class-mad4b-scp-ability-catalog-transport.php', 'microtime( true ) >= $deadline', 'false', 'catalog-transport-runtime.php'),
    ('single-flight fail-open', 'class-mad4b-scp-distributed-lock.php', '1 !== (int) $acquired', 'false', 'catalog-transport-runtime.php'),
    ('lost connection lock ignored', 'class-mad4b-scp-ability-catalog-transport.php', '! MAD4B_SCP_Distributed_Lock::owns( $lock )', 'false', 'catalog-transport-runtime.php'),
    ('database lock namespace omitted', 'class-mad4b-scp-distributed-lock.php', "$database . ':' . $wpdb->options", "$wpdb->options", 'catalog-transport-runtime.php'),
    ('authority scope dispatcher pin ignored', 'class-mad4b-scp-abilities.php', "! hash_equals( MAD4B_SCP_Ability_Catalog_Transport::current_authority_scope(), $input['expected_authority_scope_sha256'] )", 'false', 'gateway-regression-runtime.php'),
    ('write permission preparation gate removed', 'class-mad4b-scp-abilities.php', "$prepared = $this->validate_prepared_classification( $ability_name, $input );", "$prepared = true;", 'gateway-regression-runtime.php'),
    ('write governance target binding removed', 'class-mad4b-scp-abilities.php', "! hash_equals( self::$write_dispatch_governance_binding, $binding )", 'false', 'gateway-regression-runtime.php'),
    ('write governance input binding removed', 'class-mad4b-scp-abilities.php', "$identity['target_input_sha256'] = $target_input_sha256;", "$identity['target_input_sha256'] = str_repeat( '0', 64 );", 'gateway-regression-runtime.php'),
    ('permission-to-execution governance rebind allowed', 'class-mad4b-scp-abilities.php', "! hash_equals( $captured_hash, $execute_hash )", 'false', 'gateway-regression-runtime.php'),
    ('approval UUIDv4 version guard removed', 'class-mad4b-scp-identifiers.php', "4[a-f0-9]{3}-[89ab]", "[a-f0-9]{4}-[89ab]", 'identity-context-runtime.php'),
    ('approval execution scope restoration removed', 'class-mad4b-scp-identity-context.php', "self::$request_approval_ticket_id = $previous;", '/* approval scope restoration removed */', 'identity-context-runtime.php'),
    ('write execution observation cleanup removed', 'class-mad4b-scp-abilities.php', "MAD4B_SCP_Authorization::clear_execution_callback_observation( $ability_name );", '/* execution observation cleanup removed */', 'gateway-regression-runtime.php'),
    ('context receipt byte budget removed', 'class-mad4b-scp-abilities.php', "strlen( $receipt_json ) > $receipt_budget", 'false', 'gateway-regression-runtime.php'),
    ('context receipt issuer budget removed', 'class-mad4b-scp-context-preflight.php', "strlen( $encoded_receipt ) > self::MAX_RECEIPT_TRANSPORT_BYTES", 'false', 'context-preflight-runtime.php'),
    ('context receipt HMAC verification removed', 'class-mad4b-scp-context-preflight.php', "! hash_equals( self::receipt_signature( $expected_digest ), $signature )", 'false', 'context-preflight-runtime.php'),
    ('classification canonical ordering removed', 'class-mad4b-scp-ability-contract-inspector.php', "sort( $keys, SORT_STRING );", '/* canonical ordering removed */', 'ability-contract-inspector-runtime.php'),
    ('receipt signature ignored', 'class-mad4b-scp-preparation-receipt.php', "! hash_equals( hash_hmac( 'sha256', $parts[1], self::key() ), $parts[2] )", 'false', 'preparation-receipt-runtime.php'),
    ('receipt expiry ignored', 'class-mad4b-scp-preparation-receipt.php', "$p['expires_at'] <= $now", 'false', 'preparation-receipt-runtime.php'),
    ('receipt nonce requirement removed', 'class-mad4b-scp-preparation-receipt.php', "! isset( $p['nonce'] ) || ! is_string( $p['nonce'] ) || 1 !== preg_match( '/^[a-f0-9]{32}$/D', $p['nonce'] )", 'false', 'preparation-receipt-runtime.php'),
    ('receipt subject ignored', 'class-mad4b-scp-preparation-receipt.php', "! hash_equals( MAD4B_SCP_Ability_Catalog_Transport::current_authority_scope(), $p['authority_scope_sha256'] )", 'false', 'preparation-receipt-runtime.php'),
    ('receipt descriptor ignored', 'class-mad4b-scp-preparation-receipt.php', "! hash_equals( $row['descriptor_sha256'], $p['descriptor_sha256'] )", 'false', 'preparation-receipt-runtime.php'),
    ('receipt dispatcher verification ignored', 'class-mad4b-scp-abilities.php', 'if ( is_wp_error( $receipt ) ) return $receipt;', '/* ignored receipt failure */', 'preparation-receipt-runtime.php'),
    ('execution orphan promoted out of reconciliation', 'class-mad4b-scp-execution-state-view.php', "if ( $orphan || $hazard_signal ) {", 'if ( $hazard_signal ) {', 'execution-state-view-runtime.php'),
    ('execution journal hazard ignored', 'class-mad4b-scp-execution-state-view.php', "if ( $orphan || $hazard_signal ) {", 'if ( $orphan ) {', 'execution-state-view-runtime.php'),
    ('unknown execution reconciliation fail-open', 'class-mad4b-scp-execution-state-view.php', "if ( self::UNKNOWN === $state ) $reconciliation = true;", '/* unknown reconciliation requirement removed */', 'execution-state-view-runtime.php'),
    ('execution completed without outcome promoted to success', 'class-mad4b-scp-execution-state-view.php', "if ( in_array( $outcome, $success_outcomes, true ) ) {", "if ( '' === $outcome || in_array( $outcome, $success_outcomes, true ) ) {", 'execution-state-view-runtime.php'),
    ('completed idempotency digest evidence ignored', 'class-mad4b-scp-execution-state-view.php', "&& $result_digest_valid", "&& true", 'execution-state-view-runtime.php'),
    ('verified no-effect reconciliation reference ignored', 'class-mad4b-scp-execution-state-view.php', "&& $reconciliation_ref_present", "&& true", 'execution-state-view-runtime.php'),
    ('not-started execution-entry proof ignored', 'class-mad4b-scp-execution-state-view.php', "'not_started' === $mutation_state && $target_execution_known && false === $target_execution_entered", "'not_started' === $mutation_state", 'execution-state-view-runtime.php'),
    ('cron cleanup omitted', 'class-mad4b-scp-catalog-lifecycle.php', "wp_clear_scheduled_hook( 'mad4b_catalog_gc' );", '/* omitted */', 'catalog-lifecycle-runtime.php'),
]
files = ['class-mad4b-scp-execution-state-view.php', 'class-mad4b-scp-identifiers.php', 'class-mad4b-scp-identity-context.php', 'class-mad4b-scp-context-preflight.php', 'class-mad4b-scp-semantic-content-field-contracts.php', 'class-mad4b-scp-ability-contract-inspector.php', 'class-mad4b-scp-capability-descriptor-registry.php', 'class-mad4b-scp-preparation-receipt.php', 'class-mad4b-scp-distributed-lock.php', 'class-mad4b-scp-unified-capability-gateway.php', 'class-mad4b-scp-chatgpt-tool-projection.php', 'class-mad4b-scp-ability-catalog-transport.php', 'class-mad4b-scp-catalog-object-store.php', 'class-mad4b-scp-abilities.php', 'class-mad4b-scp-catalog-lifecycle.php']
with tempfile.TemporaryDirectory(prefix='mad4b-gateway-mutants-') as tmp:
    target = Path(tmp)
    (target / 'includes').mkdir()
    (target / 'tests').mkdir()
    for test in {m[4] for m in mutations}:
        shutil.copy2(root / 'tests' / test, target / 'tests' / test)
    for name in files:
        shutil.copy2(root / 'includes' / name, target / 'includes' / name)
    for test in {m[4] for m in mutations}:
        baseline = subprocess.run(['php', str(target / 'tests' / test)], capture_output=True, text=True, timeout=10)
        assert baseline.returncode == 0, f'Pristine behavioral test failed: {test}: {baseline.stderr}'
    for label, file, before, after, test in mutations:
        for name in files:
            shutil.copy2(root / 'includes' / name, target / 'includes' / name)
        path = target / 'includes' / file
        code = path.read_text()
        assert before in code, f'Mutation anchor missing: {label}'
        path.write_text(code.replace(before, after))
        try:
            result = subprocess.run(['php', str(target / 'tests' / test)], capture_output=True, text=True, timeout=10)
        except subprocess.TimeoutExpired:
            # Nonblocking single-flight regressions can recurse: the CI guard
            # must terminate them rather than hanging the runner.
            assert label == 'single-flight fail-open'
            print(f'KILLED {label} (bounded timeout)')
            continue
        assert result.returncode != 0, f'Regression survived behavioral CI: {label}'
        assert 'syntax error' not in result.stderr.lower(), f'Mutation only caused syntax failure: {label}'
        print(f'KILLED {label}')
print(f'PASS {len(mutations)} representative regression mutations rejected')
runpy.run_path(str(root / 'tests' / 'capability-fabric-bulk-hardening-contract.py'), run_name='__main__')


# Runtime compatibility is intentionally exercised without changing the
# baseline-owned workflow. These fixtures run in isolated PHP processes so
# WP_CLI and DOING_CRON constants can be proven independently.
for compatibility_fixture in (
    'runtime-compatibility-profile-runtime.php',
    'runtime-compatibility-profile-cron.php',
):
    result = subprocess.run(
        ['php', str(root / 'tests' / compatibility_fixture)],
        capture_output=True,
        text=True,
        timeout=15,
    )
    assert result.returncode == 0, (
        f'Runtime compatibility fixture failed: {compatibility_fixture}: '
        f'{result.stdout}\n{result.stderr}'
    )
    print(result.stdout.strip())

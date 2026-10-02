"""Prove behavioral CI rejects representative regressions, not just marker loss."""
from pathlib import Path
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
    ('classification canonical ordering removed', 'class-mad4b-scp-ability-contract-inspector.php', "sort( $keys, SORT_STRING );", '/* canonical ordering removed */', 'ability-contract-inspector-runtime.php'),
    ('receipt signature ignored', 'class-mad4b-scp-preparation-receipt.php', "! hash_equals( hash_hmac( 'sha256', $parts[1], self::key() ), $parts[2] )", 'false', 'preparation-receipt-runtime.php'),
    ('receipt expiry ignored', 'class-mad4b-scp-preparation-receipt.php', "$p['expires_at'] <= $now", 'false', 'preparation-receipt-runtime.php'),
    ('receipt subject ignored', 'class-mad4b-scp-preparation-receipt.php', "! hash_equals( MAD4B_SCP_Ability_Catalog_Transport::current_authority_scope(), $p['authority_scope_sha256'] )", 'false', 'preparation-receipt-runtime.php'),
    ('receipt descriptor ignored', 'class-mad4b-scp-preparation-receipt.php', "! hash_equals( $row['descriptor_sha256'], $p['descriptor_sha256'] )", 'false', 'preparation-receipt-runtime.php'),
    ('receipt dispatcher verification ignored', 'class-mad4b-scp-abilities.php', 'if ( is_wp_error( $receipt ) ) return $receipt;', '/* ignored receipt failure */', 'preparation-receipt-runtime.php'),
    ('cron cleanup omitted', 'class-mad4b-scp-catalog-lifecycle.php', "wp_clear_scheduled_hook( 'mad4b_catalog_gc' );", '/* omitted */', 'catalog-lifecycle-runtime.php'),
]
files = ['class-mad4b-scp-ability-contract-inspector.php', 'class-mad4b-scp-capability-descriptor-registry.php', 'class-mad4b-scp-preparation-receipt.php', 'class-mad4b-scp-distributed-lock.php', 'class-mad4b-scp-unified-capability-gateway.php', 'class-mad4b-scp-chatgpt-tool-projection.php', 'class-mad4b-scp-ability-catalog-transport.php', 'class-mad4b-scp-catalog-object-store.php', 'class-mad4b-scp-abilities.php', 'class-mad4b-scp-catalog-lifecycle.php']
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

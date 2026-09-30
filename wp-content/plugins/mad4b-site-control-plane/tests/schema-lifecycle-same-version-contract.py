from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
life=(ROOT/'includes/class-mad4b-scp-schema-lifecycle.php').read_text(encoding='utf-8')
main=(ROOT/'mad4b-site-control-plane.php').read_text(encoding='utf-8')
for marker in ('package_identity()', 'MAD4B_SCP_Schema::is_ready()', 'wp_schedule_single_event', 'maybe_reconcile_admin_lifecycle', 'reconcile_scheduled', 'LOCK_OPTION', 'retry_due()', 'admin_package_lifecycle', 'next_attempt_at', 'MAD4B_SCP_Schema::install_or_upgrade()', 'applied_package_identity', 'wp_clear_scheduled_hook'):
    assert marker in life, marker
assert "MAD4B_SCP_Schema_Lifecycle::boot();" in main
assert '0.4.0-rc.84' in main
assert 'hash_file' not in life
assert 'lstat(' not in life
assert 'hash_file' not in life
assert 'MAD4B_SCP_Schema::MIGRATION_ID' in life and 'MAD4B_SCP_Schema::VERSION' in life
print('mad4b.schema-lifecycle-same-version.v1: PASS')

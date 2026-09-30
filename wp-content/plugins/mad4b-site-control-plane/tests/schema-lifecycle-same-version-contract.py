from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
life=(ROOT/'includes/class-mad4b-scp-schema-lifecycle.php').read_text(encoding='utf-8')
main=(ROOT/'mad4b-site-control-plane.php').read_text(encoding='utf-8')
for marker in ('package_identity()', 'MAD4B_SCP_Schema::is_ready()', 'wp_schedule_single_event', 'maybe_reconcile_admin_lifecycle', 'reconcile_scheduled', 'mark_current_package_applied', 'LOCK_OPTION', 'mad4b_scp_runtime_maintenance_lock_v1', 'retry_due()', 'restart_grace()', 'admin_package_lifecycle', 'next_attempt_at', 'MAD4B_SCP_Schema::install_or_upgrade()', 'applied_package_identity', 'wp_clear_scheduled_hook'):
    assert marker in life, marker
assert "MAD4B_SCP_Schema_Lifecycle::boot();" in main
plugin=(ROOT/'includes/class-mad4b-scp-plugin.php').read_text(encoding='utf-8')
assert "MAD4B_SCP_Schema_Lifecycle::mark_current_package_applied( 'activation' )" in plugin
assert '0.4.0-rc.85' in main
assert 'hash_file' not in life
assert 'lstat(' not in life
assert 'hash_file' not in life
assert 'MAD4B_SCP_Schema::MIGRATION_ID' in life and 'MAD4B_SCP_Schema::VERSION' in life
assert "MAD4B_SCP_Self_Update::managed_apply_in_progress()" in life
assert "if ( ! empty( $grace['active'] ) ) return;" in life
print('mad4b.schema-lifecycle-same-version.v1: PASS')

#!/usr/bin/env python3
from pathlib import Path

root = Path(__file__).resolve().parents[1]
abilities = (root / 'includes/class-mad4b-scp-abilities.php').read_text(encoding='utf-8')
overrides = (root / 'includes/class-mad4b-scp-governed-ability-overrides.php').read_text(encoding='utf-8')
plugin = (root / 'includes/class-mad4b-scp-plugin.php').read_text(encoding='utf-8')
runtime = (root / 'tests/runtime-readonly-diagnostics-smoke.php').read_text(encoding='utf-8')

assert "'mad4b/diagnostics-health'" in abilities
assert "false, true, false, true" in abilities, 'diagnostics ability must remain annotated readonly'
for marker in [
    "'mad4b/diagnostics-health' === $name",
    "array( __CLASS__, 'diagnostics_health_readonly' )",
    "mad4b.readonly-diagnostics.v1",
    "backup_root_readonly_status",
    "'observational_only' => true",
    "'prepares_backup_root' => false",
    "'path_disclosed' => false",
    "wp_upload_dir( null, false )",
]:
    assert marker in overrides, f'missing observational diagnostics marker: {marker}'

method = overrides.split('public static function diagnostics_health_readonly()', 1)[1].split('public static function content_update_post', 1)[0]
for forbidden in [
    'prepare_backup_root(',
    'wp_mkdir_p(',
    'mkdir(',
    'chmod(',
    'file_put_contents(',
    'fopen(',
    'rename(',
    'unlink(',
    'update_option(',
    'add_option(',
    'delete_option(',
]:
    assert forbidden not in method, f'readonly diagnostics performs mutation primitive: {forbidden}'

assert 'MAD4B_SCP_Governed_Ability_Overrides::boot();' in plugin
for marker in [
    'runtime-readonly-diagnostics.v1',
    'Diagnostics created a missing backup root.',
    "'mad4b.readonly-diagnostics.v1'",
]:
    assert marker in runtime, f'missing runtime observational proof: {marker}'

print('mad4b.site-control-plane.readonly-diagnostics.v1: PASS')

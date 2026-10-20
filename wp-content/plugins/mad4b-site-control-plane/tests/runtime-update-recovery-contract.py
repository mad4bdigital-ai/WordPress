"""Execute standalone recovery/broker fixtures; never treat them as PHPUnit."""
from pathlib import Path
import subprocess

root = Path(__file__).resolve().parents[1]
for fixture in ('runtime-update-recovery-runtime.php', 'dynamic-first-party-enrollment-runtime.php'):
    subprocess.run(['php', '-n', str(root / 'tests' / fixture)], check=True)
servers = (root / 'includes/class-mad4b-scp-servers.php').read_text()
enrollment_mount = servers.split("'mad4b-enrollment' => array_values", 1)[1].split("'mad4b-content' =>", 1)[0]
assert 'MAD4B_SCP_Runtime_Release_Set::chatgpt_step_up_tools()' in enrollment_mount
update = (root / 'includes/class-mad4b-scp-self-update.php').read_text()
native = update.split('public static function native_apply', 1)[1].split('public static function bootstrap_native_apply', 1)[0]
assert ': self::can_bootstrap_native_apply( $input );' in native
assert 'MAD4B_SCP_Runtime_Release_Set::revalidate_control_plane_bootstrap( $input )' in native
assert '(bool) $bootstrap_revalidate && empty( $runtime_bootstrap )' in native
replacement = update.split('private static function apply_verified_archive', 1)[1]
assert replacement.index('MAD4B_SCP_Runtime_Release_Set::revalidate_control_plane_bootstrap( array() )') < replacement.index('$upgrader->install(')
print('RUNTIME_UPDATE_RECOVERY_CONTRACT_PASS; hermetic only, live acceptance pending')

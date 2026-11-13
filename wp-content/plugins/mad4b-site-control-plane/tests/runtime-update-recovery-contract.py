"""Execute standalone recovery/broker fixtures; never treat them as PHPUnit."""
from pathlib import Path
import subprocess

root = Path(__file__).resolve().parents[1]
for fixture in ('runtime-update-recovery-runtime.php', 'dynamic-first-party-enrollment-runtime.php',
                'native-update-routing-runtime.php', 'adaptive-operation-router-runtime.php',
                'post-manual-update-guidance-runtime.php', 'plugin-operation-readiness-runtime.php',
                'read-callback-boundary-runtime.php', 'gateway-regression-runtime.php',
                'context-scan-completeness-runtime.php', 'drive-scan-continuation-runtime.php'):
    subprocess.run(['php', '-n', str(root / 'tests' / fixture)], check=True)
parity = (root / 'includes/class-mad4b-scp-remote-operation-parity.php').read_text()
enrollment_mount = parity.split('public static function enrollment_abilities()', 1)[1].split('public static function register_abilities()', 1)[0]
assert 'array_keys( MAD4B_SCP_Governed_Ability_Census::eligible() )' in enrollment_mount
assert '$registry_ready' in enrollment_mount
update = (root / 'includes/class-mad4b-scp-self-update.php').read_text()
native = update.split('public static function native_apply', 1)[1].split('public static function bootstrap_native_apply', 1)[0]
planner = update.split('public static function native_plan', 1)[1].split('public static function native_apply', 1)[0]
assert planner.index("$plan['plan_sha256'] =") < planner.index("$plan['execution_guidance'] =")
assert ': self::can_bootstrap_native_apply( $input );' in native
assert 'MAD4B_SCP_Runtime_Release_Set::revalidate_control_plane_bootstrap( $input )' in native
assert '(bool) $bootstrap_revalidate && empty( $runtime_bootstrap )' in native
replacement = update.split('private static function apply_verified_archive', 1)[1]
assert replacement.index('MAD4B_SCP_Runtime_Release_Set::revalidate_control_plane_bootstrap( array() )') < replacement.index('$upgrader->install(')
print('RUNTIME_UPDATE_RECOVERY_CONTRACT_PASS; hermetic only, live acceptance pending')

includes = root / 'includes'
assert "$checkpoint['repair_guidance'] = self::repair_guidance(" in (includes / 'class-mad4b-scp-staging-write-authority.php').read_text()
assert "$result['repair_guidance'] = self::repair_guidance( $result, self::persisted_status() );" in (includes / 'class-mad4b-scp-skill-runtime-certification.php').read_text()
assert "$public['verification_guidance'] = self::connection_verification_guidance( $status );" in (includes / 'class-mad4b-scp-google-drive-context.php').read_text()

read_consistency = (includes / 'class-mad4b-scp-read-consistency.php').read_text()
assert "'wp_register_ability_args', array( 'MAD4B_SCP_Connector_Resilience', 'wrap_read_registration' ), 180, 2" in read_consistency
abilities = (includes / 'class-mad4b-scp-abilities.php').read_text()
assert "'prepare_execution' => array( 'type' => 'boolean', 'default' => true )" in abilities
assert "array_key_exists( 'prepare_execution', $input ) && false === $input['prepare_execution']" in abilities

provider = (includes / 'class-mad4b-scp-context-provider-gateway.php').read_text()
assert 'MAD4B_SCP_Google_Drive_Context::scan_source_page( $source, $continuation )' in provider
admin = (includes / 'class-mad4b-scp-context-admin-ui.php').read_text()
handler = admin.split('public static function handle_scan_source()',1)[1].split('public static function handle_remove_source()',1)[0]
assert handler.index('self::require_admin( self::ACTION_SCAN_SOURCE )') < handler.index('MAD4B_SCP_Drive_Scan_Admin::step')
assert 'source_scan_apply_admission' in handler
authority = (includes / 'class-mad4b-scp-context-authority.php').read_text()
assert "isset( $scan['expected_registry_revision'] ) && (int) $scan['expected_registry_revision'] !== self::registry_revision()" in authority

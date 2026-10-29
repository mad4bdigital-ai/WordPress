from pathlib import Path
import json
root=Path(__file__).resolve().parents[2]
src=root/'wp-content/plugins/mad4b-site-control-plane/includes'
registry=(src/'class-mad4b-scp-adapter-registry.php').read_text()
provider=(src/'class-mad4b-scp-native-ability-trust.php').read_text()
manifest=json.loads((Path(__file__).parent/'manifest.example.json').read_text())
assert manifest['contract']=='mad4b.native-ability-trust.v1'
assert manifest['site_host']!='tours.dev.mad4b.com'
for guard in ['MAD4B_SCP_NATIVE_ABILITY_MANIFEST_PATH','MAD4B_SCP_NATIVE_ABILITY_MANIFEST_SHA256',
              'hash_equals','hash_file','get_input_schema','get_meta','is_callable','source_file',
              'WP_CONTENT_DIR','owner_approval_required','OWNER_PIN_VERIFIED']:
    assert guard in provider,guard
for unsafe in ['wp_update_post(', 'update_option(', 'wp_insert_post(', 'wp_remote_post(',
               'shell_exec(', 'system(', 'file_put_contents(']:
    assert unsafe not in provider,unsafe
for tenant in ['tours.dev.mad4b.com','company-performance-scan-plan','35138','26212']:
    assert tenant not in provider and tenant not in registry, tenant
assert "mad4b/native-capability-registry" in registry
assert "class-mad4b-scp-native-ability-trust.php" in registry
assert "native_capability_registry" in registry
assert "register_registry_ability" in registry
assert "class-mad4b-scp-external-adapter-trust.php" in registry
print('Native Ability Manifest contract PASS')

#!/usr/bin/env python3
import json
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
src=(ROOT/'includes/adapters/class-mad4b-scp-form-provider-adapters.php').read_text('utf-8')
main=(ROOT/'mad4b-site-control-plane.php').read_text('utf-8')
registry=(ROOT/'includes/class-mad4b-scp-adapter-registry.php').read_text('utf-8')
catalog=(ROOT/'config/adapter-support-catalog.json').read_text('utf-8')
for marker in [
 'MAD4B_SCP_JetFormBuilder_Adapter','jetformbuilder/list-forms','jetformbuilder/get-form',
 "const POST_TYPE = 'jet-form-builder'","'submission_values_exposed'=>false","'form_content_exposed'=>false",
 'MAD4B_SCP_FluentForms_Adapter','fluentforms/list-forms','fluentforms/get-form',
 "fluentFormApi('forms')","'entries_exposed'=>false","'submission_values_exposed'=>false",
]:
    assert marker in src, marker
for forbidden in ['wp_insert_post(','wp_update_post(','wp_delete_post(','$wpdb->insert','$wpdb->update','$wpdb->delete','fluentform/submission']:
    assert forbidden not in src, forbidden
assert 'class-mad4b-scp-form-provider-adapters.php' in main
# Registration moved to the source-reviewed policy manifest. The registry intentionally
# contains no hard-coded vendor adapter class names; approval cannot be widened by a hook.
policy=json.loads((ROOT/'config/adapter-registration-policy.json').read_text('utf-8'))
assert policy['contract']=='mad4b.adapter-registration-policy.v1'
assert policy['approval_model']=='source_reviewed_manifest_no_runtime_self_enrollment'
assert policy['unknown_plugin_mutation']=='deny'
for cls in ('MAD4B_SCP_JetFormBuilder_Adapter','MAD4B_SCP_FluentForms_Adapter'):
    assert cls in policy['approved_classes'], cls+' missing exact reviewed source admission'
    assert cls in policy['default_classes'], cls+' missing bundled default registration'
assert 'approved_bundled_adapter_classes()' in registry
assert 'default_bundled_adapter_classes()' in registry
assert 'is_trusted_bundled_class' in registry
assert '"functional_mode": "specialized"' in catalog
print('mad4b.form-provider-read-adapters.contract.v1: PASS')

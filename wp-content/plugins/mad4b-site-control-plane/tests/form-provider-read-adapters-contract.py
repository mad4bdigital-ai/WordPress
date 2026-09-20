#!/usr/bin/env python3
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
assert 'MAD4B_SCP_JetFormBuilder_Adapter' in registry and 'MAD4B_SCP_FluentForms_Adapter' in registry
assert '"functional_mode": "specialized"' in catalog
print('mad4b.form-provider-read-adapters.contract.v1: PASS')

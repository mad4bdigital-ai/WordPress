#!/usr/bin/env python3
from pathlib import Path
root=Path(__file__).resolve().parents[1]
files={
 'elementor':(root/'includes/adapters/class-mad4b-scp-elementor-adapter.php').read_text(),
 'jetengine':(root/'includes/adapters/class-mad4b-scp-jetengine-adapter.php').read_text(),
 'jetsmart':(root/'includes/adapters/class-mad4b-scp-jetsmartfilters-adapter.php').read_text(),
 'base':(root/'includes/adapters/class-mad4b-scp-adapter-base.php').read_text(),
 'reversible':(root/'includes/class-mad4b-scp-reversible-adapter-mutations.php').read_text(),
}
def req(key,*needles):
    for n in needles:
        if n not in files[key]: raise SystemExit(f'FAIL {key}: missing {n!r}')
def deny(key,*needles):
    for n in needles:
        if n in files[key]: raise SystemExit(f'FAIL {key}: forbidden {n!r}')
req('base','reversible_contract_for','MAD4B_SCP_Reversible_Adapter_Mutations::execute','MAD4B_SCP_Authorization::authorize_mutation')
req('reversible','before_sha256','after_sha256','mad4b_undo_state_drift','mad4b_undo_verification_failed','restore_reversible_state','read_reversible_state','rollback_payload_sha256')
req('elementor','mad4b.rollback.elementor-widget-settings.v1','remove_settings','exact_provider_certified','mad4b_elementor_stale_document','mad4b_elementor_widget_not_unique','mad4b_elementor_setting_policy_denied','mad4b_elementor_readback_mismatch','replace_widget_settings')
req('jetengine','mad4b.rollback.jetengine-post-meta.v1',"'_listing_data'",'mad4b_jetengine_listing_data_value_invalid','mad4b_jetengine_listing_data_scope_denied','mad4b_jetengine_stale_meta','restore_reversible_state')
req('jetsmart','mad4b.rollback.jetsmartfilters-filter-meta.v1',"'_query_var'",'mad4b_jetsmartfilters_meta_missing','mad4b_jetsmartfilters_query_var_invalid','mad4b_jetsmartfilters_stale_meta','mad4b_jetsmartfilters_readback_mismatch','restore_reversible_state')
for key in ('elementor','jetengine','jetsmart'):
    deny(key,'$wpdb->query(', 'eval(', 'shell_exec(', 'exec(', 'system(', 'passthru(')
print('mad4b.provider-bounded-reversible.v1: PASS')

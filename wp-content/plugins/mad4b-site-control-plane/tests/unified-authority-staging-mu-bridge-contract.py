#!/usr/bin/env python3
"""MU bridge is read-only, avoids modifying Source Provenance or executing shell."""
from pathlib import Path
import re
root=Path(__file__).resolve().parents[4]
p=root/'ops/central-rollout/315-mad4b-unified-authority-bridge.php'
s=p.read_text()
need=[
    "add_action( 'plugins_loaded'",
    "MAD4B_SCP_Central_Company_Rollout",
    "MAD4B_SCP_Central_Release_Registry",
    "MAD4B_SCP_Policy",
    "MAD4B_SCP_Unified_Operation_Authority",
    "MAD4B_SCP_Central_Operator_Handoff::boot();",
]
for a in need:assert a in s,a
for forbidden in ["wp_set_current_user(", "shell_exec(", "exec(", "system(", "wp_insert_post(", "grant_ability(", "update_option(", "MAD4B_SCP_Policy::can_mutate()"]:
    assert forbidden not in s,forbidden
assert "if ( ! class_exists( 'MAD4B_SCP_Unified_Operation_Authority', false ) )" in s
assert "if ( ! class_exists( 'MAD4B_SCP_Central_Operator_Handoff', false ) )" in s
print("UNIFIED_AUTHORITY_STAGING_MU_BRIDGE_CONTRACT: PASS")

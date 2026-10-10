#!/usr/bin/env python3
"""IMP12 static safeguards only; native PHPUnit/WordPress not implied."""
from pathlib import Path
P=Path(__file__).resolve().parents[1]
s=(P/"includes"/"class-mad4b-scp-import-schema-onboarding.php").read_text()
registry=(P/"includes"/"class-mad4b-scp-content-experience-profiles.php").read_text()
ux=(P/"includes"/"class-mad4b-scp-activity-import-experience.php").read_text()
fixture=(P/"tests"/"imp12-schema-onboarding-runtime.php").read_text()
preflight=(P/"tests"/"feature007-manual-preflight.py").read_text()
def req(condition,why):
    if not condition: raise AssertionError(why)
for t in ("get_post_types(", "post_type_exists(", "get_post_type_object(",
          "get_registered_meta_keys( 'post', $type )",
          "get_object_taxonomies(", "is_protected_meta(",
          "show_in_rest", "lexical_only_not_business_meaning",
          "field_mapping_approved' => false", "wordPress_profile_created' => false",
          "jetengine_cct_definition_certified' => false",
          "mutation_performed' => false", "origin_enrolled()",
          "environment_allowed( array( 'staging' ) )"):
    req(t in s,"Missing safe no-Profile schema gate "+t)
req("get_posts(" not in s and "wp_insert_post(" not in s and
    "wp_update_post(" not in s and "update_option(" not in s,
    "Zero-Profile discovery attempted a business mutation")
req("business-activity-import-schema-onboarding-plan" in registry and
    "class-mad4b-scp-import-schema-onboarding.php" in registry,
    "Onboarding schema ability is not registered on MCP")
req("No Content Experience Profile is configured yet" in ux and
    "MAD4B_SCP_Import_Schema_Onboarding::plan( array() )" in ux and
    "JetEngine CCT requires independent native discovery" in ux,
    "Empty-Profile import wizard still dead ends without safe CPT first-run help")
req("internal WordPress post type" not in fixture or "attachment" in fixture,
    "Missing native internal-post-type negative")
for t in ("class-mad4b-scp-import-schema-onboarding.php",
          "imp12-schema-onboarding-runtime.php",
          "imp12-schema-onboarding-source-contract.py"):
    req(t in preflight, "Missing IMP12 native/preflight enrollment "+t)
print("PASS IMP12 bounded WordPress-only schema discovery without guessing CCT ownership (STATIC ONLY)")

#!/usr/bin/env python3
"""Verify the non-authorizing, site-neutral WP User / Role Governance Skill pack."""
import json
import re
from pathlib import Path

repo=Path(__file__).resolve().parents[4]
plugin=repo/"wp-content/plugins/mad4b-site-control-plane"
portable=repo/"plugins/mad4b-wordpress/skills"
name="wordpress-user-role-governance"
manifest=json.loads((plugin/"config/skill-seed-manifest.json").read_text("utf-8"))
policy=json.loads((plugin/"config/user-role-governance-contract.json").read_text("utf-8"))
seeder=(plugin/"includes/class-mad4b-scp-skill-seeder.php").read_text("utf-8")
source=(plugin/"skill-seeds"/name/"SKILL.md").read_bytes()
mirror=(portable/name/"SKILL.md").read_bytes()
text=source.decode("utf-8")
assert manifest["contract"]=="mad4b.skill-seed-manifest.v1"
assert manifest["seed_version"]==15 and "const SEED_VERSION = 15;" in seeder
rows=[row for row in manifest["skills"] if row.get("name")==name]
assert len(rows)==1 and rows[0]=={"level":"workflow","target":"user-role-governance","name":name,"enabled":True}
assert source==mirror and len(source)>2900
assert text.startswith("---\nname: "+name+"\ndescription: ")
for marker in [
"wp_roles()", "get_role()", "WP_User", "user_can()", "current_user_can()", "map_meta_cap()",
"OAuth", "NHI", "Site Profile", "multisite", "super admin", "tenant", "FluentBoards",
"edit_posts", "edit_users", "manage_options", "manage_woocommerce", "PLAN_ONLY",
"WordPress", "readback", "rollback", "approval", "negative access", "Rate"
]:
 assert marker.lower() in text.lower(), marker
assert not re.search(r"https?://(?:staging\.|tours\.dev|localhost)|(?:user_id|form_id|tour_id)\s*[:=]\s*\d+",text,re.I)
assert policy["contract"]=="mad4b.wordpress-user-role-governance-blueprint.v1"
assert policy["site_neutral"] and policy["mode"]=="plan_only"
assert policy["runtime_enforcer"] is False
assert all(policy[k] is True for k in ["does_not_register_wp_roles","does_not_register_wp_capabilities","does_not_register_mutation_abilities"])
expected={"traveler","reviewer","commercial_manager","booking_operator","site_administrator","developer_support"}
assert set(p["id"] for p in policy["personas"])==expected
assert len({p["id"] for p in policy["personas"]})==len(policy["personas"])
assert all(p["responsibilities"] and p["never_inherit"] for p in policy["personas"])
gates={row["id"]:row for row in policy["workflow_gates"]}
for gate in ["qualification","quote_draft","final_quote_approval","traveler_acceptance","woo_order","supplier_booking"]:
 assert gate in gates and gates[gate]["owner"] and gates[gate]["requires"]
assert gates["final_quote_approval"]["owner"] != gates["traveler_acceptance"]["owner"]
assert gates["woo_order"]["requires"] == "certified_write_with_final_quote_approval_and_traveler_acceptance"
assert policy["default_unavailable_behavior"]=="deny_and_return_plan_only"
assert "user_delete" in policy["restricted_operations"] and "capability_grant" in policy["restricted_operations"]
assert all(policy["acceptance"].values())
print("wordpress-user-role-governance-contract: PASS")

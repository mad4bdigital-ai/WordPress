#!/usr/bin/env python3
from pathlib import Path
root=Path(__file__).resolve().parents[1]
site=(root/"includes/class-mad4b-scp-site-profile.php").read_text(encoding="utf-8")
approval=(root/"includes/class-mad4b-scp-approval-tickets.php").read_text(encoding="utf-8")
durable=(root/"includes/class-mad4b-scp-durable-execution.php").read_text(encoding="utf-8")
projection=(root/"includes/class-mad4b-scp-chatgpt-tool-projection.php").read_text(encoding="utf-8")
catalog=(root/"includes/class-mad4b-scp-ability-catalog-transport.php").read_text(encoding="utf-8")
subject=(root/"includes/class-mad4b-scp-oauth-subject-user-bridge.php").read_text(encoding="utf-8")
guard=(root/"includes/class-mad4b-scp-execution-commit-guard.php").read_text(encoding="utf-8")
def need(c,m):
    if not c: raise AssertionError(m)
need("profile_authority_quarantined" in site and "foreign_origin" in site and "environment_drift" in site,"Site Profile clone quarantine missing")
need("origin_enrolled()" in approval and "site_profile_binding" in approval and "profile_digest" in approval,"approval identity is not Site Profile bound")
need("'site_binding' =>" in durable and "Ability_Contract_Inspector::site_binding" in durable,"durable scope is not tenant/origin bound")
need("current_binding()" in projection and "binding_matches" in projection,"projection state is not site-binding fenced")
need("Ability_Contract_Inspector::site_binding()" in catalog,"catalog/preparation authority scope lacks site binding")
need("MAD4B_SCP_Site_Profile::origin_enrolled()" in subject and "user_is_enrolled" in subject,"OAuth subject mapping can survive foreign Site Profile")
need("'site_profile'" in guard and "subject_lifecycle" in guard,"commit guard does not revalidate cloned profile/subject state")
print("mad4b.clone-authority-quarantine.contract.v1: PASS")

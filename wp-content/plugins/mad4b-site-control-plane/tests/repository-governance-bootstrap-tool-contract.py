#!/usr/bin/env python3
from pathlib import Path

script = Path("tools/Apply-Mad4bMasterRuleset.ps1").read_text(encoding="utf-8")

required = [
    '"--jq",".[] | @json"',
    '$currentLines = @(& gh @currentArgs)',
    'if ($current.Count -eq 0)',
    'Write-Host "[]"',
    '$requiredProperties = @("id","name","enforcement")',
    '$item.PSObject.Properties[$propertyName]',
    'malformed ruleset list entry missing',
    '"repos/$Repository/rulesets/${rulesetId}?includes_parents=true"',
    '"--method","PUT"',
    '"repos/$Repository/rulesets/$rulesetId"',
    '"--input",$TemplatePath',
    'repository ruleset reconciliation failed',
    'Reconciled existing ruleset id=$rulesetId',
    '$currentBranch = (& git branch --show-current).Trim()',
    '$currentBranch -ne "master"',
    'ruleset activation is post-merge only',
    '"repos/$Repository/commits/master"',
    '$remoteMaster -ne $currentHead',
    'post_merge_master_verified=true',
    '$ExpectedConfirmation = "APPLY_MAD4B_MASTER_RULESET:$Repository:$($ExpectedHead.ToLowerInvariant())"',
]

missing = [needle for needle in required if needle not in script]
if missing:
    raise SystemExit("repository governance bootstrap parser contract missing: " + ", ".join(missing))

for forbidden in [
    '$current = @($currentRaw | ConvertFrom-Json)',
    '"repos/$Repository/rulesets/$rulesetId?includes_parents=true"',
]:
    if forbidden in script:
        raise SystemExit("legacy Windows PowerShell empty-array parser returned: " + forbidden)

print("REPOSITORY_GOVERNANCE_BOOTSTRAP_TOOL_CONTRACT: PASS")
print("empty_ruleset_list=zero_items")
template = Path(".github/mad4b-master-ruleset-template.json").read_text(encoding="utf-8")
for required_check in ["Repository release verdict", "Repository feature boundary", '"integration_id": 15368']:
    if required_check not in template:
        raise SystemExit(f"canonical master ruleset template missing trusted check: {required_check}")

print("ruleset_shape_guard=id,name,enforcement")
print("existing_named_ruleset=reconciled_to_exact_template")
print("activation_timing=post_merge_master_only")
print("confirmation_scope=repository_plus_exact_head")

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
]

missing = [needle for needle in required if needle not in script]
if missing:
    raise SystemExit("repository governance bootstrap parser contract missing: " + ", ".join(missing))

for forbidden in [
    '$current = @($currentRaw | ConvertFrom-Json)',
]:
    if forbidden in script:
        raise SystemExit("legacy Windows PowerShell empty-array parser returned: " + forbidden)

print("REPOSITORY_GOVERNANCE_BOOTSTRAP_TOOL_CONTRACT: PASS")
print("empty_ruleset_list=zero_items")
print("ruleset_shape_guard=id,name,enforcement")

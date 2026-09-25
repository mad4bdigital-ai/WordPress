#!/usr/bin/env python3
from pathlib import Path
import json
import subprocess
import tempfile

script = Path("tools/Apply-Mad4bMasterRuleset.ps1").read_text(encoding="utf-8")

required = [
    '"--jq",".[] | @json"',
    '"repos/$Repository/rulesets?includes_parents=false"',
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
    '"tools/verify_repository_ruleset_template.py"',
    '"tools/verify_repository_ruleset_restore.py"',
    'canonical ruleset template does not exactly implement repository governance policy',
    '$rollbackMode = "restore"',
    '$rollbackMode = "delete"',
    'pre_apply_ruleset_snapshot=ready',
    '--readback $ReadbackPath',
    'canonical_readback_match=true',
    'ruleset_rollback=restored_previous',
    'ruleset_rollback=deleted_new_ruleset',
    'GOVERNANCE_APPLY_RECOVERY_REQUIRED',
    '$Utf8NoBom = New-Object System.Text.UTF8Encoding($false)',
    '[System.IO.File]::WriteAllText($RollbackPayloadPath, $rollbackPayloadJson, $Utf8NoBom)',
    '[System.IO.File]::WriteAllText($ReadbackPath, [string]$detailRaw, $Utf8NoBom)',
    '[string]$before.source_type -ne "Repository"',
    '[string]$before.source -ne $Repository',
    '$rollbackRules = @()',
    'PSObject.Properties["require_extra_approval_for_unattributed_changes"]',
    'PSObject.Properties.Remove("require_extra_approval_for_unattributed_changes")',
    '$before.PSObject.Properties["bypass_actors"]',
    'bypass-actor state is not observable',
    'existing canonical ruleset contains bypass actors',
    '$BeforeReadbackPath = Join-Path $env:TEMP "mad4b-ruleset-before-readback.json"',
    '$RollbackReadbackPath = Join-Path $env:TEMP "mad4b-ruleset-rollback-readback.json"',
    '--before $BeforeReadbackPath --after $RollbackReadbackPath --repository $Repository',
    'ruleset_rollback_readback=verified',
    'ruleset_rollback_deletion_readback=verified',
    'previous ruleset restore did not verify against the exact pre-apply state',
    'newly-created ruleset still exists after automatic rollback',
    '"tools/build_repository_ruleset_attestation.py"',
    '$RulesetAttestationPath = Join-Path $env:TEMP "mad4b-ruleset-attestation.json"',
    '--readback $ReadbackPath --policy $PolicyPath --template $TemplatePath --repository $Repository --output $RulesetAttestationPath',
    '$attestationScope -ne "environment"',
    '$environmentName -ne "repository-governance"',
    '$variableName -ne "MAD4B_RULESET_ATTESTATION"',
    '"repos/$Repository/environments/$environmentName"',
    'governance environment create/update failed',
    'governance_environment_readback=verified',
    '"repos/$Repository/environments/$environmentName/variables/$variableName"',
    '"--method","PATCH"',
    '"--method","POST"',
    '"repos/$Repository/environments/$environmentName/variables"',
    'environment-scoped ruleset attestation variable upsert failed',
    'environment-scoped ruleset attestation variable readback mismatch',
    'ruleset_attestation_readback=verified',
    '[Environment]::SetEnvironmentVariable($variableName, $attestationValue, "Process")',
    '$status.ruleset_attestation_verified -ne $true',
    'freshly-built attestation did not satisfy aggregate repository governance',
    '--require-ruleset-attestation',
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


ruleset_verifier = Path("tools/verify_repository_ruleset_template.py")
if not ruleset_verifier.is_file():
    raise SystemExit("canonical ruleset verifier is missing")
ruleset_text = ruleset_verifier.read_text(encoding="utf-8")
for needle in [
    "mad4b.repository-ruleset-template.v1",
    "live ruleset mutable fields do not exactly match canonical template",
    "required status checks differ from policy",
    "pull_request rule differs from policy",
    "ruleset template contains ungoverned conditions",
]:
    if needle not in ruleset_text:
        raise SystemExit(f"canonical ruleset verifier contract missing: {needle}")

print("template_policy_preflight=exact")
print("post_mutation_readback=canonical")
print("readback_mismatch_rollback=bounded")


canonical_template_proc = subprocess.run(
    [
        "python3",
        "tools/verify_repository_ruleset_template.py",
        "--template",
        ".github/mad4b-master-ruleset-template.json",
        "--policy",
        ".github/mad4b-repository-governance-policy.json",
    ],
    text=True,
    capture_output=True,
)
if canonical_template_proc.returncode != 0:
    raise SystemExit(
        "canonical ruleset template executable preflight failed: "
        + (canonical_template_proc.stderr or canonical_template_proc.stdout)
    )

print("canonical_template_executable_preflight=pass")
print("windows_json_encoding=utf8_no_bom")
print("repository_ruleset_discovery=local_only")
print("rollback_payload=response_only_fields_stripped")
print("bypass_evidence=explicit")


restore_verifier = Path("tools/verify_repository_ruleset_restore.py")
if not restore_verifier.is_file():
    raise SystemExit("repository ruleset restore verifier is missing")
restore_text = restore_verifier.read_text(encoding="utf-8")
for needle in [
    "mad4b.repository-ruleset-restore-readback.v1",
    "restored ruleset does not match the exact pre-apply mutable state",
    "ruleset id changed across automatic restore",
    "bypass-actor evidence is missing",
]:
    if needle not in restore_text:
        raise SystemExit(f"repository ruleset restore verifier contract missing: {needle}")

fixture = {
    "id": 23968498,
    "name": "MAD4B master release governance",
    "target": "branch",
    "source_type": "Repository",
    "source": "mad4bdigital-ai/WordPress",
    "enforcement": "active",
    "bypass_actors": [],
    "conditions": {
        "ref_name": {
            "include": ["refs/heads/master"],
            "exclude": [],
        }
    },
    "rules": [
        {"type": "deletion"},
        {"type": "non_fast_forward"},
        {
            "type": "pull_request",
            "parameters": {
                "allowed_merge_methods": ["merge"],
                "dismiss_stale_reviews_on_push": False,
                "require_code_owner_review": False,
                "require_last_push_approval": False,
                "required_approving_review_count": 0,
                "required_review_thread_resolution": True,
                "required_reviewers": [],
                "require_extra_approval_for_unattributed_changes": True,
            },
        },
        {
            "type": "required_status_checks",
            "parameters": {
                "do_not_enforce_on_create": False,
                "required_status_checks": [
                    {
                        "context": "Repository release verdict",
                        "integration_id": 15368,
                    }
                ],
                "strict_required_status_checks_policy": True,
            },
        },
    ],
}

with tempfile.TemporaryDirectory() as td:
    root = Path(td)
    before = root / "before.json"
    after = root / "after.json"
    output = root / "result.json"
    before.write_text(json.dumps(fixture), encoding="utf-8")
    after.write_text(json.dumps(fixture), encoding="utf-8")
    restore_ok = subprocess.run(
        [
            "python3",
            "tools/verify_repository_ruleset_restore.py",
            "--before",
            str(before),
            "--after",
            str(after),
            "--repository",
            "mad4bdigital-ai/WordPress",
            "--output",
            str(output),
        ],
        text=True,
        capture_output=True,
    )
    if restore_ok.returncode != 0:
        raise SystemExit(
            "repository ruleset restore executable PASS fixture failed: "
            + (restore_ok.stderr or restore_ok.stdout)
        )
    restored = json.loads(output.read_text(encoding="utf-8"))
    if restored.get("ready") is not True or restored.get("restored_exactly") is not True:
        raise SystemExit("repository ruleset restore PASS fixture did not certify exact restore")

    drifted = json.loads(json.dumps(fixture))
    drifted["rules"][-1]["parameters"]["required_status_checks"].append(
        {"context": "unexpected", "integration_id": 15368}
    )
    after.write_text(json.dumps(drifted), encoding="utf-8")
    restore_bad = subprocess.run(
        [
            "python3",
            "tools/verify_repository_ruleset_restore.py",
            "--before",
            str(before),
            "--after",
            str(after),
            "--repository",
            "mad4bdigital-ai/WordPress",
        ],
        text=True,
        capture_output=True,
    )
    if restore_bad.returncode == 0:
        raise SystemExit("repository ruleset restore verifier accepted a drifted restore")

print("rollback_readback_verifier=executable")
print("rollback_drift_rejection=pass")


attestation_builder = Path("tools/build_repository_ruleset_attestation.py")
if not attestation_builder.is_file():
    raise SystemExit("repository ruleset attestation builder is missing")
builder_text = attestation_builder.read_text(encoding="utf-8")
for needle in [
    "mad4b.repository-ruleset-attestation.v1",
    "privileged ruleset readback does not expose bypass actors",
    "ruleset_updated_at",
    "policy_sha256",
    "template_sha256",
    "bypass_actor_count",
    "require_extra_approval_for_unattributed_changes",
]:
    if needle not in builder_text:
        raise SystemExit(f"ruleset attestation builder contract missing: {needle}")

target_template = json.loads(Path(".github/mad4b-master-ruleset-template.json").read_text(encoding="utf-8"))
attestation_fixture = {
    "id": 23968498,
    "name": target_template["name"],
    "target": target_template["target"],
    "source_type": "Repository",
    "source": "mad4bdigital-ai/WordPress",
    "enforcement": target_template["enforcement"],
    "bypass_actors": [],
    "conditions": target_template["conditions"],
    "rules": json.loads(json.dumps(target_template["rules"])),
    "updated_at": "2026-09-26T00:00:00Z",
}
fixture_pull_request = next(
    row for row in attestation_fixture["rules"] if row.get("type") == "pull_request"
)
fixture_pull_request.setdefault("parameters", {})[
    "require_extra_approval_for_unattributed_changes"
] = True
with tempfile.TemporaryDirectory() as td:
    root = Path(td)
    readback = root / "readback.json"
    output = root / "attestation.json"
    readback.write_text(json.dumps(attestation_fixture), encoding="utf-8")
    build_ok = subprocess.run(
        [
            "python3",
            "tools/build_repository_ruleset_attestation.py",
            "--readback",
            str(readback),
            "--policy",
            ".github/mad4b-repository-governance-policy.json",
            "--template",
            ".github/mad4b-master-ruleset-template.json",
            "--repository",
            "mad4bdigital-ai/WordPress",
            "--output",
            str(output),
        ],
        text=True,
        capture_output=True,
    )
    if build_ok.returncode != 0:
        raise SystemExit(
            "ruleset attestation executable PASS fixture failed: "
            + (build_ok.stderr or build_ok.stdout)
        )
    attestation = json.loads(output.read_text(encoding="utf-8"))
    if (
        attestation.get("contract") != "mad4b.repository-ruleset-attestation.v1"
        or attestation.get("bypass_actor_count") != 0
        or attestation.get("ruleset_updated_at") != "2026-09-26T00:00:00Z"
        or attestation.get("verified_readback") is not True
    ):
        raise SystemExit("ruleset attestation PASS fixture did not bind exact privileged readback")

    unsafe = json.loads(json.dumps(attestation_fixture))
    unsafe["bypass_actors"] = [
        {"actor_id": 1, "actor_type": "RepositoryRole", "bypass_mode": "always"}
    ]
    readback.write_text(json.dumps(unsafe), encoding="utf-8")
    build_bad = subprocess.run(
        [
            "python3",
            "tools/build_repository_ruleset_attestation.py",
            "--readback",
            str(readback),
            "--policy",
            ".github/mad4b-repository-governance-policy.json",
            "--template",
            ".github/mad4b-master-ruleset-template.json",
            "--repository",
            "mad4bdigital-ai/WordPress",
            "--output",
            str(output),
        ],
        text=True,
        capture_output=True,
    )
    if build_bad.returncode == 0:
        raise SystemExit("ruleset attestation builder accepted non-empty bypass actors")

print("ruleset_attestation_builder=executable")
print("ruleset_attestation_nonzero_bypass_rejection=pass")
print("ruleset_attestation_scope=environment")
print("ruleset_attestation_environment=repository-governance")
print("ruleset_attestation_variable=upsert_and_readback")


attestation_build_pos = script.index('Write-Host "=== BUILD RULESET ATTESTATION ==="')
aggregate_verify_pos = script.index(
    '& $PythonCommand "tools/verify_repository_governance.py"',
    attestation_build_pos,
)
variable_publish_pos = script.index('Write-Host "=== UPSERT RULESET ATTESTATION VARIABLE ==="')
if not (attestation_build_pos < aggregate_verify_pos < variable_publish_pos):
    raise SystemExit(
        "ruleset attestation must be built and aggregate-verified before repository-variable publish"
    )

print("ruleset_attestation_publish_order=build+verify+publish")

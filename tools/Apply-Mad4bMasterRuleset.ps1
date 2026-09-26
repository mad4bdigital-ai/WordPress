param(
    [string]$Repository = "mad4bdigital-ai/WordPress",
    [string]$TemplatePath = ".github/mad4b-master-ruleset-template.json",
    [string]$PolicyPath = ".github/mad4b-repository-governance-policy.json",
    [Parameter(Mandatory = $true)]
    [ValidatePattern("^[0-9a-fA-F]{40}$")]
    [string]$ExpectedHead,
    [string]$Confirmation
)

$ErrorActionPreference = "Stop"
Set-StrictMode -Version Latest
$Utf8NoBom = New-Object System.Text.UTF8Encoding($false)

$ExpectedConfirmation = "APPLY_MAD4B_MASTER_RULESET:${Repository}:$($ExpectedHead.ToLowerInvariant())"
if ($Confirmation -ne $ExpectedConfirmation) {
    throw "GOVERNANCE_APPLY_FAIL_CLOSED: confirmation mismatch. Expected '$ExpectedConfirmation'."
}

if (-not (Get-Command git -ErrorAction SilentlyContinue)) {
    throw "GOVERNANCE_APPLY_FAIL_CLOSED: git is required for exact-head binding."
}
$currentHead = (& git rev-parse HEAD).Trim().ToLowerInvariant()
if ($LASTEXITCODE -ne 0 -or $currentHead -ne $ExpectedHead.ToLowerInvariant()) {
    throw "GOVERNANCE_APPLY_FAIL_CLOSED: exact-head mismatch. current=$currentHead expected=$ExpectedHead"
}
$dirty = @(& git status --porcelain)
if ($LASTEXITCODE -ne 0 -or $dirty.Count -ne 0) {
    throw "GOVERNANCE_APPLY_FAIL_CLOSED: working tree must be clean before governance apply."
}
$currentBranch = (& git branch --show-current).Trim()
if ($LASTEXITCODE -ne 0 -or $currentBranch -ne "master") {
    throw "GOVERNANCE_APPLY_FAIL_CLOSED: ruleset activation is post-merge only and must run from the local master branch."
}

if (-not (Get-Command gh -ErrorAction SilentlyContinue)) {
    throw "GOVERNANCE_APPLY_FAIL_CLOSED: GitHub CLI (gh) is required."
}
$PythonCommand = if (Get-Command python -ErrorAction SilentlyContinue) { "python" } elseif (Get-Command python3 -ErrorAction SilentlyContinue) { "python3" } else { $null }
if (-not $PythonCommand) {
    throw "GOVERNANCE_APPLY_FAIL_CLOSED: Python is required for governed readback."
}
foreach ($RequiredPath in @($TemplatePath, $PolicyPath, "tools/verify_repository_governance.py", "tools/verify_repository_ruleset_template.py", "tools/verify_repository_ruleset_restore.py", "tools/build_repository_ruleset_attestation.py", "tools/publish_repository_ruleset_attestation.py")) {
    if (-not (Test-Path -LiteralPath $RequiredPath -PathType Leaf)) {
        throw "GOVERNANCE_APPLY_FAIL_CLOSED: required file not found: $RequiredPath"
    }
}

$template = Get-Content -LiteralPath $TemplatePath -Raw | ConvertFrom-Json
if ($template.name -ne "MAD4B master release governance") {
    throw "GOVERNANCE_APPLY_FAIL_CLOSED: unexpected ruleset name."
}
if ($template.target -ne "branch" -or $template.enforcement -ne "active") {
    throw "GOVERNANCE_APPLY_FAIL_CLOSED: template must be an active branch ruleset."
}
if (@($template.bypass_actors).Count -ne 0) {
    throw "GOVERNANCE_APPLY_FAIL_CLOSED: bypass actors are forbidden."
}
$includedRefs = @($template.conditions.ref_name.include)
if ($includedRefs.Count -ne 1 -or $includedRefs[0] -ne "refs/heads/master") {
    throw "GOVERNANCE_APPLY_FAIL_CLOSED: template must target only refs/heads/master."
}

$TemplateVerificationPath = Join-Path $env:TEMP "mad4b-ruleset-template-verification.json"
Remove-Item -LiteralPath $TemplateVerificationPath -Force -ErrorAction SilentlyContinue
& $PythonCommand "tools/verify_repository_ruleset_template.py" --template $TemplatePath --policy $PolicyPath --output $TemplateVerificationPath
if ($LASTEXITCODE -ne 0 -or -not (Test-Path -LiteralPath $TemplateVerificationPath -PathType Leaf)) {
    throw "GOVERNANCE_APPLY_FAIL_CLOSED: canonical ruleset template does not exactly implement repository governance policy."
}
$templateVerification = Get-Content -LiteralPath $TemplateVerificationPath -Raw | ConvertFrom-Json
if ($templateVerification.ready -ne $true -or $templateVerification.readback_verified -ne $false) {
    throw "GOVERNANCE_APPLY_FAIL_CLOSED: canonical template preflight did not reach ready=true."
}

Write-Host "=== EXACT SOURCE ==="
Write-Host "head=$currentHead"
Write-Host "working_tree=clean"
Write-Host "=== AUTHORITY PREFLIGHT ==="
& gh auth status
if ($LASTEXITCODE -ne 0) {
    throw "GOVERNANCE_APPLY_FAIL_CLOSED: gh authentication is not ready."
}
$remoteMaster = (& gh api -H "Accept: application/vnd.github+json" "repos/$Repository/commits/master" --jq ".sha").Trim().ToLowerInvariant()
if ($LASTEXITCODE -ne 0 -or $remoteMaster -ne $currentHead) {
    throw "GOVERNANCE_APPLY_FAIL_CLOSED: local master is not the exact current repository master. local=$currentHead remote=$remoteMaster"
}
Write-Host "remote_master=$remoteMaster"
Write-Host "post_merge_master_verified=true"

Write-Host "=== CURRENT RULESETS ==="
$currentArgs = @(
    "api",
    "-H","Accept: application/vnd.github+json",
    "-H","X-GitHub-Api-Version: 2026-03-10",
    "repos/$Repository/rulesets?includes_parents=false",
    "--jq",".[] | @json"
)
$currentLines = @(& gh @currentArgs)
if ($LASTEXITCODE -ne 0) {
    throw "GOVERNANCE_APPLY_FAIL_CLOSED: unable to read repository rulesets."
}

$current = @()
foreach ($line in $currentLines) {
    if ([string]::IsNullOrWhiteSpace([string]$line)) {
        continue
    }

    $item = [string]$line | ConvertFrom-Json
    if ($null -eq $item) {
        throw "GOVERNANCE_APPLY_FAIL_CLOSED: ruleset list contained a null entry."
    }

    $requiredProperties = @("id","name","enforcement")
    foreach ($propertyName in $requiredProperties) {
        if ($null -eq $item.PSObject.Properties[$propertyName]) {
            throw "GOVERNANCE_APPLY_FAIL_CLOSED: malformed ruleset list entry missing '$propertyName'."
        }
    }

    $current += $item
}

if ($current.Count -eq 0) {
    Write-Host "[]"
} else {
    $current | ConvertTo-Json -Depth 100
}

$named = @($current | Where-Object { $_.name -eq $template.name })
$unexpected = @($current | Where-Object { $_.name -ne $template.name })
if ($unexpected.Count -gt 0) {
    $names = ($unexpected | ForEach-Object { "$($_.id):$($_.name):$($_.enforcement)" }) -join ", "
    throw "GOVERNANCE_APPLY_FAIL_CLOSED: unexpected existing rulesets require reconciliation before apply: $names"
}

$rulesetId = $null
$mutationPerformed = $false
$rollbackMode = "none"
$RollbackPayloadPath = Join-Path $env:TEMP "mad4b-ruleset-pre-apply-backup.json"
$BeforeReadbackPath = Join-Path $env:TEMP "mad4b-ruleset-before-readback.json"
$ReadbackPath = Join-Path $env:TEMP "mad4b-ruleset-readback.json"
$RollbackReadbackPath = Join-Path $env:TEMP "mad4b-ruleset-rollback-readback.json"
$RollbackVerificationPath = Join-Path $env:TEMP "mad4b-ruleset-rollback-verification.json"
$RulesetAttestationPath = Join-Path $env:TEMP "mad4b-ruleset-attestation.json"
$RulesetAttestationPublicationPath = Join-Path $env:TEMP "mad4b-ruleset-attestation-publication.json"
$GovernanceStatusPath = Join-Path $env:TEMP "mad4b-repository-governance-status.json"
Remove-Item -LiteralPath $RollbackPayloadPath,$BeforeReadbackPath,$ReadbackPath,$RollbackReadbackPath,$RollbackVerificationPath,$RulesetAttestationPath,$RulesetAttestationPublicationPath,$GovernanceStatusPath -Force -ErrorAction SilentlyContinue
if ($named.Count -gt 1) {
    throw "GOVERNANCE_APPLY_FAIL_CLOSED: duplicate MAD4B governance rulesets detected."
} elseif ($named.Count -eq 1) {
    $rulesetId = [string]$named[0].id
    $beforeRaw = & gh api -H "Accept: application/vnd.github+json" -H "X-GitHub-Api-Version: 2026-03-10" "repos/$Repository/rulesets/${rulesetId}?includes_parents=true"
    if ($LASTEXITCODE -ne 0) {
        throw "GOVERNANCE_APPLY_FAIL_CLOSED: unable to capture exact pre-apply ruleset for rollback."
    }
    [System.IO.File]::WriteAllText($BeforeReadbackPath, [string]$beforeRaw, $Utf8NoBom)
    $before = $beforeRaw | ConvertFrom-Json
    if ($null -eq $before.PSObject.Properties["bypass_actors"]) {
        throw "GOVERNANCE_APPLY_FAIL_CLOSED: bypass-actor state is not observable with the active credential; mutation is forbidden."
    }
    if (@($before.bypass_actors).Count -ne 0) {
        throw "GOVERNANCE_APPLY_FAIL_CLOSED: existing canonical ruleset contains bypass actors; automatic reconciliation is forbidden."
    }
    if ([string]$before.source_type -ne "Repository" -or [string]$before.source -ne $Repository) {
        throw "GOVERNANCE_APPLY_FAIL_CLOSED: selected canonical ruleset is not repository-owned by $Repository."
    }
    $rollbackRules = @()
    foreach ($rule in @($before.rules)) {
        $rollbackRule = ([string]($rule | ConvertTo-Json -Depth 100)) | ConvertFrom-Json
        if ([string]$rollbackRule.type -eq "pull_request" -and $null -ne $rollbackRule.parameters) {
            $responseOnly = $rollbackRule.parameters.PSObject.Properties["require_extra_approval_for_unattributed_changes"]
            if ($null -ne $responseOnly) {
                $rollbackRule.parameters.PSObject.Properties.Remove("require_extra_approval_for_unattributed_changes")
            }
        }
        $rollbackRules += $rollbackRule
    }
    $rollbackPayload = [ordered]@{
        name = $before.name
        target = $before.target
        enforcement = $before.enforcement
        bypass_actors = @($before.bypass_actors)
        conditions = $before.conditions
        rules = $rollbackRules
    }
    $rollbackPayloadJson = $rollbackPayload | ConvertTo-Json -Depth 100
    [System.IO.File]::WriteAllText($RollbackPayloadPath, $rollbackPayloadJson, $Utf8NoBom)
    $rollbackMode = "restore"
    Write-Host "pre_apply_ruleset_snapshot=ready"
    Write-Host "=== RECONCILE EXISTING RULESET ==="
    Write-Host "Existing named ruleset detected; applying the exact reviewed template. id=$rulesetId"
    $updateArgs = @(
        "api",
        "--method","PUT",
        "-H","Accept: application/vnd.github+json",
        "-H","X-GitHub-Api-Version: 2026-03-10",
        "repos/$Repository/rulesets/$rulesetId",
        "--input",$TemplatePath
    )
    $updatedRaw = & gh @updateArgs
    if ($LASTEXITCODE -ne 0) {
        throw "GOVERNANCE_APPLY_FAIL_CLOSED: repository ruleset reconciliation failed. Ensure the active gh credential has Administration:write for this repository."
    }
    $updated = $updatedRaw | ConvertFrom-Json
    if ($null -eq $updated -or [string]$updated.id -ne $rulesetId) {
        throw "GOVERNANCE_APPLY_FAIL_CLOSED: reconciled ruleset response did not preserve the expected ruleset id."
    }
    $mutationPerformed = $true
    Write-Host "Reconciled existing ruleset id=$rulesetId"
} else {
    Write-Host "=== CREATE RULESET ==="
    $createArgs = @("api","--method","POST","-H","Accept: application/vnd.github+json","-H","X-GitHub-Api-Version: 2026-03-10","repos/$Repository/rulesets","--input",$TemplatePath)
    $createdRaw = & gh @createArgs
    if ($LASTEXITCODE -ne 0) {
        throw "GOVERNANCE_APPLY_FAIL_CLOSED: repository ruleset creation failed. Ensure the active gh credential has Administration:write for this repository."
    }
    $created = $createdRaw | ConvertFrom-Json
    $rulesetId = [string]$created.id
    if ([string]::IsNullOrWhiteSpace($rulesetId)) {
        throw "GOVERNANCE_APPLY_FAIL_CLOSED: created ruleset response did not contain an id."
    }
    $mutationPerformed = $true
    $rollbackMode = "delete"
    Write-Host "Created ruleset id=$rulesetId"
}

Write-Host "=== EXACT READBACK ==="
$detailArgs = @("api","-H","Accept: application/vnd.github+json","-H","X-GitHub-Api-Version: 2026-03-10","repos/$Repository/rulesets/${rulesetId}?includes_parents=true")
$detailRaw = & gh @detailArgs
if ($LASTEXITCODE -ne 0) {
    throw "GOVERNANCE_APPLY_FAIL_CLOSED: exact ruleset readback failed."
}
$detail = $detailRaw | ConvertFrom-Json
$detail | ConvertTo-Json -Depth 100
[System.IO.File]::WriteAllText($ReadbackPath, [string]$detailRaw, $Utf8NoBom)

& $PythonCommand "tools/verify_repository_ruleset_template.py" --template $TemplatePath --policy $PolicyPath --readback $ReadbackPath --output $TemplateVerificationPath
$readbackTemplateRc = $LASTEXITCODE
if ($readbackTemplateRc -ne 0) {
    Write-Host "canonical_readback_match=false"
    if ($mutationPerformed -and $rollbackMode -eq "restore" -and (Test-Path -LiteralPath $RollbackPayloadPath -PathType Leaf)) {
        Write-Host "=== ROLLBACK RULESET ==="
        & gh api --method PUT -H "Accept: application/vnd.github+json" -H "X-GitHub-Api-Version: 2026-03-10" "repos/$Repository/rulesets/$rulesetId" --input $RollbackPayloadPath | Out-Null
        if ($LASTEXITCODE -ne 0) {
            throw "GOVERNANCE_APPLY_RECOVERY_REQUIRED: canonical readback mismatched and previous ruleset restoration failed. ruleset_id=$rulesetId"
        }
        $rollbackRaw = & gh api -H "Accept: application/vnd.github+json" -H "X-GitHub-Api-Version: 2026-03-10" "repos/$Repository/rulesets/$rulesetId"
        if ($LASTEXITCODE -ne 0) {
            throw "GOVERNANCE_APPLY_RECOVERY_REQUIRED: previous ruleset PUT returned success but restore readback failed. ruleset_id=$rulesetId"
        }
        [System.IO.File]::WriteAllText($RollbackReadbackPath, [string]$rollbackRaw, $Utf8NoBom)
        & $PythonCommand "tools/verify_repository_ruleset_restore.py" --before $BeforeReadbackPath --after $RollbackReadbackPath --repository $Repository --output $RollbackVerificationPath
        if ($LASTEXITCODE -ne 0) {
            throw "GOVERNANCE_APPLY_RECOVERY_REQUIRED: previous ruleset restore did not verify against the exact pre-apply state. ruleset_id=$rulesetId"
        }
        Write-Host "ruleset_rollback=restored_previous"
        Write-Host "ruleset_rollback_readback=verified"
    } elseif ($mutationPerformed -and $rollbackMode -eq "delete") {
        Write-Host "=== ROLLBACK CREATED RULESET ==="
        & gh api --method DELETE -H "Accept: application/vnd.github+json" -H "X-GitHub-Api-Version: 2026-03-10" "repos/$Repository/rulesets/$rulesetId"
        if ($LASTEXITCODE -ne 0) {
            throw "GOVERNANCE_APPLY_RECOVERY_REQUIRED: canonical readback mismatched and newly-created ruleset deletion failed. ruleset_id=$rulesetId"
        }
        $postDeleteIds = @(& gh api -H "Accept: application/vnd.github+json" -H "X-GitHub-Api-Version: 2026-03-10" "repos/$Repository/rulesets?includes_parents=false" --jq ".[].id")
        if ($LASTEXITCODE -ne 0) {
            throw "GOVERNANCE_APPLY_RECOVERY_REQUIRED: newly-created ruleset DELETE returned success but deletion readback failed. ruleset_id=$rulesetId"
        }
        if (@($postDeleteIds | ForEach-Object { [string]$_ }) -contains [string]$rulesetId) {
            throw "GOVERNANCE_APPLY_RECOVERY_REQUIRED: newly-created ruleset still exists after automatic rollback. ruleset_id=$rulesetId"
        }
        Write-Host "ruleset_rollback=deleted_new_ruleset"
        Write-Host "ruleset_rollback_deletion_readback=verified"
    }
    throw "GOVERNANCE_APPLY_FAIL_CLOSED: live ruleset did not exactly match the canonical template after mutation; automatic rollback completed."
}
Write-Host "canonical_readback_match=true"

Write-Host "=== BUILD RULESET ATTESTATION ==="
& $PythonCommand "tools/build_repository_ruleset_attestation.py" --readback $ReadbackPath --policy $PolicyPath --template $TemplatePath --repository $Repository --output $RulesetAttestationPath
if ($LASTEXITCODE -ne 0 -or -not (Test-Path -LiteralPath $RulesetAttestationPath -PathType Leaf)) {
    throw "GOVERNANCE_APPLY_FAIL_CLOSED: privileged ruleset attestation build failed."
}
Write-Host "=== PUBLISH OWNER RULESET ATTESTATION ==="
& $PythonCommand "tools/publish_repository_ruleset_attestation.py" --repository $Repository --policy $PolicyPath --template $TemplatePath --attestation $RulesetAttestationPath --output $RulesetAttestationPublicationPath
if ($LASTEXITCODE -ne 0 -or -not (Test-Path -LiteralPath $RulesetAttestationPublicationPath -PathType Leaf)) {
    throw "GOVERNANCE_APPLY_FAIL_CLOSED: owner-authored ruleset attestation publication failed."
}
$publication = Get-Content -LiteralPath $RulesetAttestationPublicationPath -Raw | ConvertFrom-Json
if (
    $publication.published -ne $true -or
    $publication.readback_verified -ne $true -or
    [string]$publication.authenticated_owner_login -ne "mad4bdigital-ai" -or
    [string]$publication.comment_author_login -ne "mad4bdigital-ai" -or
    [int]$publication.ledger_issue_number -lt 1 -or
    [int64]$publication.comment_id -lt 1
) {
    throw "GOVERNANCE_APPLY_FAIL_CLOSED: owner-authored attestation publication evidence is incomplete."
}
Write-Host "ruleset_attestation_scope=owner_issue_comment"
Write-Host "ruleset_attestation_issue_number=$($publication.ledger_issue_number)"
Write-Host "ruleset_attestation_comment_id=$($publication.comment_id)"
Write-Host "ruleset_attestation_author=$($publication.comment_author_login)"
Write-Host "ruleset_attestation_publish_readback=verified"

Write-Host "=== VERIFY AGGREGATE GOVERNANCE WITH PUBLISHED ATTESTATION ==="
& $PythonCommand "tools/verify_repository_governance.py" --repository $Repository --policy $PolicyPath --template $TemplatePath --require-ruleset-attestation --output $GovernanceStatusPath
if ($LASTEXITCODE -ne 0 -or -not (Test-Path -LiteralPath $GovernanceStatusPath -PathType Leaf)) {
    throw "GOVERNANCE_APPLY_FAIL_CLOSED: published owner attestation did not satisfy aggregate repository governance."
}
$status = Get-Content -LiteralPath $GovernanceStatusPath -Raw | ConvertFrom-Json
if ($status.ready -ne $true -or $status.ruleset_attestation_verified -ne $true) {
    throw "GOVERNANCE_APPLY_FAIL_CLOSED: aggregate governance did not verify the published owner attestation."
}

Write-Host ""
Write-Host "APPLY_MAD4B_MASTER_RULESET:${rulesetId}:ready"
Write-Host "mutation_performed=$($mutationPerformed.ToString().ToLowerInvariant())"
Write-Host "required_check=Repository release verdict"
Write-Host "required_check=Repository feature boundary"
Write-Host "required_check_integration_id=15368"
Write-Host "target_ref=refs/heads/master"
Write-Host "ruleset_attestation_scope=owner_issue_comment"
Write-Host "ruleset_attestation_issue_number=$($publication.ledger_issue_number)"
Write-Host "ruleset_attestation_comment_id=$($publication.comment_id)"
Write-Host "ruleset_attestation_author=$($publication.comment_author_login)"
Write-Host "reviewed_head=$currentHead"

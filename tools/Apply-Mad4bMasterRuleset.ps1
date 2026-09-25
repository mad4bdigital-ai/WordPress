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

$ExpectedConfirmation = "APPLY_MAD4B_MASTER_RULESET:$Repository"
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

if (-not (Get-Command gh -ErrorAction SilentlyContinue)) {
    throw "GOVERNANCE_APPLY_FAIL_CLOSED: GitHub CLI (gh) is required."
}
$PythonCommand = if (Get-Command python -ErrorAction SilentlyContinue) { "python" } elseif (Get-Command python3 -ErrorAction SilentlyContinue) { "python3" } else { $null }
if (-not $PythonCommand) {
    throw "GOVERNANCE_APPLY_FAIL_CLOSED: Python is required for governed readback."
}
foreach ($RequiredPath in @($TemplatePath, $PolicyPath, "tools/verify_repository_governance.py")) {
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

Write-Host "=== EXACT SOURCE ==="
Write-Host "head=$currentHead"
Write-Host "working_tree=clean"
Write-Host "=== AUTHORITY PREFLIGHT ==="
& gh auth status
if ($LASTEXITCODE -ne 0) {
    throw "GOVERNANCE_APPLY_FAIL_CLOSED: gh authentication is not ready."
}

Write-Host "=== CURRENT RULESETS ==="
$currentArgs = @(
    "api",
    "-H","Accept: application/vnd.github+json",
    "-H","X-GitHub-Api-Version: 2026-03-10",
    "repos/$Repository/rulesets?includes_parents=true",
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
if ($named.Count -gt 1) {
    throw "GOVERNANCE_APPLY_FAIL_CLOSED: duplicate MAD4B governance rulesets detected."
} elseif ($named.Count -eq 1) {
    $rulesetId = [string]$named[0].id
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

& $PythonCommand "tools/verify_repository_governance.py" --repository $Repository --policy $PolicyPath --output "mad4b-repository-governance-status.json"
if ($LASTEXITCODE -ne 0) {
    throw "GOVERNANCE_APPLY_FAIL_CLOSED: post-apply governed readback failed. Do not merge PR #60."
}

$status = Get-Content -LiteralPath "mad4b-repository-governance-status.json" -Raw | ConvertFrom-Json
if ($status.ready -ne $true) {
    throw "GOVERNANCE_APPLY_FAIL_CLOSED: readback did not reach ready=true."
}

Write-Host ""
Write-Host "APPLY_MAD4B_MASTER_RULESET:$rulesetId:ready"
Write-Host "mutation_performed=$($mutationPerformed.ToString().ToLowerInvariant())"
Write-Host "required_check=Repository release verdict"
Write-Host "required_check=Repository feature boundary"
Write-Host "required_check_integration_id=15368"
Write-Host "target_ref=refs/heads/master"
Write-Host "reviewed_head=$currentHead"

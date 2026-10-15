#Requires -Version 5.1
<#
Install a LOCAL, fail-closed G9 pre-commit guard for this Git checkout.
Never changes the GitHub repository, WordPress, Production or global Git settings.
Refuses to overwrite an existing unrelated pre-commit hook.
#>
[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [string]$RepositoryPath
)
$ErrorActionPreference = 'Stop'
$root = (Resolve-Path -LiteralPath $RepositoryPath -ErrorAction Stop).Path
$git = Get-Command git -ErrorAction Stop
$python = Get-Command python -ErrorAction Stop
$gitRoot = (& $git.Source -C $root rev-parse --show-toplevel 2>&1)
if ($LASTEXITCODE -ne 0 -or -not $gitRoot) {
    throw 'Not a readable Git repository'
}
$guard = Join-Path $root 'tools\mad4b_g9_precommit.py'
if (-not (Test-Path -LiteralPath $guard -PathType Leaf)) {
    throw "Repository guard missing: $guard (fetch current PR #258 source first)"
}
$effectiveHooksPath = (& $git.Source -C $root config --get core.hooksPath 2>$null)
if ($LASTEXITCODE -eq 0 -and -not [string]::IsNullOrWhiteSpace([string]$effectiveHooksPath)) {
    throw "Custom core.hooksPath already configured: $effectiveHooksPath. Refusing to install an inactive or conflicting standard hook."
}
$hookLocation = (& $git.Source -C $root rev-parse --git-path hooks/pre-commit 2>&1)
if ($LASTEXITCODE -ne 0 -or -not $hookLocation) {
    throw 'Cannot resolve local Git pre-commit hook path'
}
$hookLocation = [string]$hookLocation
if (-not [IO.Path]::IsPathRooted($hookLocation)) {
    $hookLocation = Join-Path $root $hookLocation
}
$hookDirectory = Split-Path -Parent $hookLocation
if (-not (Test-Path -LiteralPath $hookDirectory -PathType Container)) {
    New-Item -ItemType Directory -Path $hookDirectory -Force | Out-Null
}
$marker = 'MAD4B G9 LOCAL SOURCE INTEGRITY HOOK'
$hook = @'
#!/bin/sh
# MAD4B G9 LOCAL SOURCE INTEGRITY HOOK
set -eu
root="$(git rev-parse --show-toplevel)"
exec python "$root/tools/mad4b_g9_precommit.py"
'@
if (Test-Path -LiteralPath $hookLocation -PathType Leaf) {
    $existing = [IO.File]::ReadAllText($hookLocation)
    if (-not $existing.Contains($marker)) {
        throw "Another pre-commit hook exists; refusing to overwrite: $hookLocation"
    }
}
$utf8NoBom = New-Object System.Text.UTF8Encoding($false)
[IO.File]::WriteAllText($hookLocation, ($hook -replace "`r`n", "`n") + "`n", $utf8NoBom)
Write-Output "G9_LOCAL_PRECOMMIT_HOOK=INSTALLED"
Write-Output "GIT_HOOK=$hookLocation"
Write-Output "PYTHON=$($python.Source)"
Write-Output "SCOPE=LOCAL_GIT_COMMITS_ONLY; CI_AND_BUILD_STILL_FAIL_CLOSED"
Write-Output "NO_WORDPRESS_DEPLOYMENT=true; NO_PRODUCTION_MUTATION=true"

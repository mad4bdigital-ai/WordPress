#Requires -Version 5.1
<#
Local, non-authorizing CI evidence runner for any approved WordPress host.
Docker is optional for test isolation. This script NEVER connects to Hostinger
SSH, writes WordPress content, deploys plugins or changes production settings.
#>
[CmdletBinding()]
param(
    [Parameter(Mandatory=$true)][string]$RepositoryPath,
    [ValidateSet("PullRequest","Branch","Commit")][string]$SourceType="PullRequest",
    [Parameter(Mandatory=$true)][string]$Reference,
    [ValidateSet("Auto","Docker","Native")][string]$Runtime="Auto",
    [ValidateSet("Hostinger","WordPressHosted","WordPressLocal")][string]$TargetPlatform="Hostinger",
    [ValidateSet("Core","Extended")][string]$Profile="Extended",
    [string]$ExpectedSha="",
    [string]$SiteEvidence="",
    [string]$ExpectedSiteUrl="",
    [switch]$AllowNativeExecution,
    [switch]$ProbeSiteReadOnly,
    [string]$OutputRoot="$env:USERPROFILE\Downloads"
)
$ErrorActionPreference = "Stop"
$repoName = "mad4bdigital-ai/WordPress"
$repo = (Resolve-Path -LiteralPath $RepositoryPath).Path
$program = Join-Path $repo "tools\mad4b-local-ci-parity.py"
if (-not (Test-Path -LiteralPath $program -PathType Leaf)) {
    throw "Runner not in this local checkout. Pull the feature branch or materialize its files. No tests run."
}
$python = Get-Command py -ErrorAction SilentlyContinue
if ($python) {
    $pythonArgs = @("-3")
    $pythonExe = $python.Source
} else {
    $python = Get-Command python -ErrorAction Stop
    $pythonExe = $python.Source
    $pythonArgs = @()
}
$kind = switch ($SourceType) { "PullRequest" { "pull_request" }; "Branch" { "branch" }; "Commit" { "commit" } }
$target = switch ($TargetPlatform) { "Hostinger" { "hostinger" }; "WordPressHosted" { "wordpress_hosted" }; "WordPressLocal" { "wordpress_local" } }
if ($SourceType -eq "PullRequest") {
    if ($Reference -cnotmatch '^[1-9][0-9]{0,8}$') { throw "Invalid PR reference" }
    $url = "https://api.github.com/repos/$repoName/pulls/$Reference"
    $pr = Invoke-RestMethod -Uri $url -Headers @{ "Accept"="application/vnd.github+json"; "User-Agent"="MAD4B-Parity-Runner" } -TimeoutSec 20
    if ($pr.state -ne "open" -or $pr.head.repo.full_name -ne $repoName) { throw "PR must be open in the trusted repository" }
    $resolved = [string]$pr.head.sha
} elseif ($SourceType -eq "Branch") {
    if ($Reference -cnotmatch '^[A-Za-z0-9_][A-Za-z0-9._/-]{0,119}$' -or $Reference.Contains("..") -or $Reference.Contains("//") -or $Reference.EndsWith(".lock")) { throw "Invalid branch ref" }
    $branchPath = [uri]::EscapeDataString($Reference)
    $branch = Invoke-RestMethod -Uri "https://api.github.com/repos/$repoName/branches/$branchPath" -Headers @{ "Accept"="application/vnd.github+json"; "User-Agent"="MAD4B-Parity-Runner" } -TimeoutSec 20
    $resolved = [string]$branch.commit.sha
} else {
    $resolved = $Reference
}
if ($resolved -cnotmatch '^[a-f0-9]{40}$') { throw "GitHub did not resolve to a valid immutable source SHA" }
if ($ExpectedSha -and $ExpectedSha -cne $resolved) { throw "The selected source moved since approval. Abort." }
$OutputPath = Join-Path $OutputRoot ("MAD4B-LOCAL-CI-" + $resolved.Substring(0,12) + "-" + (Get-Date -Format "yyyyMMdd-HHmmss"))
$cli = @($program, "--repository-path", $repo, "--repository", $repoName,
    "--source-type", $kind, "--source-reference", $Reference,
    "--expected-sha", $resolved, "--runtime", $Runtime.ToLowerInvariant(),
    "--target-type", $target, "--profile", $Profile.ToLowerInvariant(),
    "--output", $OutputPath)
if ($AllowNativeExecution) { $cli += "--allow-native-execution" }
if ($SiteEvidence) { $cli += @("--site-evidence", $SiteEvidence) }
if ($ProbeSiteReadOnly) {
    if (-not $ExpectedSiteUrl) { throw "Use -ExpectedSiteUrl for the explicit read-only probe." }
    $cli += "--probe-site"
}
if ($ExpectedSiteUrl) { $cli += @("--expected-site-url", $ExpectedSiteUrl) }
Write-Host "Exact selected SHA: $resolved"
Write-Host "Host target: $target | isolated test runtime: $Runtime"
Write-Host "Output directory: $OutputPath"
& $pythonExe @pythonArgs @cli
if ($LASTEXITCODE -ne 0) { throw "Local CI gates are not all verified. Do not install or promote a package. Read $OutputPath\LOCAL-CI-PARITY-REPORT.json" }
Write-Host "Targeted offline tests passed. This is NOT full CI parity or live Hostinger acceptance."
Write-Host "Report: $OutputPath\LOCAL-CI-PARITY-REPORT.json"

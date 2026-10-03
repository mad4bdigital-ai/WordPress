[CmdletBinding()]
param(
    [string]$BaseUrl = '',
    [string]$Issuer = '',
    [string]$Resource = '',
    [string]$ClientId = 'mad4b-governed-canary',
    [string]$RedirectUri = 'http://127.0.0.1:8765/callback',
    [string]$Scope = 'mad4b:read offline_access',
    [int]$TimeoutSeconds = 180,
    [switch]$SelfTest
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

function ConvertTo-Base64Url([byte[]]$Bytes) {
    return [Convert]::ToBase64String($Bytes).TrimEnd('=').Replace('+','-').Replace('/','_')
}
function Get-RandomUrl([int]$Count) {
    $bytes = New-Object byte[] $Count
    [Security.Cryptography.RandomNumberGenerator]::Fill($bytes)
    return ConvertTo-Base64Url $bytes
}
function Get-Challenge([string]$Verifier) {
    $sha = [Security.Cryptography.SHA256]::Create()
    try { return ConvertTo-Base64Url ($sha.ComputeHash([Text.Encoding]::ASCII.GetBytes($Verifier))) }
    finally { $sha.Dispose() }
}
function Get-Origin([Uri]$Uri) {
    $port = if ($Uri.IsDefaultPort) { '' } else { ':' + $Uri.Port }
    return '{0}://{1}{2}' -f $Uri.Scheme.ToLowerInvariant(), $Uri.Host.ToLowerInvariant(), $port
}
function Get-AsMetadata([Uri]$Uri) {
    $path = $Uri.AbsolutePath.Trim('/')
    $origin = Get-Origin $Uri
    return $(if ($path) { "$origin/.well-known/oauth-authorization-server/$path" } else { "$origin/.well-known/oauth-authorization-server" })
}
function Get-ResourceMetadata([Uri]$Uri) {
    return "$(Get-Origin $Uri)/.well-known/oauth-protected-resource$($Uri.AbsolutePath)"
}
function ConvertFrom-Query([string]$Query) {
    $out = @{}
    foreach ($pair in $Query.TrimStart('?').Split('&')) {
        if (-not $pair) { continue }
        $parts = $pair.Split('=',2)
        $key = [Net.WebUtility]::UrlDecode($parts[0])
        $value = if ($parts.Count -gt 1) { [Net.WebUtility]::UrlDecode($parts[1]) } else { '' }
        $out[$key] = $value
    }
    return $out
}
function Send-LoopbackPage([Net.Sockets.TcpClient]$Client,[string]$Message) {
    $body = "<html><body><h2>$([Net.WebUtility]::HtmlEncode($Message))</h2><p>You can close this tab.</p></body></html>"
    $bodyBytes = [Text.Encoding]::UTF8.GetBytes($body)
    $head = [Text.Encoding]::ASCII.GetBytes("HTTP/1.1 200 OK`r`nContent-Type: text/html; charset=utf-8`r`nContent-Length: $($bodyBytes.Length)`r`nCache-Control: no-store`r`nConnection: close`r`n`r`n")
    $stream = $Client.GetStream(); $stream.Write($head,0,$head.Length); $stream.Write($bodyBytes,0,$bodyBytes.Length); $stream.Flush()
}
function Invoke-McpRequest(
    [string]$Uri,
    [string]$Token,
    [hashtable]$Payload,
    [string]$SessionId = '',
    [string]$ProtocolVersion = '2025-11-25'
) {
    $headers = @{Authorization="Bearer $Token";Accept='application/json, text/event-stream'}
    if ($SessionId) { $headers['Mcp-Session-Id'] = $SessionId }
    if ($ProtocolVersion) { $headers['MCP-Protocol-Version'] = $ProtocolVersion }
    try {
        $response = Invoke-WebRequest -Method Post -Uri $Uri -Headers $headers -ContentType 'application/json' -Body ($Payload | ConvertTo-Json -Depth 20 -Compress) -TimeoutSec 15
    } catch {
        if ($null -eq $_.Exception.Response) { throw }
        $status = try { [int]$_.Exception.Response.StatusCode } catch { [int]$_.Exception.Response.StatusCode.value__ }
        $stage = try { [string]$_.Exception.Response.Headers['X-MAD4B-MCP-Admission-Stage'] } catch { '' }
        $code = try { [string]$_.Exception.Response.Headers['X-MAD4B-MCP-Admission-Code'] } catch { '' }
        throw "MCP request failed with HTTP $status$(if($stage){" at $stage"}else{''})$(if($code){" [$code]"}else{''})."
    }
    $payloadJson = $null
    if ($response.Content) {
        $raw = [string]$response.Content
        if ($raw.TrimStart().StartsWith('data:')) {
            $raw = (($raw -split "\r?\n") | Where-Object { $_ -like 'data:*' } | ForEach-Object { $_.Substring(5).Trim() }) -join ''
        }
        if ($raw) { $payloadJson = $raw | ConvertFrom-Json -Depth 100 }
    }
    return [pscustomobject]@{
        Status = [int]$response.StatusCode
        Headers = $response.Headers
        Payload = $payloadJson
    }
}
function Assert-McpSuccess([string]$Stage,$Exchange) {
    if ($Exchange.Status -lt 200 -or $Exchange.Status -ge 300 -or $null -eq $Exchange.Payload -or $null -ne $Exchange.Payload.error -or $null -eq $Exchange.Payload.result) {
        $code = try { [string]$Exchange.Headers['X-MAD4B-MCP-Admission-Code'] } catch { '' }
        $admissionStage = try { [string]$Exchange.Headers['X-MAD4B-MCP-Admission-Stage'] } catch { '' }
        throw "$Stage failed with HTTP $($Exchange.Status)$(if($admissionStage){" at $admissionStage"}else{''})$(if($code){" [$code]"}else{''})."
    }
    if ($Exchange.Payload.result.isError -eq $true) { throw "$Stage returned an MCP tool error." }
    return $Exchange.Payload.result
}

if ($SelfTest) {
    $v = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk'
    if ((Get-Challenge $v) -ne 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM') { throw 'PKCE RFC 7636 self-test failed.' }
    Write-Host 'MAD4B local OAuth canary self-test: PASS'
    exit 0
}

if ($BaseUrl) {
    $siteBase = $BaseUrl.TrimEnd('/')
    if (-not $Issuer) { $Issuer = "$siteBase/oauth/mcp" }
    if (-not $Resource) { $Resource = "$siteBase/wp-json/mcp/mad4b-chatgpt" }
}
if (-not $Issuer -or -not $Resource) { throw 'Provide -BaseUrl, or provide both -Issuer and -Resource.' }

$issuerUri = [Uri]$Issuer; $resourceUri = [Uri]$Resource; $redirect = [Uri]$RedirectUri
if ($issuerUri.Scheme -ne 'https' -or $resourceUri.Scheme -ne 'https') { throw 'Issuer/resource must use HTTPS.' }
if ($redirect.Scheme -ne 'http' -or $redirect.Host -notin @('127.0.0.1','localhost','::1')) { throw 'RedirectUri must use an HTTP loopback host.' }

$resourceMetaUrl = Get-ResourceMetadata $resourceUri
$asMetaUrl = Get-AsMetadata $issuerUri
$resourceMeta = Invoke-RestMethod -Uri $resourceMetaUrl -Headers @{Accept='application/json'} -TimeoutSec 15
if ([string]$resourceMeta.resource -ne $Resource -or @($resourceMeta.authorization_servers) -notcontains $Issuer) { throw 'Protected-resource metadata mismatch.' }
$metadata = Invoke-RestMethod -Uri $asMetaUrl -Headers @{Accept='application/json'} -TimeoutSec 15
if ([string]$metadata.issuer -ne $Issuer -or @($metadata.code_challenge_methods_supported) -notcontains 'S256') { throw 'Authorization-server metadata mismatch.' }
$jwks = Invoke-RestMethod -Uri ([string]$metadata.jwks_uri) -Headers @{Accept='application/json'} -TimeoutSec 15
if (@($jwks.keys).Count -lt 1) { throw 'JWKS contains no signing keys.' }

$verifier = Get-RandomUrl 48; $challenge = Get-Challenge $verifier; $state = Get-RandomUrl 32
$q = [ordered]@{response_type='code';client_id=$ClientId;redirect_uri=$RedirectUri;scope=$Scope;resource=$Resource;code_challenge=$challenge;code_challenge_method='S256';state=$state}
$query = ($q.GetEnumerator() | ForEach-Object { '{0}={1}' -f [Uri]::EscapeDataString($_.Key),[Uri]::EscapeDataString([string]$_.Value) }) -join '&'
$authorizeUrl = ([string]$metadata.authorization_endpoint) + '?' + $query

$listener = [Net.Sockets.TcpListener]::new([Net.IPAddress]::Loopback,$redirect.Port); $client = $null
try {
    $listener.Start(); Write-Host 'Complete WordPress login and consent in the browser.'; Start-Process $authorizeUrl
    $task = $listener.AcceptTcpClientAsync(); if (-not $task.Wait([TimeSpan]::FromSeconds($TimeoutSeconds))) { throw 'Timed out waiting for OAuth callback.' }
    $client = $task.GetAwaiter().GetResult(); $reader = [IO.StreamReader]::new($client.GetStream(),[Text.Encoding]::ASCII,$false,4096,$true)
    $line = $reader.ReadLine(); while ($reader.ReadLine()) {}
    $target = $line.Split(' ')[1]; $callback = [Uri]("http://127.0.0.1:$($redirect.Port)$target"); $params = ConvertFrom-Query $callback.Query
    if ($params['error']) { Send-LoopbackPage $client 'OAuth authorization failed.'; throw "OAuth error: $($params['error'])" }
    if ($params['state'] -cne $state -or -not $params['code']) { Send-LoopbackPage $client 'OAuth callback validation failed.'; throw 'OAuth callback validation failed.' }
    $code = [string]$params['code']; Send-LoopbackPage $client 'Authorization received successfully.'
} finally { if ($client) { $client.Dispose() }; $listener.Stop() }

$token = Invoke-RestMethod -Method Post -Uri ([string]$metadata.token_endpoint) -ContentType 'application/x-www-form-urlencoded' -Body @{grant_type='authorization_code';code=$code;client_id=$ClientId;redirect_uri=$RedirectUri;code_verifier=$verifier;resource=$Resource} -TimeoutSec 15
$accessToken = [string]$token.access_token
if (-not $accessToken) { throw 'Token endpoint returned no access token.' }
$initialize = Invoke-McpRequest $Resource $accessToken @{
    jsonrpc='2.0'; id=1; method='initialize'; params=@{
        protocolVersion='2025-11-25'
        capabilities=@{}
        clientInfo=@{name='mad4b-cli-canary';version='1.0.0'}
    }
}
$initializeResult = Assert-McpSuccess 'initialize' $initialize
$sessionId = [string]$initialize.Headers['Mcp-Session-Id']
if (-not $sessionId) { throw 'initialize succeeded without Mcp-Session-Id.' }
$protocolVersion = if ($initializeResult.protocolVersion) { [string]$initializeResult.protocolVersion } else { '2025-11-25' }

$tools = Invoke-McpRequest $Resource $accessToken @{jsonrpc='2.0';id=2;method='tools/list';params=@{}} $sessionId $protocolVersion
$toolsResult = Assert-McpSuccess 'tools/list' $tools
$toolNames = @($toolsResult.tools | ForEach-Object { [string]$_.name })
$recoveryTools = @('mad4b-site-profile-status','mad4b-session-safe-diagnostics','mad4b-site-info')
foreach ($toolName in $recoveryTools) {
    if ($toolNames -notcontains $toolName) { throw "tools/list omitted required recovery tool $toolName." }
}

$id = 10
foreach ($toolName in $recoveryTools) {
    $call = Invoke-McpRequest $Resource $accessToken @{jsonrpc='2.0';id=$id;method='tools/call';params=@{name=$toolName;arguments=@{}}} $sessionId $protocolVersion
    [void](Assert-McpSuccess "tools/call $toolName" $call)
    $id++
}

$accessToken = $null; $token = $null
Write-Host 'MAD4B Local OAuth Governed Canary: PASS'
Write-Host 'OAuth browser round-trip: PASS'
Write-Host 'PKCE S256: PASS'
Write-Host 'Token exchange: PASS'
Write-Host "MCP initialize/tools/list/tools/call: PASS (protocol $protocolVersion)"
Write-Host 'Recovery tools: site-profile-status, session-safe-diagnostics, site-info: PASS'
Write-Host 'The access token was intentionally not printed or persisted.'

[CmdletBinding()]
param(
    [string]$Issuer = 'https://staging.egypttourgates.com/oauth/mcp',
    [string]$Resource = 'https://staging.egypttourgates.com/wp-json/mcp/mad4b-chatgpt',
    [string]$ClientId = 'mad4b-staging-canary',
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
function Get-ProbeStatus([string]$Uri,[string]$Token) {
    try {
        $r = Invoke-WebRequest -Method Post -Uri $Uri -Headers @{Authorization="Bearer $Token";Accept='application/json, text/event-stream'} -ContentType 'application/json' -Body '{"jsonrpc":"2.0","id":1,"method":"ping"}' -TimeoutSec 15
        return [int]$r.StatusCode
    } catch {
        if ($null -eq $_.Exception.Response) { throw }
        try { return [int]$_.Exception.Response.StatusCode } catch { return [int]$_.Exception.Response.StatusCode.value__ }
    }
}

if ($SelfTest) {
    $v = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk'
    if ((Get-Challenge $v) -ne 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM') { throw 'PKCE RFC 7636 self-test failed.' }
    Write-Host 'MAD4B local OAuth canary self-test: PASS'
    exit 0
}

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
$status = Get-ProbeStatus $Resource $accessToken
$accessToken = $null; $token = $null
if ($status -in @(401,403,404,405,503) -or $status -ge 500) { throw "Authenticated MCP ingress failed with HTTP $status." }
Write-Host 'MAD4B Local OAuth Staging Canary: PASS'
Write-Host 'OAuth browser round-trip: PASS'
Write-Host 'PKCE S256: PASS'
Write-Host 'Token exchange: PASS'
Write-Host "Bearer accepted by mad4b-chatgpt: PASS (HTTP $status)"
Write-Host 'The access token was intentionally not printed or persisted.'

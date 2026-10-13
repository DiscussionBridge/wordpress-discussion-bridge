[CmdletBinding()]
param()
$ErrorActionPreference = 'Stop'
$dbRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
$dbNonce = [Guid]::NewGuid().ToString('N')
$dbA = & (Join-Path $dbRoot 'scripts/build-release.ps1') -OutputName "package-author-$dbNonce-a.zip" | ConvertFrom-Json
$dbB = & (Join-Path $dbRoot 'scripts/build-release.ps1') -OutputName "package-author-$dbNonce-b.zip" | ConvertFrom-Json
if ($dbA.sha256 -cne $dbB.sha256) { throw 'Identical source bytes did not produce identical ZIP bytes' }
$dbVerified = & (Join-Path $dbRoot 'scripts/verify-release.ps1') -Path $dbA.path | ConvertFrom-Json
if ($dbVerified.sha256 -cne $dbA.sha256 -or $dbVerified.members -ne $dbA.members -or $dbVerified.renderer_inputs -ne 3) { throw 'Package identity or renderer input inventory changed during verification' }
$dbRejected = $false
try { & (Join-Path $dbRoot 'scripts/build-release.ps1') -OutputName "package-author-$dbNonce-a.zip" | Out-Null }
catch { $dbRejected = $true }
if (-not $dbRejected) { throw 'The build overwrote an existing package' }
$dbRejected = $false
try { & (Join-Path $dbRoot 'scripts/build-release.ps1') -OutputName '../escaped.zip' | Out-Null }
catch { $dbRejected = $true }
if (-not $dbRejected) { throw 'The build accepted an escaped artifact output path' }
$dbTampered = Join-Path $dbRoot ".artifacts/package-author-$dbNonce-tampered.zip"
[IO.File]::Copy($dbA.path, $dbTampered, $false)
$dbZip = [IO.Compression.ZipFile]::Open($dbTampered, [IO.Compression.ZipArchiveMode]::Update)
try {
    $dbEntry = $dbZip.GetEntry('wordpress-discussion-bridge/src/Materializer.php')
    $dbEntry.Delete()
    $dbEntry = $dbZip.CreateEntry('wordpress-discussion-bridge/src/Materializer.php')
    $dbWriter = [IO.StreamWriter]::new($dbEntry.Open())
    try { $dbWriter.Write('tampered publication runtime') } finally { $dbWriter.Dispose() }
} finally { $dbZip.Dispose() }
$dbRejected = $false
try { & (Join-Path $dbRoot 'scripts/verify-release.ps1') -Path $dbTampered | Out-Null }
catch { $dbRejected = $true }
if (-not $dbRejected) { throw 'Package verification accepted a modified runtime member' }
[ordered]@{ checks=5; result='author-checks-passed'; package=$dbA; verified=$dbVerified;
    repeated_package=$dbB.path; retained_tampered_negative_fixture=$dbTampered } | ConvertTo-Json -Depth 6

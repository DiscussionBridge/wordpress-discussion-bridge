[CmdletBinding()]
param([string]$OutputName)
$ErrorActionPreference = 'Stop'

$dbRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
$dbManifest = Get-Content -LiteralPath (Join-Path $dbRoot 'discussionbridge-release.json') -Raw | ConvertFrom-Json
$dbVersion = [string]$dbManifest.component.version
if ($dbVersion -notmatch '^\d+\.\d+\.\d+(?:-[a-zA-Z0-9.]+)?$' -or $dbManifest.family.version -ne $dbVersion) {
    throw 'Invalid or inconsistent release manifest version'
}
$dbPlugin = Get-Content -LiteralPath (Join-Path $dbRoot 'wordpress-discussion-bridge.php') -Raw
$dbBlock = Get-Content -LiteralPath (Join-Path $dbRoot 'blocks/from-discourse/block.json') -Raw | ConvertFrom-Json
$dbAsset = Get-Content -LiteralPath (Join-Path $dbRoot 'blocks/from-discourse/index.asset.php') -Raw
if ($dbPlugin -notmatch ('(?m)^ \* Version: ' + [regex]::Escape($dbVersion) + '\r?$') -or
    $dbPlugin -notmatch ("define\('DISCUSSIONBRIDGE_WORDPRESS_VERSION', '" + [regex]::Escape($dbVersion) + "'\)") -or
    $dbBlock.version -ne $dbVersion -or
    $dbAsset -notmatch ("'version'\s*=>\s*'" + [regex]::Escape($dbVersion) + "'")) {
    throw 'Plugin, block and asset versions must agree before packaging'
}
if (-not $OutputName) { $OutputName = "wordpress-discussion-bridge-$dbVersion-candidate.zip" }
if ($OutputName -notmatch '^[a-zA-Z0-9][a-zA-Z0-9._-]*\.zip$') { throw 'OutputName must be a ZIP filename' }
$dbOutputRoot = [IO.Path]::GetFullPath((Join-Path $dbRoot '.artifacts'))
$dbOutput = [IO.Path]::GetFullPath((Join-Path $dbOutputRoot $OutputName))
if (-not $dbOutput.StartsWith($dbOutputRoot + [IO.Path]::DirectorySeparatorChar) -or
    (Test-Path -LiteralPath $dbOutput)) { throw 'Unsafe or existing output path' }
if ((Test-Path -LiteralPath $dbOutputRoot) -and
    ((Get-Item -LiteralPath $dbOutputRoot -Force).Attributes -band [IO.FileAttributes]::ReparsePoint)) {
    throw 'Linked artifact output directory is prohibited'
}

$dbPaths = [Collections.Generic.List[string]]::new()
foreach ($dbSingle in @('wordpress-discussion-bridge.php','discussionbridge-release.json','README.md','CARRY_PRUNE_MAP.md','BACKUP_RESTORE.md','SOURCE_URL_MOVES.md','LICENSE','composer.json','package.json','package-lock.json')) {
    $dbPaths.Add($dbSingle)
}
foreach ($dbDirectory in @('src','assets','blocks','resources','scripts','tests')) {
    if ((Get-Item -LiteralPath (Join-Path $dbRoot $dbDirectory) -Force).Attributes -band [IO.FileAttributes]::ReparsePoint) {
        throw 'Linked package source directory is prohibited'
    }
    foreach ($dbItem in Get-ChildItem -LiteralPath (Join-Path $dbRoot $dbDirectory) -Recurse -Force) {
        if ($dbItem.Attributes -band [IO.FileAttributes]::ReparsePoint) { throw 'Linked package members are prohibited' }
        if (-not $dbItem.PSIsContainer) { $dbPaths.Add([IO.Path]::GetRelativePath($dbRoot, $dbItem.FullName).Replace('\','/')) }
    }
}
$dbOrdered = $dbPaths.ToArray()
[Array]::Sort($dbOrdered, [StringComparer]::Ordinal)
$dbBytes = @{}
$dbLedger = foreach ($dbRelative in $dbOrdered) {
    $dbSource = [IO.Path]::GetFullPath((Join-Path $dbRoot $dbRelative))
    if (-not $dbSource.StartsWith($dbRoot + [IO.Path]::DirectorySeparatorChar)) { throw 'Package source bounds failure' }
    if ((Get-Item -LiteralPath $dbSource).Attributes -band [IO.FileAttributes]::ReparsePoint) { throw 'Linked package member' }
    $dbBody = [IO.File]::ReadAllBytes($dbSource)
    $dbBytes[$dbRelative] = $dbBody
    [ordered]@{ path=$dbRelative; bytes=$dbBody.Length; sha256=[Convert]::ToHexString([Security.Cryptography.SHA256]::HashData($dbBody)).ToLowerInvariant() }
}
$dbProvenance = [ordered]@{ format=1; component='wordpress-discussion-bridge'; version=$dbVersion; members=@($dbLedger) }
$dbUtf8 = [Text.UTF8Encoding]::new($false)
$dbBytes['SOURCE_MANIFEST.json'] = $dbUtf8.GetBytes(($dbProvenance | ConvertTo-Json -Depth 6) + "`n")
$dbOrdered = [string[]]@($dbBytes.Keys)
[Array]::Sort($dbOrdered, [StringComparer]::Ordinal)
New-Item -ItemType Directory -Path $dbOutputRoot -Force | Out-Null
$dbStream = [IO.File]::Open($dbOutput, [IO.FileMode]::CreateNew)
try {
    $dbArchive = [IO.Compression.ZipArchive]::new($dbStream, [IO.Compression.ZipArchiveMode]::Create, $true, $dbUtf8)
    try {
        foreach ($dbRelative in $dbOrdered) {
            $dbEntry = $dbArchive.CreateEntry('wordpress-discussion-bridge/' + $dbRelative, [IO.Compression.CompressionLevel]::Optimal)
            $dbEntry.LastWriteTime = [DateTimeOffset]::new(1980,1,1,0,0,0,[TimeSpan]::Zero)
            $dbEntry.ExternalAttributes = 0
            $dbEntryStream = $dbEntry.Open()
            try { $dbEntryStream.Write($dbBytes[$dbRelative],0,$dbBytes[$dbRelative].Length) }
            finally { $dbEntryStream.Dispose() }
        }
    } finally { $dbArchive.Dispose() }
} finally { $dbStream.Dispose() }
[ordered]@{ path=$dbOutput; version=$dbVersion; members=$dbOrdered.Length; sha256=(Get-FileHash -LiteralPath $dbOutput -Algorithm SHA256).Hash.ToLowerInvariant() } | ConvertTo-Json

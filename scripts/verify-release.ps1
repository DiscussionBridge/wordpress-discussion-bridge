[CmdletBinding()]
param([Parameter(Mandatory=$true)][string]$Path)
$ErrorActionPreference = 'Stop'
$dbPrefix = 'wordpress-discussion-bridge/'
$dbZip = [IO.Compression.ZipFile]::OpenRead([IO.Path]::GetFullPath($Path))
try {
    $dbMembers = @{}
    foreach ($dbEntry in $dbZip.Entries) {
        if (-not $dbEntry.FullName.StartsWith($dbPrefix, [StringComparison]::Ordinal)) { throw 'Unexpected plugin root' }
        $dbRelative = $dbEntry.FullName.Substring($dbPrefix.Length)
        if ($dbRelative -eq '' -or $dbRelative.Contains('\') -or $dbRelative.StartsWith('/') -or
            ($dbRelative.Split('/') | Where-Object { $_ -in @('', '.', '..') }) -or $dbMembers.ContainsKey($dbRelative)) {
            throw 'Unsafe or repeated package member'
        }
        $dbStream = $dbEntry.Open()
        try {
            $dbBuffer = [IO.MemoryStream]::new()
            try { $dbStream.CopyTo($dbBuffer); $dbMembers[$dbRelative] = $dbBuffer.ToArray() }
            finally { $dbBuffer.Dispose() }
        } finally { $dbStream.Dispose() }
    }
    if (-not $dbMembers.ContainsKey('SOURCE_MANIFEST.json')) { throw 'Missing source manifest' }
    $dbManifest = [Text.Encoding]::UTF8.GetString($dbMembers['SOURCE_MANIFEST.json']) | ConvertFrom-Json
    if ($dbManifest.format -ne 1 -or $dbManifest.component -ne 'wordpress-discussion-bridge') { throw 'Invalid source manifest' }
    $dbSeen = @{}
    foreach ($dbMember in $dbManifest.members) {
        $dbName = [string]$dbMember.path
        if ($dbSeen.ContainsKey($dbName) -or $dbName -eq 'SOURCE_MANIFEST.json' -or -not $dbMembers.ContainsKey($dbName)) { throw 'Missing or repeated manifest member' }
        $dbSeen[$dbName] = $true
        $dbBytes = $dbMembers[$dbName]
        $dbDigest = [Convert]::ToHexString([Security.Cryptography.SHA256]::HashData($dbBytes)).ToLowerInvariant()
        if ($dbBytes.Length -ne $dbMember.bytes -or $dbDigest -cne $dbMember.sha256) { throw "Package digest mismatch: $dbName" }
    }
    if ($dbSeen.Count + 1 -ne $dbMembers.Count) { throw 'Unlisted package member' }
    foreach ($dbRequired in @('wordpress-discussion-bridge.php', 'discussionbridge-release.json',
        'composer.json', 'package.json', 'package-lock.json', 'resources/js/browser-rich-content.mjs',
        'scripts/build-browser-rich-content.mjs', 'scripts/build-release.ps1', 'scripts/verify-release.ps1',
        'tests/run.php', 'tests/bootstrap.php', 'assets/discussionbridge-rich-content.js', 'assets/browser-rich-content-provenance.json')) {
        if (-not $dbSeen.ContainsKey($dbRequired)) { throw "Required maintained source/build member is absent: $dbRequired" }
    }
    foreach ($dbName in $dbMembers.Keys) {
        if ($dbName -match '(^|/)(\.git|\.tmp|\.artifacts|node_modules|vendor|demo)(/|$)') { throw 'Local/dependency/demo state entered the package' }
    }
    $dbProvenance = [Text.Encoding]::UTF8.GetString($dbMembers['assets/browser-rich-content-provenance.json']) | ConvertFrom-Json
    foreach ($dbInput in $dbProvenance.inputs.PSObject.Properties) {
        $dbBytes = $dbMembers[$dbInput.Name]
        if ($null -eq $dbBytes -or $dbBytes.Length -ne $dbInput.Value.bytes -or
            [Convert]::ToHexString([Security.Cryptography.SHA256]::HashData($dbBytes)).ToLowerInvariant() -cne $dbInput.Value.sha256) {
            throw "Renderer build input mismatch: $($dbInput.Name)"
        }
    }
    foreach ($dbOutput in $dbProvenance.outputs.PSObject.Properties) {
        $dbBytes = $dbMembers['assets/' + $dbOutput.Name]
        if ($null -eq $dbBytes -or $dbBytes.Length -ne $dbOutput.Value.bytes -or
            [Convert]::ToHexString([Security.Cryptography.SHA256]::HashData($dbBytes)).ToLowerInvariant() -cne $dbOutput.Value.sha256) {
            throw "Renderer output mismatch: $($dbOutput.Name)"
        }
    }
    foreach ($dbNotice in $dbProvenance.bundledPackages) {
        $dbBytes = $dbMembers['assets/' + $dbNotice.file]
        if ($null -eq $dbBytes -or $dbBytes.Length -ne $dbNotice.bytes -or
            [Convert]::ToHexString([Security.Cryptography.SHA256]::HashData($dbBytes)).ToLowerInvariant() -cne $dbNotice.sha256) {
            throw "Renderer notice mismatch: $($dbNotice.file)"
        }
    }
    [ordered]@{ path=[IO.Path]::GetFullPath($Path); version=$dbManifest.version; members=$dbMembers.Count;
        renderer_inputs=@($dbProvenance.inputs.PSObject.Properties).Count; notices=@($dbProvenance.bundledPackages).Count;
        sha256=(Get-FileHash -LiteralPath $Path -Algorithm SHA256).Hash.ToLowerInvariant() } | ConvertTo-Json
} finally { $dbZip.Dispose() }

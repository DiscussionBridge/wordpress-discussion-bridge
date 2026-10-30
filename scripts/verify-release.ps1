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
    foreach ($dbRequired in @('wordpress-discussion-bridge.php', 'discussionbridge-release.json', 'BACKUP_RESTORE.md', 'SOURCE_URL_MOVES.md',
        'composer.json', 'package.json', 'package-lock.json', 'resources/js/browser-rich-content.mjs',
        'scripts/build-browser-rich-content.mjs', 'scripts/bundled-license.mjs', 'scripts/managed-output.mjs', 'scripts/build-release.ps1', 'scripts/verify-release.ps1',
        'tests/run.php', 'tests/bootstrap.php', 'tests/integration.php', 'tests/integration-bootstrap.php',
        'src/CmsArtifacts.php', 'src/CmsCoordinator.php', 'src/CmsSourcePublisher.php', 'src/NativeWriter.php',
        'src/CmsSourceMove.php', 'src/CmsSourceOwnership.php', 'src/CmsSourceNativeMove.php', 'src/CmsSourceArticleProfile.php', 'src/CmsSourcePublicVerifier.php',
        'tests/source-move-loopback.php', 'tests/fixtures/source-loopback-server.php', 'tests/fixtures/source-article-theme/single.php',
        'src/NativeDeletion.php', 'src/private/cms-v4/CmsCodec.php', 'src/private/cms-v4/cms-schema.json',
        'src/private/cms-v4/PROVENANCE.json', 'src/private/cms-v4/idna/DEPENDENCIES.json',
        'scripts/refresh-contract.mjs', 'tests/contract-runtime.php',
        'tests/fixtures/cms-v4/url-domain-policy.json', 'tests/fixtures/cms-v4/url-contextj.json',
        'tests/fixtures/cms-v4/positive-examples.json',
        'assets/discussionbridge-rich-content.js', 'assets/browser-rich-content-provenance.json')) {
        if (-not $dbSeen.ContainsKey($dbRequired)) { throw "Required maintained source/build member is absent: $dbRequired" }
    }
    foreach ($dbName in $dbMembers.Keys) {
        if ($dbName -match '(^|/)(\.git|\.tmp|\.artifacts|node_modules|demo)(/|$)') { throw 'Local/dependency/demo state entered the package' }
        if ($dbName -match '(^|/)vendor(/|$)' -and -not $dbName.StartsWith('src/private/cms-v4/idna/vendor/', [StringComparison]::Ordinal)) {
            throw 'Uncontrolled dependency state entered the package'
        }
    }
    $dbPrivate = [Text.Encoding]::UTF8.GetString($dbMembers['src/private/cms-v4/PROVENANCE.json']) | ConvertFrom-Json
    if ($dbPrivate.wire_version -ne 'v4' -or $dbPrivate.implementation_version -ne '0.4.0-alpha.1' -or $null -ne $dbPrivate.release_version) { throw 'Current development Contract tuple mismatch' }
    $dbRelease = [Text.Encoding]::UTF8.GetString($dbMembers['discussionbridge-release.json']) | ConvertFrom-Json
    if ($dbPrivate.candidate_id -cne 'discussionbridge-v4-core-20261029-r7' -or
        $dbPrivate.source.commit -cne '09d77c0209835c2d996aad294d161f167c7bd838' -or
        $dbPrivate.normative_sha256 -cne '808042688cbde88fac0acdf1187e4cb8c251e13025bfcf09cf3a3c361ca02003' -or
        $dbRelease.contract.normative_sha256 -cne $dbPrivate.normative_sha256 -or
        $dbPrivate.dependency_manifest_sha256 -cne '478d6e5c8f96255f7124e5bd0d28f79975d4b8466fea097ef2edb2fb1151c282') {
        throw 'Package is not paired to the frozen reviewed shared source'
    }
    $dbSchemaPin = @($dbPrivate.members | Where-Object { $_.path -ceq 'cms-schema.json' })
    $dbPhpPin = @($dbPrivate.members | Where-Object { $_.path -ceq 'CmsCodec.php' })
    if ($dbSchemaPin.Count -ne 1 -or $dbPhpPin.Count -ne 1 -or
        $dbSchemaPin[0].sha256 -cne 'e6a15a6d70f7bc8b8ee82aa22a0b9c2ff1bbf2da6277f7087118be9e026ce5c0' -or
        $dbPhpPin[0].sha256 -cne '502df4f0324ac9b65c0068f1025d71290d149522e98a29382763816f01a20555' -or
        $dbRelease.candidate_id -cne $dbPrivate.candidate_id -or
        $dbRelease.contract.schema_sha256 -cne $dbSchemaPin[0].sha256 -or $dbRelease.contract.php_sha256 -cne $dbPhpPin[0].sha256) {
        throw 'Release metadata and private Contract pins disagree'
    }
    foreach ($dbMember in $dbPrivate.members) {
        $dbBytes = $dbMembers['src/private/cms-v4/' + $dbMember.path]
        if ($null -eq $dbBytes -or $dbBytes.Length -ne $dbMember.bytes -or [Convert]::ToHexString([Security.Cryptography.SHA256]::HashData($dbBytes)).ToLowerInvariant() -cne $dbMember.sha256) { throw 'Private Contract pin mismatch' }
    }
    $dbIdnaBytes = $dbMembers['src/private/cms-v4/idna/DEPENDENCIES.json']
    $dbIdnaHash = [Convert]::ToHexString([Security.Cryptography.SHA256]::HashData($dbIdnaBytes)).ToLowerInvariant()
    if ($dbIdnaHash -cne $dbPrivate.dependency_manifest_sha256 -or $dbIdnaHash -cne $dbRelease.contract.php_dependencies_sha256) {
        throw 'Private dependency identity mismatch'
    }
    $dbIdna = [Text.Encoding]::UTF8.GetString($dbIdnaBytes) | ConvertFrom-Json -Depth 100
    if ($dbIdna.php_minimum -ne '8.1' -or @($dbIdna.additional_mandatory_extensions).Count -ne 0) { throw 'Private dependency hosting requirements changed' }
    $dbExpectedPrivate = @{}
    foreach ($dbMember in $dbPrivate.members) {
        if ($dbExpectedPrivate.ContainsKey([string]$dbMember.path)) { throw 'Duplicate private member' }
        $dbExpectedPrivate[[string]$dbMember.path] = $true
    }
    foreach ($dbName in $dbMembers.Keys) {
        if ($dbName.StartsWith('src/private/cms-v4/', [StringComparison]::Ordinal)) {
            $dbRelative = $dbName.Substring('src/private/cms-v4/'.Length)
            if ($dbRelative -cne 'PROVENANCE.json' -and -not $dbExpectedPrivate.ContainsKey($dbRelative)) { throw 'Unlisted private runtime member' }
        }
    }
    foreach ($dbMember in $dbIdna.members) {
        $dbPrivatePath = 'idna/' + [string]$dbMember.path
        if (-not $dbExpectedPrivate.ContainsKey($dbPrivatePath)) { throw 'Dependency omitted from private ledger' }
        $dbBytes = $dbMembers['src/private/cms-v4/' + $dbPrivatePath]
        if ($null -eq $dbBytes -or $dbBytes.Length -ne $dbMember.bytes -or [Convert]::ToHexString([Security.Cryptography.SHA256]::HashData($dbBytes)).ToLowerInvariant() -cne $dbMember.sha256) {
            throw 'Bundled private dependency/license mismatch'
        }
    }
    $dbFixturePins = @{
        'tests/fixtures/cms-v4/positive-examples.json' = 'b3ea942e9fe6b824ab2a0af5f4eecf46b672d544856fea53929d60a359225b30'
        'tests/fixtures/cms-v4/url-domain-policy.json' = 'e6fa88694fe1142f8754c2e50cbee752a9755d63324247aaa9db465450d5672e'
        'tests/fixtures/cms-v4/url-contextj.json' = '5cf9aee605cd002a75d929da35fe0f6bcb99b2dd83a78a71751dc24b95adadd8'
    }
    $dbFixtureSeen = @{}
    foreach ($dbMember in $dbPrivate.fixtures) {
        if (-not $dbFixturePins.ContainsKey([string]$dbMember.path) -or $dbFixtureSeen.ContainsKey([string]$dbMember.path) -or
            $dbFixturePins[[string]$dbMember.path] -cne $dbMember.sha256) { throw 'Wrong or repeated frozen fixture' }
        $dbFixtureSeen[[string]$dbMember.path] = $true
        $dbBytes = $dbMembers[[string]$dbMember.path]
        if ($null -eq $dbBytes -or $dbBytes.Length -ne $dbMember.bytes -or [Convert]::ToHexString([Security.Cryptography.SHA256]::HashData($dbBytes)).ToLowerInvariant() -cne $dbMember.sha256) {
            throw 'Shared contract fixture identity mismatch'
        }
    }
    if ($dbFixtureSeen.Count -ne $dbFixturePins.Count) { throw 'Incomplete frozen fixture set' }
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
        if ($dbNotice.licenseSource.extraction -notin @('whole-file','pinned-license-section') -or $dbNotice.licenseSource.sha256 -notmatch '^[a-f0-9]{64}$') { throw 'Renderer notice lacks actual source provenance' }
    }
    $dbFastDom = @($dbProvenance.bundledPackages | Where-Object { $_.name -ceq 'fastdom' })
    if ($dbFastDom.Count -ne 1 -or $dbFastDom[0].version -cne '1.0.12' -or $dbFastDom[0].sha256 -cne 'df2ae14f12f31864acef768195afe4584b08a257f25925f49884619279f02f37' -or $dbFastDom[0].licenseSource.sha256 -cne 'cc5669ab6f7741bb061c8a7244addc8e3af1f84ec7d2445744b59d0169a87d11') { throw 'Missing/incomplete actual FastDom notice' }
    [ordered]@{ path=[IO.Path]::GetFullPath($Path); version=$dbManifest.version; members=$dbMembers.Count;
        renderer_inputs=@($dbProvenance.inputs.PSObject.Properties).Count; notices=@($dbProvenance.bundledPackages).Count;
        sha256=(Get-FileHash -LiteralPath $Path -Algorithm SHA256).Hash.ToLowerInvariant() } | ConvertTo-Json
} finally { $dbZip.Dispose() }

[CmdletBinding()]
param([Parameter(Mandatory=$true)][string]$Path, [string]$PhpBinary = 'php')
$ErrorActionPreference = 'Stop'
& (Join-Path $PSScriptRoot 'verify-release.ps1') -Path $Path
$dbTempRoot = [IO.Path]::GetFullPath([IO.Path]::GetTempPath())
$dbTemp = Join-Path $dbTempRoot ('discussionbridge-wp-package-' + [Guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $dbTemp | Out-Null
try {
    [IO.Compression.ZipFile]::ExtractToDirectory([IO.Path]::GetFullPath($Path), $dbTemp)
    $dbFixture = Join-Path $dbTemp 'wordpress-discussion-bridge/tests/contract-runtime.php'
    & $PhpBinary -n $dbFixture
    if ($LASTEXITCODE -ne 0) { throw 'The normal extracted WordPress package failed its actual no-optional-extension codec checks' }
} finally {
    $dbResolved = (Get-Item -LiteralPath $dbTemp -Force).FullName
    if ([IO.Path]::GetDirectoryName($dbResolved) -ne $dbTempRoot.TrimEnd([IO.Path]::DirectorySeparatorChar) -or
        -not [IO.Path]::GetFileName($dbResolved).StartsWith('discussionbridge-wp-package-', [StringComparison]::Ordinal) -or
        ((Get-Item -LiteralPath $dbResolved -Force).Attributes -band [IO.FileAttributes]::ReparsePoint)) {
        throw 'Preserving an unexpected package-test target; cleanup refused'
    }
    Remove-Item -LiteralPath $dbResolved -Recurse -Force
}

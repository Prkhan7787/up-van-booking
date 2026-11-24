$ErrorActionPreference='Stop'
if($PSVersionTable.PSVersion.Major -ne 5 -or $PSVersionTable.PSVersion.Minor -ne 1){throw 'Test must run under Windows PowerShell 5.1'}

$projectRoot='C:\จองรถตู้\up-van-booking'
$allowed=Join-Path $projectRoot '.runtime\php84\php.exe'
$malformed='C:\จองรถตู้\up-van-booking.runtime\php84\php.exe'
$relative='.runtime\php84\php.exe'
$expectedResolved=(Resolve-Path -LiteralPath $allowed -ErrorAction Stop).ProviderPath
$actualResolved=(Resolve-Path -LiteralPath $allowed -ErrorAction Stop).ProviderPath
$canonicalExpected=[IO.Path]::GetFullPath($expectedResolved)
$canonicalActual=[IO.Path]::GetFullPath($actualResolved)

if(!(Test-Path -LiteralPath $allowed -PathType Leaf)){throw 'Approved Thai runtime path is missing'}
if(![IO.Path]::IsPathRooted($allowed)){throw 'Approved runtime path must be absolute'}
if(![string]::Equals($canonicalActual,$canonicalExpected,[StringComparison]::OrdinalIgnoreCase)){throw 'Approved canonical runtime path was rejected'}
if(Test-Path -LiteralPath $malformed -PathType Leaf){throw 'Malformed near-match path unexpectedly exists'}
if([string]::Equals([IO.Path]::GetFullPath($malformed),$canonicalExpected,[StringComparison]::OrdinalIgnoreCase)){throw 'Malformed near-match path passed allowlist comparison'}
if([IO.Path]::IsPathRooted($relative)){throw 'Relative runtime path unexpectedly passed absolute-path requirement'}

$caseVariant=$allowed.Replace('up-van-booking','UP-VAN-BOOKING')
$caseCanonical=[IO.Path]::GetFullPath((Resolve-Path -LiteralPath $caseVariant -ErrorAction Stop).ProviderPath)
if(![string]::Equals($caseCanonical,$canonicalExpected,[StringComparison]::OrdinalIgnoreCase)){throw 'Windows case-insensitive path comparison failed'}

Write-Output 'PASS: Windows PowerShell 5.1 accepts canonical Thai project runtime and rejects malformed/relative paths.'

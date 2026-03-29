param(
  [string]$AvdName = "Medium_Phone_API_36.1",
  [string]$AppUrl = "",
  [switch]$SkipAndroidStudio,
  [switch]$NoCleanInstall
)

$ErrorActionPreference = "Stop"

function Write-Info([string]$Message) {
  Write-Host "[android-pwa-full] $Message"
}

$scriptDir = $PSScriptRoot
if ([string]::IsNullOrWhiteSpace($scriptDir)) {
  $scriptDir = Split-Path -Parent $PSCommandPath
}

$repoRoot = (Resolve-Path (Join-Path $scriptDir "..")).Path
$launchScript = Join-Path $scriptDir "android_launch_egg1_3.ps1"
$pwaTestScript = Join-Path $scriptDir "pwa_emulator_test.mjs"

if (-not (Test-Path $launchScript)) {
  throw "Launch script not found: $launchScript"
}
if (-not (Test-Path $pwaTestScript)) {
  throw "PWA test script not found: $pwaTestScript"
}

Write-Info "Launching Android Studio/emulator and opening Egg1.3 URL..."
$launchParams = @{
  AvdName = $AvdName
}
if (-not [string]::IsNullOrWhiteSpace($AppUrl)) {
  $launchParams["AppUrl"] = $AppUrl
}
if ($SkipAndroidStudio) {
  $launchParams["SkipAndroidStudio"] = $true
}

& $launchScript @launchParams

Push-Location $repoRoot
try {
  $nodeArgs = @("scripts/pwa_emulator_test.mjs", "--avd", $AvdName)
  if (-not [string]::IsNullOrWhiteSpace($AppUrl)) {
    $nodeArgs += @("--base-url", $AppUrl)
  }
  if ($NoCleanInstall) {
    $nodeArgs += "--no-clean-install"
  }

  Write-Info "Running full PWA flow: install prompt + standalone launch + update..."
  & node @nodeArgs
  if ($LASTEXITCODE -ne 0) {
    throw "PWA emulator test failed with exit code $LASTEXITCODE."
  }
} finally {
  Pop-Location
}

param(
  [string]$AvdName = "Medium_Phone_API_36.1",
  [string]$AppUrl = "",
  [switch]$SkipAndroidStudio
)

$ErrorActionPreference = "Stop"

function Write-Info([string]$Message) {
  Write-Host "[android-launch] $Message"
}

function Get-RepoRoot {
  $scriptDir = $PSScriptRoot
  if ([string]::IsNullOrWhiteSpace($scriptDir)) {
    $scriptDir = Split-Path -Parent $PSCommandPath
  }
  return (Resolve-Path (Join-Path $scriptDir "..")).Path
}

function Get-EnvValue([string]$EnvPath, [string]$Key) {
  if (-not (Test-Path $EnvPath)) {
    return ""
  }

  $line = Get-Content $EnvPath | Where-Object { $_ -match "^\s*$Key\s*=" } | Select-Object -First 1
  if (-not $line) {
    return ""
  }

  $value = $line -replace "^\s*$Key\s*=\s*", ""
  return $value.Trim()
}

function Get-DefaultPort([Uri]$Uri) {
  if (-not $Uri.IsDefaultPort) {
    return [int]$Uri.Port
  }

  if ($Uri.Scheme -eq "https") {
    return 443
  }

  return 80
}

function Convert-ToEmulatorTarget([string]$RawUrl) {
  if ([string]::IsNullOrWhiteSpace($RawUrl)) {
    throw "App URL is empty."
  }

  $uri = [Uri]$RawUrl
  $builder = New-Object System.UriBuilder($uri)
  $hostPort = Get-DefaultPort -Uri $uri
  $devicePort = $hostPort
  $needsReverse = $false

  if ($builder.Host -eq "localhost" -or $builder.Host -eq "127.0.0.1") {
    $needsReverse = $true
    if ($devicePort -lt 1024) {
      if ($env:EMULATOR_LOCAL_PORT -and $env:EMULATOR_LOCAL_PORT -match "^\d+$") {
        $devicePort = [int]$env:EMULATOR_LOCAL_PORT
      } else {
        $devicePort = 8765
      }
    }

    $builder.Host = "localhost"
    $builder.Port = $devicePort
  } elseif ($builder.Host -eq "0.0.0.0") {
    $builder.Host = "10.0.2.2"
  }

  return [PSCustomObject]@{
    BaseUrl = $builder.Uri.AbsoluteUri.TrimEnd("/")
    NeedsReverse = $needsReverse
    HostPort = $hostPort
    DevicePort = $devicePort
  }
}

function Resolve-AndroidSdkRoot {
  if ($env:ANDROID_SDK_ROOT -and (Test-Path $env:ANDROID_SDK_ROOT)) {
    return $env:ANDROID_SDK_ROOT
  }

  if ($env:ANDROID_HOME -and (Test-Path $env:ANDROID_HOME)) {
    return $env:ANDROID_HOME
  }

  $defaultPath = Join-Path $env:LOCALAPPDATA "Android\Sdk"
  if (Test-Path $defaultPath) {
    return $defaultPath
  }

  throw "Android SDK path was not found. Set ANDROID_SDK_ROOT or ANDROID_HOME."
}

function Resolve-AndroidStudioPath {
  $candidates = New-Object System.Collections.Generic.List[string]
  if ($env:ANDROID_STUDIO_PATH) {
    $candidates.Add($env:ANDROID_STUDIO_PATH)
    $candidates.Add((Join-Path $env:ANDROID_STUDIO_PATH "bin\studio64.exe"))
  }

  $regKeys = @(
    "HKLM:\Software\Microsoft\Windows\CurrentVersion\Uninstall\*",
    "HKLM:\Software\WOW6432Node\Microsoft\Windows\CurrentVersion\Uninstall\*",
    "HKCU:\Software\Microsoft\Windows\CurrentVersion\Uninstall\*"
  )

  $installLocations = Get-ItemProperty $regKeys -ErrorAction SilentlyContinue |
    Where-Object { $_.DisplayName -like "*Android Studio*" } |
    Select-Object -ExpandProperty InstallLocation -ErrorAction SilentlyContinue

  foreach ($location in $installLocations) {
    if ([string]::IsNullOrWhiteSpace($location)) {
      continue
    }
    $candidates.Add((Join-Path $location "bin\studio64.exe"))
  }

  $candidates.Add("C:\Program Files\Android\Android Studio\bin\studio64.exe")
  $candidates.Add("C:\Program Files(x86)\Android\Android Studio\bin\studio64.exe")
  $candidates.Add((Join-Path $env:LOCALAPPDATA "Programs\Android Studio\bin\studio64.exe"))

  foreach ($candidate in $candidates) {
    if (Test-Path $candidate) {
      return $candidate
    }
  }

  return ""
}

function Get-AdbDevices([string]$AdbPath) {
  $raw = & $AdbPath devices
  $devices = New-Object System.Collections.Generic.List[object]

  foreach ($line in $raw) {
    if ($line -match "^(emulator-\d+)\s+(\S+)$") {
      $devices.Add([PSCustomObject]@{
        Serial = $Matches[1]
        State = $Matches[2]
      })
    }
  }

  return $devices.ToArray()
}

function Restart-AdbServer([string]$AdbPath) {
  Write-Info "Restarting adb server..."
  & $AdbPath kill-server | Out-Null
  Start-Sleep -Seconds 1
  & $AdbPath start-server | Out-Null
  Start-Sleep -Seconds 2
}

function Wait-ForDeviceState([string]$AdbPath, [int]$TimeoutSeconds, [string]$DesiredState = "device") {
  $deadline = (Get-Date).AddSeconds($TimeoutSeconds)
  while ((Get-Date) -lt $deadline) {
    $device = Get-AdbDevices -AdbPath $AdbPath | Where-Object { $_.State -eq $DesiredState } | Select-Object -First 1
    if ($device) {
      return $device.Serial
    }
    Start-Sleep -Seconds 1
  }

  return ""
}

function Stop-StaleEmulators([string]$AdbPath, [object[]]$Devices) {
  foreach ($device in @($Devices)) {
    if ($device.State -eq "device") {
      continue
    }

    Write-Info "Stopping stale emulator $($device.Serial) ($($device.State))..."
    try {
      & $AdbPath -s $device.Serial emu kill | Out-Null
    } catch {
      # Ignore and continue to process-kill fallback.
    }
  }

  Start-Sleep -Seconds 2
  Get-Process emulator, qemu-system-x86_64 -ErrorAction SilentlyContinue | Stop-Process -Force -ErrorAction SilentlyContinue
}

function Wait-ForBoot([string]$AdbPath, [string]$Serial) {
  & $AdbPath -s $Serial wait-for-device | Out-Null

  $deadline = (Get-Date).AddMinutes(3)
  while ((Get-Date) -lt $deadline) {
    $boot = (& $AdbPath -s $Serial shell getprop sys.boot_completed 2>$null).Trim()
    if ($boot -eq "1") {
      return
    }
    Start-Sleep -Seconds 1
  }

  throw "Timed out waiting for emulator boot completion."
}

function Start-EmulatorIfNeeded([string]$AdbPath, [string]$EmulatorPath, [string]$RequestedAvd) {
  $readySerial = Wait-ForDeviceState -AdbPath $AdbPath -TimeoutSeconds 5 -DesiredState "device"
  if ($readySerial) {
    return $readySerial
  }

  $devices = Get-AdbDevices -AdbPath $AdbPath
  $staleDevices = @($devices | Where-Object { $_.State -ne "device" })
  if ($staleDevices.Count -gt 0) {
    $stateSummary = ($staleDevices | ForEach-Object { "$($_.Serial):$($_.State)" }) -join ", "
    Write-Info "Detected stale emulator state(s): $stateSummary"
    Restart-AdbServer -AdbPath $AdbPath

    $readySerial = Wait-ForDeviceState -AdbPath $AdbPath -TimeoutSeconds 20 -DesiredState "device"
    if ($readySerial) {
      return $readySerial
    }

    Stop-StaleEmulators -AdbPath $AdbPath -Devices $staleDevices
  }

  $availableAvds = (& $EmulatorPath -list-avds).Trim().Split([Environment]::NewLine) | Where-Object { $_.Trim() -ne "" }
  if ($availableAvds -notcontains $RequestedAvd) {
    throw "Requested AVD '$RequestedAvd' was not found. Available: $($availableAvds -join ', ')"
  }

  Write-Info "Starting emulator: $RequestedAvd"
  Start-Process -FilePath $EmulatorPath -ArgumentList @("-avd", $RequestedAvd, "-netdelay", "none", "-netspeed", "full") -WindowStyle Normal | Out-Null

  $deadline = (Get-Date).AddMinutes(5)
  while ((Get-Date) -lt $deadline) {
    Start-Sleep -Seconds 1
    $devices = Get-AdbDevices -AdbPath $AdbPath
    $readyDevice = $devices | Where-Object { $_.State -eq "device" } | Select-Object -First 1
    if ($readyDevice) {
      return $readyDevice.Serial
    }
  }

  $states = (Get-AdbDevices -AdbPath $AdbPath | ForEach-Object { "$($_.Serial):$($_.State)" }) -join ", "
  throw "No emulator device became available. Last adb state(s): $states"
}

function Open-ChromeUrl([string]$AdbPath, [string]$Serial, [string]$Url) {
  & $AdbPath -s $Serial shell am start -a android.intent.action.VIEW -n "com.android.chrome/com.google.android.apps.chrome.Main" -d $Url | Out-Null
}

function Ensure-AdbReverse([string]$AdbPath, [string]$Serial, [int]$DevicePort, [int]$HostPort) {
  $deviceBinding = "tcp:$DevicePort"
  $hostBinding = "tcp:$HostPort"

  try {
    & $AdbPath -s $Serial reverse --remove $deviceBinding 2>$null | Out-Null
  } catch {
    # Ignore when no prior reverse mapping exists.
  }
  & $AdbPath -s $Serial reverse $deviceBinding $hostBinding | Out-Null
}

$repoRoot = Get-RepoRoot
$envPath = Join-Path $repoRoot ".env"

if ([string]::IsNullOrWhiteSpace($AppUrl)) {
  $AppUrl = Get-EnvValue -EnvPath $envPath -Key "APP_URL"
}

if ([string]::IsNullOrWhiteSpace($AppUrl)) {
  $AppUrl = "http://localhost/sumacot/egg1.3"
}

$emulatorTarget = Convert-ToEmulatorTarget -RawUrl $AppUrl
$targetUrl = "$($emulatorTarget.BaseUrl)/login"

$sdkRoot = Resolve-AndroidSdkRoot
$adbPath = Join-Path $sdkRoot "platform-tools\adb.exe"
$emulatorPath = Join-Path $sdkRoot "emulator\emulator.exe"

if (-not (Test-Path $adbPath)) {
  throw "adb.exe not found at: $adbPath"
}
if (-not (Test-Path $emulatorPath)) {
  throw "emulator.exe not found at: $emulatorPath"
}

if (-not $SkipAndroidStudio) {
  $studioPath = Resolve-AndroidStudioPath
  if ($studioPath) {
    $existingStudio = Get-Process -Name "studio64" -ErrorAction SilentlyContinue
    if (-not $existingStudio) {
      Write-Info "Starting Android Studio..."
      Start-Process -FilePath $studioPath | Out-Null
      Start-Sleep -Seconds 2
    } else {
      Write-Info "Android Studio is already running."
    }
  } else {
    Write-Info "Android Studio executable not found from registry/common paths. Continuing with emulator launch."
  }
}

$serial = Start-EmulatorIfNeeded -AdbPath $adbPath -EmulatorPath $emulatorPath -RequestedAvd $AvdName
Write-Info "Using device: $serial"
Wait-ForBoot -AdbPath $adbPath -Serial $serial

if ($emulatorTarget.NeedsReverse) {
  Write-Info "Configuring adb reverse tcp:$($emulatorTarget.DevicePort) -> tcp:$($emulatorTarget.HostPort)"
  Ensure-AdbReverse -AdbPath $adbPath -Serial $serial -DevicePort $emulatorTarget.DevicePort -HostPort $emulatorTarget.HostPort
}

Write-Info "Opening Egg1.3 URL in Chrome: $targetUrl"
Open-ChromeUrl -AdbPath $adbPath -Serial $serial -Url $targetUrl

Write-Host ""
Write-Host "Ready:"
Write-Host "  Studio   : $(-not $SkipAndroidStudio)"
Write-Host "  Device   : $serial"
Write-Host "  AVD      : $AvdName"
Write-Host "  URL      : $targetUrl"
if ($emulatorTarget.NeedsReverse) {
  Write-Host "  Reverse  : tcp:$($emulatorTarget.DevicePort) -> tcp:$($emulatorTarget.HostPort)"
}

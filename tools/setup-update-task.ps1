#Requires -Version 5.1
# Runs tools\update-local.ps1 (pull-only) when you sign in to Windows and every 30 minutes.
#   powershell -ExecutionPolicy Bypass -File tools\setup-update-task.ps1          (turn on)
#   powershell -ExecutionPolicy Bypass -File tools\setup-update-task.ps1 -Remove  (turn off)
param([switch]$Remove)

$ErrorActionPreference = 'Stop'
$RepoRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$TaskName = 'SMS2 Local Update'

if ($Remove) {
    Unregister-ScheduledTask -TaskName $TaskName -Confirm:$false -ErrorAction SilentlyContinue
    Write-Host "Removed scheduled task: $TaskName"
    return
}

$script = Join-Path $RepoRoot 'tools\update-local.ps1'
$action = New-ScheduledTaskAction -Execute 'powershell.exe' `
    -Argument "-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File `"$script`" -Quiet" `
    -WorkingDirectory $RepoRoot
$atLogon = New-ScheduledTaskTrigger -AtLogOn -User $env:USERNAME
$repeat = New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(1) -RepetitionInterval (New-TimeSpan -Minutes 30) -RepetitionDuration (New-TimeSpan -Days 365)
$settings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -StartWhenAvailable

Register-ScheduledTask -TaskName $TaskName -Action $action -Trigger @($atLogon, $repeat) -Settings $settings `
    -Description 'Pull-only update of the local SMS2 copy from GitHub (never pushes).' -Force | Out-Null
Write-Host "Scheduled task '$TaskName' is on. It runs at sign-in and every 30 minutes."

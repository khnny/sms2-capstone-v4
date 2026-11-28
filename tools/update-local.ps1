#Requires -Version 5.1
<#
  Pull-only update for a local SMS2 copy (PC / laptop with XAMPP).

  - Gets the latest `main` from GitHub. Never commits or pushes.
  - Skips the update if you have uncommitted changes, so nothing is overwritten.
  - New SQL files in database/patches are applied to your local database,
    after a mysqldump backup into storage/backups/local-updates.
  - Also updates the student portal clone next to this folder, if there is one.

  Usage:
    powershell -ExecutionPolicy Bypass -File tools\update-local.ps1
    powershell -ExecutionPolicy Bypass -File tools\update-local.ps1 -Quiet   (for the scheduled task)
#>
param(
    [string]$PortalPath = '',
    [switch]$Quiet
)

# 'Continue': git and mysqldump write progress to stderr, which PowerShell 5.1
# would otherwise turn into terminating errors. Exit codes are checked instead.
$ErrorActionPreference = 'Continue'
$RepoRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$LogFile = Join-Path $env:LOCALAPPDATA 'sms2-update.log'

function Say([string]$Text) {
    $line = '[' + (Get-Date -Format 'yyyy-MM-dd HH:mm') + '] ' + $Text
    Add-Content -Path $LogFile -Value $line
    if (-not $Quiet) { Write-Host $Text }
}

function Find-Exe([string]$Name, [string[]]$Candidates) {
    $cmd = Get-Command $Name -ErrorAction SilentlyContinue
    if ($cmd) { return $cmd.Source }
    foreach ($c in $Candidates) { if (Test-Path $c) { return $c } }
    return $null
}

function Update-Repo([string]$Path, [string]$Label) {
    Push-Location $Path
    try {
        $branch = (git rev-parse --abbrev-ref HEAD).Trim()
        if ($branch -ne 'main') {
            Say "${Label}: on branch '$branch', not main. Skipped."
            return $null
        }
        $dirty = git status --porcelain --untracked-files=no
        if ($dirty) {
            Say "${Label}: you have unsaved code changes, so the update was skipped to protect them:"
            $dirty | ForEach-Object { Say "   $_" }
            return $null
        }
        $old = (git rev-parse HEAD).Trim()
        git fetch --quiet origin main
        if ($LASTEXITCODE -ne 0) { Say "${Label}: could not reach GitHub. Skipped."; return $null }
        git merge --ff-only --quiet origin/main 2>&1 | Out-Null
        if ($LASTEXITCODE -ne 0) {
            Say "${Label}: could not update cleanly (a new file on GitHub collides with a local file). Run 'git pull --ff-only origin main' to see which."
            return $null
        }
        $new = (git rev-parse HEAD).Trim()
        if ($old -eq $new) { Say "${Label}: already up to date."; return @{ Old = $old; New = $new } }
        $count = (git rev-list --count "$old..$new").Trim()
        Say "${Label}: updated ($count new commit(s))."
        return @{ Old = $old; New = $new }
    } finally {
        Pop-Location
    }
}

Say '--- SMS2 local update ---'
$result = Update-Repo $RepoRoot 'SMS2'

# New database patches since the last update
if ($result -and $result.Old -ne $result.New) {
    Push-Location $RepoRoot
    $patches = git diff --name-only --diff-filter=A $result.Old $result.New -- 'database/patches/*.sql'
    Pop-Location
    if ($patches) {
        $php = Find-Exe 'php' @('A:\xampp_new\php\php.exe', 'C:\xampp\php\php.exe', 'D:\xampp\php\php.exe')
        if (-not $php) {
            Say 'New database patches found, but php.exe was not found. Apply these by hand:'
            $patches | ForEach-Object { Say "   $_" }
        } else {
            $mysqldump = Join-Path (Split-Path (Split-Path $php)) 'mysql\bin\mysqldump.exe'
            $db = & $php (Join-Path $RepoRoot 'tools\local-db.php') --dsn | ConvertFrom-Json
            $backupDir = Join-Path $RepoRoot 'storage\backups\local-updates'
            New-Item -ItemType Directory -Force -Path $backupDir | Out-Null
            $backup = Join-Path $backupDir ($db.name + '-' + (Get-Date -Format 'yyyyMMdd-HHmmss') + '.sql')
            $backedUp = $false
            if (Test-Path $mysqldump) {
                $env:MYSQL_PWD = $db.pass
                & $mysqldump --host=$($db.host) --port=$($db.port) --user=$($db.user) --single-transaction --routines $db.name --result-file=$backup 2>$null
                Remove-Item Env:\MYSQL_PWD -ErrorAction SilentlyContinue
                $backedUp = ($LASTEXITCODE -eq 0) -and (Test-Path $backup) -and ((Get-Item $backup).Length -gt 0)
            }
            if (-not $backedUp) {
                Say 'New database patches found, but the database backup failed, so they were NOT applied:'
                $patches | ForEach-Object { Say "   $_" }
            } else {
                Say "Database backed up to $backup"
                $files = $patches | ForEach-Object { Join-Path $RepoRoot $_ }
                $out = & $php (Join-Path $RepoRoot 'tools\local-db.php') --apply @files 2>&1
                $out | ForEach-Object { Say "   $_" }
                if ($LASTEXITCODE -ne 0) { Say 'A patch failed. Your backup is above; nothing else was changed.' }
            }
        }
    }
}

# Student portal clone, if present
if (-not $PortalPath) {
    $PortalPath = Join-Path (Split-Path $RepoRoot) 'sms2-student-portal-v2'
}
if (Test-Path (Join-Path $PortalPath '.git')) {
    Update-Repo $PortalPath 'Student portal' | Out-Null
}

Say "Done. Log: $LogFile"
if (-not $Quiet) { Read-Host 'Press Enter to close' | Out-Null }

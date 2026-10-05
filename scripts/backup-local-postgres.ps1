# Daily local PostgreSQL backup with an automatic restore test and retention.
# Usage: powershell -ExecutionPolicy Bypass -File scripts\backup-local-postgres.ps1 [-RetentionDays 14]
param(
    [string]$Container = "finance_erp_postgres",
    [string]$Database = "finance_erp",
    [string]$DbUser = "finance_user",
    [int]$RetentionDays = 14
)

$ErrorActionPreference = "Stop"
$root = Split-Path -Parent $PSScriptRoot
$dir = Join-Path $root "apps\api\storage\app\backups"
New-Item -ItemType Directory -Force -Path $dir | Out-Null

$stamp = Get-Date -Format "yyyyMMdd-HHmmss"
$file = Join-Path $dir "finova-postgres-auto-$stamp.dump"
$tmpDb = "restore_check_$stamp".Replace("-", "_")
$remote = "/tmp/finova-$stamp.dump"

function Invoke-Docker { & docker @args; if ($LASTEXITCODE -ne 0) { throw "docker $($args -join ' ') failed" } }

try {
    Invoke-Docker exec $Container pg_dump -U $DbUser -d $Database -Fc -f $remote
    Invoke-Docker cp "${Container}:$remote" $file

    # Prove the dump restores: load it into a throwaway database and count tables.
    Invoke-Docker exec $Container createdb -U $DbUser $tmpDb
    Invoke-Docker exec $Container pg_restore -U $DbUser -d $tmpDb --no-owner $remote
    $tables = (& docker exec $Container psql -U $DbUser -d $tmpDb -t -A -c "select count(*) from information_schema.tables where table_schema='public'").Trim()
    if ([int]$tables -lt 1) { throw "Restore test produced no tables." }

    $size = (Get-Item $file).Length
    Write-Output "BACKUP_OK file=$file bytes=$size restored_tables=$tables"
}
finally {
    & docker exec $Container dropdb -U $DbUser --if-exists $tmpDb 2>$null
    & docker exec $Container rm -f $remote 2>$null
}

Get-ChildItem $dir -Filter "finova-postgres-auto-*.dump" |
    Where-Object { $_.LastWriteTime -lt (Get-Date).AddDays(-$RetentionDays) } |
    Remove-Item -Force

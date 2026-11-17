<#
.SYNOPSIS
    Runs B2B Sync locally on Windows: web server, queue worker and scheduler.

.DESCRIPTION
    The worker and the scheduler open in their own windows; the web server runs in this one.
    Stop everything by closing the three windows (or Ctrl+C in each).

    Without the worker, orders and stock updates wait in the queue. Without the scheduler,
    failed syncs are never retried and the dashboard shows "no heartbeat yet".

    The worker keeps the code in memory: after changing code, close its window and start again.

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File scripts\local.ps1
    Your normal local installation (.env as it is).

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File scripts\local.ps1 -Database b2b_fromzero -RedisDb 5
    A second, separate installation: its own database and its own Redis queues and cache,
    e.g. for testing a new customer from zero. Run `php artisan migrate` with the same
    settings the first time (see docs/operations.md).
#>
param(
    [string] $Database = '',
    [int] $RedisDb = -1,
    [int] $Port = 8000
)

$ErrorActionPreference = 'Stop'
Set-Location (Split-Path $PSScriptRoot -Parent)

# Set here, inherited by the two windows started below and by the server in this one.
if ($Database -ne '') { $env:DB_DATABASE = $Database }
if ($RedisDb -ge 0) {
    $env:REDIS_DB = "$RedisDb"
    $env:REDIS_CACHE_DB = "$($RedisDb + 1)"
}

# Redis and PostgreSQL must be up first; otherwise every window fails with a stack trace.
function Test-Port([int] $port) {
    $client = New-Object System.Net.Sockets.TcpClient
    try { return $client.ConnectAsync('127.0.0.1', $port).Wait(1000) } catch { return $false } finally { $client.Dispose() }
}
$missing = @()
if (-not (Test-Port 6379)) { $missing += 'Redis (port 6379)' }
if (-not (Test-Port 5432)) { $missing += 'PostgreSQL (port 5432)' }
if ($missing.Count -gt 0) {
    Write-Host ''
    Write-Host "  Not running: $($missing -join ', ')." -ForegroundColor Red
    Write-Host '  Open Laragon and click Start All, then run this script again.'
    Write-Host ''
    exit 1
}

$label = if ($Database -ne '') { "B2B Sync ($Database)" } else { 'B2B Sync' }
$windows = [ordered]@{
    'worker'    = 'php artisan queue:work redis --queue=orders,inventory,default'
    'scheduler' = 'php artisan schedule:work'
}

foreach ($name in $windows.Keys) {
    $command = "`$host.UI.RawUI.WindowTitle = '$label - $name'; $($windows[$name])"
    Start-Process powershell -WorkingDirectory (Get-Location) -ArgumentList '-NoExit', '-Command', $command
}

$host.UI.RawUI.WindowTitle = "$label - server"
Write-Host ''
Write-Host "  $label" -ForegroundColor Green
Write-Host "  Worker and scheduler started in their own windows."
Write-Host "  Open http://127.0.0.1:$Port/setup"
Write-Host ''

php artisan serve --port=$Port

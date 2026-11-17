param(
    [string]$PhpPath = '',
    [string]$MysqlBase = '',
    [string]$MysqlData = '',
    [switch]$CheckOnly
)
$ErrorActionPreference = 'Stop'
$project = (Resolve-Path (Join-Path $PSScriptRoot '../..')).Path
$toolRoot = Join-Path (Split-Path $project -Parent) '.tools/mediqueue'
if (-not $PhpPath) { $PhpPath = Join-Path $toolRoot 'php/php.exe' }
if (-not $MysqlBase) { $MysqlBase = Join-Path $toolRoot 'mysql' }
if (-not $MysqlData) { $MysqlData = Join-Path $toolRoot 'mysql-p2-data' }
$PhpPath = (Resolve-Path -LiteralPath $PhpPath).Path
$MysqlBase = (Resolve-Path -LiteralPath $MysqlBase).Path
$MysqlData = (Resolve-Path -LiteralPath $MysqlData).Path
$env:Path = (Split-Path $PhpPath -Parent) + ';' + $env:Path
$config = @{}
Get-Content -LiteralPath (Join-Path $project '.env') | ForEach-Object {
    if ($_ -match '^(APP_ENV|DB_HOST|DB_PORT|DB_DATABASE)=(.*)$') { $config[$matches[1]] = $matches[2].Trim('"', "'") }
}
if ($config.APP_ENV -notin @('local', 'demo') -or $config.DB_HOST -ne '127.0.0.1' -or $config.DB_PORT -ne '3308' -or $config.DB_DATABASE -ne 'mediqueue') {
    throw 'This native Windows launcher requires local/demo, 127.0.0.1:3308 and mediqueue. It never changes .env or initializes data.'
}
$logs = Join-Path $project '.local/demo'
if (-not $CheckOnly) { New-Item -ItemType Directory -Path $logs -Force | Out-Null }
$dbListener = Get-NetTCPConnection -LocalPort 3308 -State Listen -ErrorAction SilentlyContinue
if (-not $dbListener) {
    if ($CheckOnly) { throw 'Native MySQL is not listening on port 3308.' }
    if (-not (Test-Path -LiteralPath (Join-Path $MysqlData 'auto.cnf'))) { throw 'Existing MySQL data directory required; no automatic initialization is allowed.' }
    $mysqlArgs = @('--no-defaults', ('"--basedir={0}"' -f $MysqlBase), ('"--datadir={0}"' -f $MysqlData), '--port=3308', '--bind-address=127.0.0.1', '--mysqlx=0', ('"--log-error={0}"' -f (Join-Path $logs 'mysql-error.log')))
    Start-Process -FilePath (Join-Path $MysqlBase 'bin/mysqld.exe') -ArgumentList $mysqlArgs -WindowStyle Hidden -WorkingDirectory $project | Out-Null
    for ($attempt = 0; $attempt -lt 30; $attempt++) {
        Start-Sleep -Milliseconds 500
        if (Get-NetTCPConnection -LocalPort 3308 -State Listen -ErrorAction SilentlyContinue) { break }
    }
}
& $PhpPath (Join-Path $project 'scripts/check-demo.php')
if ($LASTEXITCODE -ne 0) { throw 'Application database health failed. Do not reset data to resolve this.' }
$artisan = Join-Path $project 'artisan'
$webListener = Get-NetTCPConnection -LocalPort 8000 -State Listen -ErrorAction SilentlyContinue
if (-not $webListener) {
    if ($CheckOnly) { throw 'Laravel is not listening on port 8000.' }
    Start-Process -FilePath $PhpPath -ArgumentList @(('"{0}"' -f $artisan), 'serve', '--host=127.0.0.1', '--port=8000', '--no-reload') -WorkingDirectory $project -WindowStyle Hidden -RedirectStandardOutput (Join-Path $logs 'web.out.log') -RedirectStandardError (Join-Path $logs 'web.err.log') | Out-Null
    for ($attempt = 0; $attempt -lt 20; $attempt++) {
        Start-Sleep -Milliseconds 500
        if (Get-NetTCPConnection -LocalPort 8000 -State Listen -ErrorAction SilentlyContinue) { break }
    }
}
$webListener = Get-NetTCPConnection -LocalPort 8000 -State Listen -ErrorAction Stop
foreach ($owner in @($webListener.OwningProcess | Sort-Object -Unique)) {
    $webProcess = Get-CimInstance Win32_Process -Filter "ProcessId = $owner"
    if ($webProcess.Name -ne 'php.exe' -or $webProcess.CommandLine -notmatch [regex]::Escape($project)) {
        throw 'Port 8000 is occupied by a process outside this project; no process was stopped.'
    }
}
$health = Invoke-RestMethod 'http://127.0.0.1:8000/health' -TimeoutSec 10
if ($health.status -ne 'ok' -or $health.database -ne 'ok') { throw 'HTTP database health failed.' }
$scheduler = Get-CimInstance Win32_Process -Filter "Name = 'php.exe'" | Where-Object { $_.CommandLine -like "*$artisan*schedule:work*" }
if (-not $scheduler) {
    if ($CheckOnly) { throw 'No scheduler with this project absolute artisan path found.' }
    $scheduler = Start-Process -FilePath $PhpPath -ArgumentList @(('"{0}"' -f $artisan), 'schedule:work', '--no-interaction') -WorkingDirectory $project -WindowStyle Hidden -RedirectStandardOutput (Join-Path $logs 'scheduler.out.log') -RedirectStandardError (Join-Path $logs 'scheduler.err.log') -PassThru
    Start-Sleep -Milliseconds 500
    if ($scheduler.HasExited) { throw 'Scheduler exited; inspect private .local/demo logs.' }
}
Write-Output 'Ready: http://127.0.0.1:8000 ; MySQL 3308, HTTP health and project scheduler verified.'
Write-Output 'This is a local demo launcher, not an automatic Windows service. Keep the processes running during the demonstration.'

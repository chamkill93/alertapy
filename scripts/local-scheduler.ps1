param(
    [ValidateRange(60, 86400)]
    [int]$IntervalSeconds = 60,
    [switch]$Once,
    [switch]$Stop
)

$ErrorActionPreference = 'Stop'
$OutputEncoding = [System.Text.UTF8Encoding]::new($false)
[Console]::OutputEncoding = $OutputEncoding
$projectPath = Split-Path -Parent $PSScriptRoot
$runtimePath = Join-Path $projectPath 'work'
$stopPath = Join-Path $runtimePath 'local-scheduler.stop'
$statusPath = Join-Path $runtimePath 'local-scheduler.json'
$logPath = Join-Path $runtimePath 'local-scheduler.log'
New-Item -ItemType Directory -Path $runtimePath -Force | Out-Null

if ($Stop) {
    Set-Content -LiteralPath $stopPath -Value 'stop' -Encoding UTF8
    Write-Output 'Se detendrá el scheduler local al terminar el ciclo actual.'
    exit 0
}

# El handle exclusivo evita dos loops, incluso si se abre el script dos veces.
try {
    $lockHandle = [System.IO.File]::Open((Join-Path $runtimePath 'local-scheduler.lock'), 'OpenOrCreate', 'ReadWrite', 'None')
} catch [System.IO.IOException] {
    Write-Output 'El scheduler local ya está en ejecución.'
    exit 0
}

try {
    if (Test-Path -LiteralPath $stopPath) { Remove-Item -LiteralPath $stopPath }
    $phpExecutable = (Get-Command php -ErrorAction Stop).Source
    $iniPath = Join-Path $projectPath 'storage/framework/php-alertapy.ini'
    $databasePath = Join-Path $projectPath 'storage/framework/alertapy-local.sqlite'
    if (!(Test-Path -LiteralPath $databasePath)) { throw 'No existe la base local de AlertaPY.' }
    if (!(Test-Path -LiteralPath $iniPath)) { throw 'No existe la configuración PHP local.' }

    $env:PHPRC = $iniPath
    $env:APP_ENV = 'local'
    $env:DB_CONNECTION = 'sqlite'
    $env:DB_DATABASE = $databasePath
    $env:DB_URL = ''
    Set-Location -LiteralPath $projectPath

    do {
        $startedAt = [DateTimeOffset]::Now
        $runtimeStatus = [ordered]@{ pid=$PID; state='running'; started_at=$startedAt.ToString('o'); interval_seconds=$IntervalSeconds }
        $runtimeStatus | ConvertTo-Json | Set-Content -LiteralPath $statusPath -Encoding UTF8
        Add-Content -LiteralPath $logPath -Value ('[' + $startedAt.ToString('o') + '] Inicio del ciclo') -Encoding UTF8

        # Un fallo de fuente se registra; el próximo ciclo vuelve a intentarlo.
        try {
            $ErrorActionPreference = 'Continue'
            & $phpExecutable artisan alertapy:cycle --no-ansi 2>&1 | ForEach-Object {
                Add-Content -LiteralPath $logPath -Value ([string]$_) -Encoding UTF8
            }
            $cycleExitCode = $LASTEXITCODE
        } finally {
            $ErrorActionPreference = 'Stop'
        }
        $runtimeStatus['exit_code'] = $cycleExitCode
        $runtimeStatus['finished_at'] = [DateTimeOffset]::Now.ToString('o')
        $runtimeStatus['state'] = if ($Once) { 'stopped' } else { 'waiting' }
        $runtimeStatus | ConvertTo-Json | Set-Content -LiteralPath $statusPath -Encoding UTF8

        if ($Once) { break }
        $nextRun = $startedAt.AddSeconds($IntervalSeconds)
        while ([DateTimeOffset]::Now -lt $nextRun -and !(Test-Path -LiteralPath $stopPath)) {
            Start-Sleep -Seconds 5
        }
    } while (!(Test-Path -LiteralPath $stopPath))
} finally {
    if ($runtimeStatus) {
        $runtimeStatus['state'] = 'stopped'
        $runtimeStatus | ConvertTo-Json | Set-Content -LiteralPath $statusPath -Encoding UTF8
    }
    $lockHandle.Dispose()
}

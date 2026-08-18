$ErrorActionPreference = 'Stop'

$runId = [Guid]::NewGuid().ToString('N')
$containerName = "pa-portal-scope-mysql-test-$runId"
$networkName = "pa-portal-scope-mysql-test-$runId"
$databaseName = "portal_scope_test_$runId"
$databaseUser = 'portal_scope'
$rootPassword = [Guid]::NewGuid().ToString('N')
$databasePassword = [Guid]::NewGuid().ToString('N')
$containerStarted = $false
$networkCreated = $false
$repositoryRoot = Split-Path -Parent $PSScriptRoot
$testImage = ([string]$env:PORTAL_SCOPE_TEST_IMAGE).Trim()
$phpunit = $null
if (-not $testImage) {
    $phpunit = Join-Path $repositoryRoot 'vendor/bin/phpunit'
    if (-not (Test-Path $phpunit) -and $env:PA_TEST_VENDOR_AUTOLOAD) {
        $phpunit = Join-Path (Split-Path -Parent $env:PA_TEST_VENDOR_AUTOLOAD) 'bin/phpunit'
    }
    if (-not (Test-Path $phpunit)) {
        throw 'PHPUnit is unavailable. Run composer install, set PA_TEST_VENDOR_AUTOLOAD, or set PORTAL_SCOPE_TEST_IMAGE to the already-built test image.'
    }
}

try {
    $existing = docker ps -a --filter "name=^/$containerName$" --format '{{.Names}}'
    if ($existing) { throw "Refusing to reuse existing container $containerName." }

    if ($testImage) {
        docker image inspect $testImage *> $null
        if ($LASTEXITCODE -ne 0) { throw "PORTAL_SCOPE_TEST_IMAGE is unavailable locally: $testImage" }
        docker network create $networkName *> $null
        if ($LASTEXITCODE -ne 0) { throw "Failed to create disposable Docker network $networkName." }
        $networkCreated = $true
    }

    $mysqlArgs = @('run', '--rm', '--detach', '--name', $containerName)
    if ($testImage) {
        $mysqlArgs += @('--network', $networkName)
    } else {
        $mysqlArgs += @('--publish', '127.0.0.1::3306')
    }
    $mysqlArgs += @(
        '--env', "MYSQL_ROOT_PASSWORD=$rootPassword",
        '--env', "MYSQL_DATABASE=$databaseName",
        '--env', "MYSQL_USER=$databaseUser",
        '--env', "MYSQL_PASSWORD=$databasePassword",
        'mysql:8.4'
    )
    $containerId = docker @mysqlArgs
    if ($LASTEXITCODE -ne 0 -or -not $containerId) { throw 'Failed to start the disposable MySQL container.' }
    $containerStarted = $true

    $databaseHost = '127.0.0.1'
    $databasePort = '3306'
    if (-not $testImage) {
        $portOutput = docker port $containerName '3306/tcp'
        if ($LASTEXITCODE -ne 0 -or $portOutput -notmatch '127\.0\.0\.1:(\d+)$') {
            throw "Could not determine the disposable MySQL port: $portOutput"
        }
        $databasePort = $Matches[1]
    } else {
        $databaseHost = $containerName
    }

    $ready = $false
    for ($attempt = 0; $attempt -lt 45; $attempt++) {
        docker exec --env "MYSQL_PWD=$rootPassword" $containerName mysqladmin ping `
            --host=127.0.0.1 --user=root --silent 2>$null | Out-Null
        if ($LASTEXITCODE -eq 0) { $ready = $true; break }
        Start-Sleep -Seconds 1
    }
    if (-not $ready) { throw 'Disposable MySQL did not become ready within 45 seconds.' }

    $dsn = "mysql:host=$databaseHost;port=$databasePort;dbname=$databaseName;charset=utf8mb4"
    Write-Host "Running portal scope lock regressions against isolated disposable MySQL 8.4 database $databaseName."
    if ($testImage) {
        docker run --rm `
            --network $networkName `
            --env "PORTAL_SCOPE_MYSQL_DSN=$dsn" `
            --env "PORTAL_SCOPE_MYSQL_USER=$databaseUser" `
            --env "PORTAL_SCOPE_MYSQL_PASSWORD=$databasePassword" `
            --env "PORTAL_SCOPE_MYSQL_DATABASE=$databaseName" `
            --env 'PORTAL_SCOPE_MYSQL_ALLOW_DESTRUCTIVE=isolated-disposable-only' `
            $testImage php vendor/bin/phpunit tests/Integration/PortalProjectionScopeLockMySqlTest.php `
                --do-not-cache-result --colors=never --fail-on-skipped
    } else {
        $env:PORTAL_SCOPE_MYSQL_DSN = $dsn
        $env:PORTAL_SCOPE_MYSQL_USER = $databaseUser
        $env:PORTAL_SCOPE_MYSQL_PASSWORD = $databasePassword
        $env:PORTAL_SCOPE_MYSQL_DATABASE = $databaseName
        $env:PORTAL_SCOPE_MYSQL_ALLOW_DESTRUCTIVE = 'isolated-disposable-only'
        & php $phpunit (Join-Path $repositoryRoot 'tests/Integration/PortalProjectionScopeLockMySqlTest.php') `
            --do-not-cache-result --colors=never --fail-on-skipped
    }
    if ($LASTEXITCODE -ne 0) { throw "Portal scope MySQL integration tests failed with exit code $LASTEXITCODE." }
}
finally {
    Remove-Item Env:PORTAL_SCOPE_MYSQL_DSN -ErrorAction SilentlyContinue
    Remove-Item Env:PORTAL_SCOPE_MYSQL_USER -ErrorAction SilentlyContinue
    Remove-Item Env:PORTAL_SCOPE_MYSQL_PASSWORD -ErrorAction SilentlyContinue
    Remove-Item Env:PORTAL_SCOPE_MYSQL_DATABASE -ErrorAction SilentlyContinue
    Remove-Item Env:PORTAL_SCOPE_MYSQL_ALLOW_DESTRUCTIVE -ErrorAction SilentlyContinue
    if ($containerStarted) { docker rm --force $containerName 2>&1 | Out-Null }
    if ($networkCreated) { docker network rm $networkName 2>&1 | Out-Null }
}

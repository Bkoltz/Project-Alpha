$ErrorActionPreference = 'Stop'

$containerName = "pa-sync-v2-mysql-test-$PID"
$databaseName = 'sync_v2_test'
$databaseUser = 'sync_v2'
$rootPassword = [Guid]::NewGuid().ToString('N')
$databasePassword = [Guid]::NewGuid().ToString('N')
$containerStarted = $false

try {
    $existing = docker ps -a --filter "name=^/$containerName$" --format '{{.Names}}'
    if ($existing) {
        throw "Refusing to reuse existing container $containerName."
    }

    $containerId = docker run --rm --detach `
        --name $containerName `
        --publish '127.0.0.1::3306' `
        --env "MYSQL_ROOT_PASSWORD=$rootPassword" `
        --env "MYSQL_DATABASE=$databaseName" `
        --env "MYSQL_USER=$databaseUser" `
        --env "MYSQL_PASSWORD=$databasePassword" `
        mysql:8.4
    if ($LASTEXITCODE -ne 0 -or -not $containerId) {
        throw 'Failed to start the disposable MySQL container.'
    }
    $containerStarted = $true

    $portOutput = docker port $containerName '3306/tcp'
    if ($LASTEXITCODE -ne 0 -or $portOutput -notmatch '127\.0\.0\.1:(\d+)$') {
        throw "Could not determine the disposable MySQL port: $portOutput"
    }
    $databasePort = $Matches[1]

    $ready = $false
    for ($attempt = 0; $attempt -lt 45; $attempt++) {
        docker exec --env "MYSQL_PWD=$rootPassword" $containerName mysqladmin ping `
            --host=127.0.0.1 `
            --user=root `
            --silent 2>$null | Out-Null
        if ($LASTEXITCODE -eq 0) {
            $ready = $true
            break
        }
        Start-Sleep -Seconds 1
    }
    if (-not $ready) {
        throw 'Disposable MySQL did not become ready within 45 seconds.'
    }

    $env:SYNC_V2_MYSQL_DSN = "mysql:host=127.0.0.1;port=$databasePort;dbname=$databaseName;charset=utf8mb4"
    $env:SYNC_V2_MYSQL_USER = $databaseUser
    $env:SYNC_V2_MYSQL_PASSWORD = $databasePassword

    Write-Host "Running Sync Contract v2 against disposable MySQL 8.4 on loopback port $databasePort."
    & php vendor/bin/phpunit tests/Integration/SyncContractV2MySqlTest.php --colors=never
    if ($LASTEXITCODE -ne 0) {
        throw "Sync Contract v2 MySQL integration test failed with exit code $LASTEXITCODE."
    }
}
finally {
    Remove-Item Env:SYNC_V2_MYSQL_DSN -ErrorAction SilentlyContinue
    Remove-Item Env:SYNC_V2_MYSQL_USER -ErrorAction SilentlyContinue
    Remove-Item Env:SYNC_V2_MYSQL_PASSWORD -ErrorAction SilentlyContinue
    if ($containerStarted) {
        docker rm --force $containerName 2>&1 | Out-Null
    }
}

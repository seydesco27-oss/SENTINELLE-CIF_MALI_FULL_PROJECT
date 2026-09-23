[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
$ProjectRoot = $PSScriptRoot
$RuntimeDirectory = Join-Path $ProjectRoot '.runtime'
$MlDirectory = Join-Path $ProjectRoot 'ML_projet_cif-master'
$ApiDirectory = Join-Path $ProjectRoot 'SENTINELLE-CIF-API'
$FrontendDirectory = Join-Path $ProjectRoot 'FRONTEND'
$MlPython = Join-Path $MlDirectory '.venv\Scripts\python.exe'

New-Item -ItemType Directory -Force -Path $RuntimeDirectory | Out-Null

function Test-Endpoint {
    param(
        [Parameter(Mandatory = $true)] [string] $Uri,
        [int] $TimeoutSeconds = 2
    )

    try {
        $response = Invoke-WebRequest -UseBasicParsing -Uri $Uri -TimeoutSec $TimeoutSeconds
        return $response.StatusCode -ge 200 -and $response.StatusCode -lt 500
    } catch {
        return $false
    }
}

function Wait-Endpoint {
    param(
        [Parameter(Mandatory = $true)] [string] $Name,
        [Parameter(Mandatory = $true)] [string] $Uri,
        [int] $TimeoutSeconds = 90
    )

    $deadline = (Get-Date).AddSeconds($TimeoutSeconds)
    while ((Get-Date) -lt $deadline) {
        if (Test-Endpoint -Uri $Uri -TimeoutSeconds 3) {
            Write-Host "[OK] $Name" -ForegroundColor Green
            return
        }
        Start-Sleep -Seconds 1
    }

    throw "$Name n'a pas repondu sur $Uri apres $TimeoutSeconds secondes. Consultez les journaux dans $RuntimeDirectory."
}

function Start-ManagedProcess {
    param(
        [Parameter(Mandatory = $true)] [string] $Name,
        [Parameter(Mandatory = $true)] [string] $FilePath,
        [Parameter(Mandatory = $true)] [string[]] $ArgumentList,
        [Parameter(Mandatory = $true)] [string] $WorkingDirectory
    )

    $stdout = Join-Path $RuntimeDirectory "$Name.stdout.log"
    $stderr = Join-Path $RuntimeDirectory "$Name.stderr.log"
    $process = Start-Process `
        -FilePath $FilePath `
        -ArgumentList $ArgumentList `
        -WorkingDirectory $WorkingDirectory `
        -WindowStyle Hidden `
        -RedirectStandardOutput $stdout `
        -RedirectStandardError $stderr `
        -PassThru

    Set-Content -Path (Join-Path $RuntimeDirectory "$Name.pid") -Value $process.Id
    Write-Host "[DEMARRAGE] $Name (PID $($process.Id))"
}

if (-not (Test-Path -LiteralPath $MlPython)) {
    throw "Environnement Python introuvable : $MlPython"
}

if (-not (Test-Endpoint -Uri 'http://127.0.0.1:8100/health')) {
    & $MlPython -c 'import fastapi, sklearn, pandas' 2>$null
    if ($LASTEXITCODE -ne 0) {
        throw "Dependances ML absentes. Executez : .\.venv\Scripts\python.exe -m pip install -r dossier\requirements.txt"
    }
    Start-ManagedProcess -Name 'ml' -FilePath $MlPython -ArgumentList @('dossier\ml_service.py') -WorkingDirectory $MlDirectory
}

$phpCommand = Get-Command php -ErrorAction Stop
if (-not (Test-Endpoint -Uri 'http://127.0.0.1:8000/api/health')) {
    Start-ManagedProcess -Name 'api' -FilePath $phpCommand.Source -ArgumentList @('-d', 'upload_max_filesize=50M', '-d', 'post_max_size=52M', '-d', 'memory_limit=512M', '-d', 'max_execution_time=120', '-S', '127.0.0.1:8000', '..\vendor\laravel\framework\src\Illuminate\Foundation\resources\server.php') -WorkingDirectory (Join-Path $ApiDirectory 'public')
}

$npmCommand = Get-Command npm.cmd -ErrorAction Stop
if (-not (Test-Endpoint -Uri 'http://127.0.0.1:5173')) {
    Start-ManagedProcess -Name 'frontend' -FilePath $npmCommand.Source -ArgumentList @('run', 'dev', '--', '--host', '127.0.0.1', '--port', '5173') -WorkingDirectory $FrontendDirectory
}

Wait-Endpoint -Name 'Modele ML' -Uri 'http://127.0.0.1:8100/health'
Wait-Endpoint -Name 'API Laravel' -Uri 'http://127.0.0.1:8000/api/health'
Wait-Endpoint -Name 'Frontend React' -Uri 'http://127.0.0.1:5173'

Write-Host ''
Write-Host 'SENTINELLE-CIF est prete : http://127.0.0.1:5173' -ForegroundColor Cyan

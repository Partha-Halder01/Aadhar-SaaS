# Starts the client demo: Laravel serves BOTH the API and the frontend on one
# origin, exposed publicly via a single Cloudflare quick tunnel.
# All processing (PHP + MySQL) stays on this laptop.
#
# Run:  powershell -ExecutionPolicy Bypass -File .\start-demo.ps1

$ErrorActionPreference = 'Stop'
$root = $PSScriptRoot
$log  = Join-Path $env:TEMP 'aadhar-demo'
New-Item -ItemType Directory -Force -Path $log | Out-Null

# Sync the frontend into Laravel's public dir so one server serves everything.
Write-Host "Syncing frontend into backend/public ..."
$src = Join-Path $root 'frontend'
$dst = Join-Path $root 'backend\public'
foreach ($item in @('css','js','admin','user')) {
    $p = Join-Path $src $item
    if (Test-Path $p) { Copy-Item $p -Destination $dst -Recurse -Force }
}
Get-ChildItem $src -File | Where-Object { $_.Extension -in '.html','.png','.webp','.jpg','.svg','.ico' } |
    ForEach-Object { Copy-Item $_.FullName -Destination $dst -Force }

# Same-origin API config.
@'
window.APP_CONFIG = {
  apiOrigin: window.location.origin
};
'@ | Set-Content (Join-Path $dst 'js\config.js') -Encoding utf8

function Start-Bg($name, $exe, $argList, $workDir) {
    $out = Join-Path $log "$name.out"
    if (Test-Path $out) { Remove-Item $out -Force }
    Start-Process -FilePath $exe -ArgumentList $argList -WorkingDirectory $workDir `
        -RedirectStandardOutput $out -RedirectStandardError (Join-Path $log "$name.err") `
        -WindowStyle Hidden
    return $out
}

Write-Host "Starting Laravel on 127.0.0.1:8000 ..."
Start-Bg 'laravel' 'php' @('artisan','serve','--host=127.0.0.1','--port=8000') (Join-Path $root 'backend') | Out-Null
Start-Sleep -Seconds 3

Write-Host "Opening Cloudflare tunnel ..."
$out = Start-Bg 'tunnel' 'cloudflared' @('tunnel','--url','http://127.0.0.1:8000','--no-autoupdate','--protocol','http2','--edge-ip-version','4') $root

$url = $null
for ($i = 0; $i -lt 45 -and -not $url; $i++) {
    foreach ($f in @($out, (Join-Path $log 'tunnel.err'))) {
        if (Test-Path $f) {
            $m = Select-String -Path $f -Pattern 'https://[a-z0-9-]+\.trycloudflare\.com' -AllMatches
            if ($m) { $url = $m.Matches[0].Value; break }
        }
    }
    if (-not $url) { Start-Sleep -Seconds 2 }
}
if (-not $url) { throw "Timed out waiting for the tunnel URL." }

Write-Host ""
Write-Host "==================== DEMO IS LIVE ====================" -ForegroundColor Green
Write-Host "  Send the client:  $url" -ForegroundColor Green
Write-Host "======================================================" -ForegroundColor Green
Write-Host "Keep this laptop on and awake - it is serving the demo."
Write-Host "A brand-new hostname can take a few minutes to resolve on some networks."

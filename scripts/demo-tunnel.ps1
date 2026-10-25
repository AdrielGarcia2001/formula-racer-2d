# demo-tunnel.ps1 — Levanta los 2 tuneles de Cloudflare y enlaza Reverb con Laravel.
# Uso:  powershell -ExecutionPolicy Bypass -File scripts\demo-tunnel.ps1
# Deja las 2 ventanas de cloudflared abiertas durante toda la demo.
# El tunel de la APP es el que compartis a los alumnos; el de Reverb solo lo usa .env.

$root = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
$cf = "C:\cloud\cloudflared.exe"
$appLog = Join-Path $env:TEMP "cf-app.log"
$revLog = Join-Path $env:TEMP "cf-reverb.log"
Remove-Item $appLog, $revLog, "$appLog.err", "$revLog.err" -ErrorAction SilentlyContinue

Start-Process $cf -ArgumentList "tunnel", "--url", "http://127.0.0.1:8000" `
    -RedirectStandardOutput $appLog -RedirectStandardError "$appLog.err" -WindowStyle Minimized
Start-Process $cf -ArgumentList "tunnel", "--url", "http://127.0.0.1:8080" `
    -RedirectStandardOutput $revLog -RedirectStandardError "$revLog.err" -WindowStyle Minimized

function Get-TunnelUrl([string]$log) {
    foreach ($f in @($log, "$log.err")) {
        if (Test-Path $f) {
            $m = Select-String -Path $f -Pattern "https://\S+\.trycloudflare\.com" | Select-Object -First 1
            if ($m) { return $m.Matches[0].Value }
        }
    }
    return $null
}

$appUrl = $null; $revUrl = $null
for ($i = 0; $i -lt 45 -and (-not $appUrl -or -not $revUrl); $i++) {
    Start-Sleep -Seconds 1
    if (-not $appUrl) { $appUrl = Get-TunnelUrl $appLog }
    if (-not $revUrl) { $revUrl = Get-TunnelUrl $revLog }
}

if (-not $appUrl -or -not $revUrl) {
    Write-Host "ERROR: no salieron las URLs. Revisa $appLog.err / $revLog.err" -ForegroundColor Red
    exit 1
}

$revHost = ([Uri]$revUrl).Host
$envPath = Join-Path $root ".env"
$lines = Get-Content $envPath | Where-Object { $_ -notmatch '^#?BROADCAST_PUBLIC_(HOST|PORT|SCHEME)=' }
$lines += "BROADCAST_PUBLIC_HOST=`"$revHost`""
$lines += "BROADCAST_PUBLIC_PORT=443"
$lines += "BROADCAST_PUBLIC_SCHEME=https"
Set-Content -Path $envPath -Value $lines

Write-Host ""
Write-Host "Tuneles listos." -ForegroundColor Green
Write-Host "  APP (compartir esta): $appUrl"
Write-Host "  Reverb WS (solo .env): $revUrl  ->  BROADCAST_PUBLIC_HOST=$revHost"
Write-Host ""
Write-Host ".env actualizado. Laravel lee .env por request: NO hace falta reiniciar nada."
Write-Host "Deja abiertas las 2 ventanas de cloudflared. Para cortar: cerralas."
Write-Host ""
Write-Host "Abrí desde otro equipo/celular: $appUrl" -ForegroundColor Cyan

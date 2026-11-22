<#
.SYNOPSIS
    End-to-end validation suite for the DJGear local Docker environment.
#>
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$OutputEncoding = [System.Text.Encoding]::ASCII

Write-Host "`n[1/8] Checking Docker Compose container states..." -ForegroundColor Cyan
& docker compose ps

Write-Host "`n[2/8] Checking WordPress HTTP response (http://localhost:8080)..." -ForegroundColor Cyan
$wpResp = Invoke-WebRequest -Uri 'http://localhost:8080/' -UseBasicParsing -TimeoutSec 15
Write-Host "WordPress HTTP Status: $($wpResp.StatusCode) ($($wpResp.StatusDescription))"

Write-Host "`n[3/8] Checking Mailpit HTTP response (http://localhost:8025)..." -ForegroundColor Cyan
$mpResp = Invoke-WebRequest -Uri 'http://localhost:8025/api/v1/info' -UseBasicParsing -TimeoutSec 15
Write-Host "Mailpit API Status: $($mpResp.StatusCode) | $($mpResp.Content)"

Write-Host "`n[4/8] Checking PHP version and WooCommerce-required extensions..." -ForegroundColor Cyan
$phpCheck = @'
<?php
$required = ['bcmath','curl','dom','exif','fileinfo','gd','imagick','intl','mbstring','mysqli','openssl','pdo_mysql','redis','soap','sodium','xml','zip','Zend OPcache'];
echo 'PHP Version: ' . PHP_VERSION . PHP_EOL;
$missing = [];
foreach ($required as $ext) {
    $ok = extension_loaded($ext);
    echo sprintf("  %-15s %s\n", $ext, $ok ? 'OK' : 'MISSING');
    if (!$ok) { $missing[] = $ext; }
}
if ($missing) { exit(1); }
'@
$phpCheck | & docker compose exec -T wordpress php
if ($LASTEXITCODE -ne 0) { throw "Missing required PHP extensions." }

Write-Host "`n[5/8] Checking MySQL 8.4 version, connection, and restricted WordPress user grants..." -ForegroundColor Cyan
& docker compose exec -T -u www-data wordpress wp db check --skip-ssl
if ($LASTEXITCODE -ne 0) { throw "WP-CLI db check failed." }
& docker compose exec -T -u www-data wordpress wp db query --skip-ssl "SELECT VERSION() AS mysql_version, CURRENT_USER() AS active_user;"
if ($LASTEXITCODE -ne 0) { throw "MySQL version check failed." }
& docker compose exec -T -u www-data wordpress wp db query --skip-ssl "SHOW GRANTS FOR CURRENT_USER();"
if ($LASTEXITCODE -ne 0) { throw "MySQL restricted user grants check failed." }

Write-Host "`n[6/8] Checking Redis connectivity and WordPress Object Cache..." -ForegroundColor Cyan
& docker compose exec -T redis sh -c 'REDISCLI_AUTH=$REDIS_PASSWORD redis-cli ping'
if ($LASTEXITCODE -ne 0) { throw "Redis ping failed." }
& docker compose exec -T -u www-data wordpress wp redis status
if ($LASTEXITCODE -ne 0) { throw "WordPress Redis Object Cache check failed." }

Write-Host "`n[7/8] Checking WordPress core & WooCommerce active status..." -ForegroundColor Cyan
& docker compose exec -T -u www-data wordpress wp core version
& docker compose exec -T -u www-data wordpress wp plugin list --status=active

Write-Host "`n[8/8] Sending test email via wp_mail() and verifying delivery in Mailpit..." -ForegroundColor Cyan
$marker = "DJGear-Env-Test-$(Get-Date -Format 'yyyyMMddHHmmss')"
& docker compose exec -T -u www-data wordpress wp eval "var_export(wp_mail('qa@djgear.local', '$marker', 'Automated local Mailpit verification from DJGear.')); echo PHP_EOL;"
if ($LASTEXITCODE -ne 0) { throw "wp_mail test execution failed." }
Start-Sleep -Seconds 2
$messages = (Invoke-WebRequest -Uri 'http://localhost:8025/api/v1/messages' -UseBasicParsing).Content | ConvertFrom-Json
$matched = $messages.messages | Where-Object { $_.Subject -eq $marker }
if (-not $matched) {
    throw "Test email with subject '$marker' was NOT received in Mailpit!"
}
Write-Host "Mailpit received test email: Subject='$($matched.Subject)' ID='$($matched.ID)'" -ForegroundColor Green

Write-Host "`nALL ENVIRONMENT VALIDATION CHECKS PASSED." -ForegroundColor Green

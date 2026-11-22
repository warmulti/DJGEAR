<#
.SYNOPSIS
    Reproducible local WordPress + WooCommerce + Redis Object Cache bootstrap for DJGear.ir.
#>
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$repoRoot = Split-Path -Parent $PSScriptRoot
$envFile = Join-Path $repoRoot '.env'

if (-not (Test-Path $envFile)) {
    throw "Missing .env file at $envFile. Copy .env.example to .env and configure local credentials first."
}

# Parse .env key-value pairs
$envMap = @{}
Get-Content $envFile | ForEach-Object {
    $line = $_.Trim()
    if ($line -and -not $line.StartsWith('#') -and $line.Contains('=')) {
        $idx = $line.IndexOf('=')
        $key = $line.Substring(0, $idx).Trim()
        $val = $line.Substring($idx + 1).Trim().Trim('"').Trim("'")
        $envMap[$key] = $val
    }
}

$siteUrl       = $envMap['WP_SITE_URL']
$siteTitle     = $envMap['WP_SITE_TITLE']
$adminUser     = $envMap['WP_ADMIN_USER']
$adminPassword = $envMap['WP_ADMIN_PASSWORD']
$adminEmail    = $envMap['WP_ADMIN_EMAIL']
$wcVersion     = $envMap['WOOCOMMERCE_VERSION']

function Invoke-WpCli {
    param([string[]]$WpArgs)
    & docker compose exec -T -u www-data wordpress wp @WpArgs
    if ($LASTEXITCODE -ne 0) {
        throw "WP-CLI command failed: wp $($WpArgs -join ' ')"
    }
}

Write-Host "==> Ensuring uploads directory permissions..." -ForegroundColor Cyan
& docker compose exec -T wordpress sh -c "mkdir -p /var/www/html/wp-content/uploads && chown -R www-data:www-data /var/www/html/wp-content/uploads"

Write-Host "==> Checking WordPress installation status..." -ForegroundColor Cyan
& docker compose exec -T -u www-data wordpress wp core is-installed
$isInstalled = ($LASTEXITCODE -eq 0)

if (-not $isInstalled) {
    Write-Host "==> Installing WordPress core..." -ForegroundColor Cyan
    Invoke-WpCli @(
        'core', 'install',
        "--url=$siteUrl",
        "--title=$siteTitle",
        "--admin_user=$adminUser",
        "--admin_password=$adminPassword",
        "--admin_email=$adminEmail",
        '--skip-email'
    )
} else {
    Write-Host "==> WordPress is already installed; skipping core install." -ForegroundColor Yellow
}

Write-Host "==> Configuring pretty permalinks & timezone (Asia/Tehran)..." -ForegroundColor Cyan
Invoke-WpCli @('rewrite', 'structure', '/%postname%/', '--hard')
Invoke-WpCli @('option', 'update', 'timezone_string', 'Asia/Tehran')

Write-Host "==> Installing Persian (fa_IR) core language pack for RTL/LTR support..." -ForegroundColor Cyan
& docker compose exec -T -u www-data wordpress wp language core install fa_IR
if ($LASTEXITCODE -ne 0) {
    Write-Warning "Could not install fa_IR language pack (check network access)."
}

Write-Host "==> Installing and activating WooCommerce ($wcVersion)..." -ForegroundColor Cyan
& docker compose exec -T -u www-data wordpress wp plugin is-installed woocommerce
if ($LASTEXITCODE -ne 0) {
    if ($wcVersion) {
        Invoke-WpCli @('plugin', 'install', 'woocommerce', "--version=$wcVersion", '--activate')
    } else {
        Invoke-WpCli @('plugin', 'install', 'woocommerce', '--activate')
    }
} else {
    Invoke-WpCli @('plugin', 'activate', 'woocommerce')
}

Write-Host "==> Installing and enabling Redis Object Cache..." -ForegroundColor Cyan
& docker compose exec -T -u www-data wordpress wp plugin is-installed redis-cache
if ($LASTEXITCODE -ne 0) {
    Invoke-WpCli @('plugin', 'install', 'redis-cache', '--activate')
} else {
    Invoke-WpCli @('plugin', 'activate', 'redis-cache')
}
Invoke-WpCli @('redis', 'enable')

Write-Host "==> Verifying active plugins & Redis status..." -ForegroundColor Cyan
Invoke-WpCli @('plugin', 'list')
Invoke-WpCli @('redis', 'status')

Write-Host "`nDJGear local WordPress + WooCommerce environment is ready at $siteUrl" -ForegroundColor Green
